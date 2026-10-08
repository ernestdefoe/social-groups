<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Flarum\Http\RequestUtil;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class FetchLinkPreviewController implements RequestHandlerInterface
{
    private const CACHE_TTL   = 3600;  // 1 hour
    private const MAX_BYTES   = 524288; // 512 KB — enough to find <head> OG tags
    private const PER_MINUTE  = 20;     // uncached fetches per member per minute
    private const USER_AGENT  = 'flarum-social-groups/1.0 (+https://github.com/ernestdefoe/social-groups)';

    public function __construct(
        private CacheRepository $cache,
        private LoggerInterface $log,
        private ClientInterface $http,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $url = trim((string) ($request->getQueryParams()['url'] ?? ''));

        $url = filter_var($url, FILTER_VALIDATE_URL);
        if (! $url || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.invalid_url')], 422);
        }

        // SSRF guard: every host the request would touch (canonical +
        // every DNS record behind it) MUST resolve to a publicly-routable
        // IP. Without this, an authenticated user can supply
        // http://169.254.169.254/latest/meta-data/iam/security-credentials/
        // and exfiltrate cloud-provider IAM credentials; or probe internal
        // services (Redis, admin panels, DB UIs) on self-hosted forums.
        //
        // Resolves once and returns the validated IP list, then we pin
        // libcurl to a specific IP via CURLOPT_RESOLVE so the connect
        // phase can NOT re-resolve. Without the pin, a sub-second-TTL
        // attacker domain can hand a public IP to dns_get_record() then
        // 169.254.169.254 to libcurl's resolver (DNS rebinding).
        $hostParts = parse_url($url);
        $host = (string) ($hostParts['host'] ?? '');
        $publicIps = $this->resolvePublicIps($host);
        if ($publicIps === null) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.url_host_not_allowed')], 422);
        }

        $cacheKey = 'sg-link-preview:' . md5($url);

        if ($cached = $this->cache->get($cacheKey)) {
            return new JsonResponse($cached);
        }

        $port = isset($hostParts['port']) && $hostParts['port']
            ? (int) $hostParts['port']
            : (($hostParts['scheme'] ?? 'https') === 'http' ? 80 : 443);

        // Web pages only: no probing other services on a public host.
        if (! in_array($port, [80, 443], true)) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.url_host_not_allowed')], 422);
        }

        // Every uncached preview is an outbound fetch the forum makes for
        // the member; cap how many one member can start.
        $rateKey = 'sg-link-preview-rate:' . $actor->id . ':' . intdiv(time(), 60);
        $this->cache->add($rateKey, 0, 120);
        if ($this->cache->increment($rateKey) > self::PER_MINUTE) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.too_many_previews')], 429);
        }

        // CURLOPT_RESOLVE format: "host:port:ip1,ip2,..." — libcurl
        // skips DNS for this tuple and dials the listed IPs in order.
        $resolveSpec = [sprintf('%s:%d:%s', strtolower($host), $port, implode(',', $publicIps))];

        try {
            $response = $this->http->request('GET', $url, [
                'stream'  => true,
                // Redirect handling is intentionally disabled. If
                // re-enabled later, every redirect target must be
                // re-validated through resolvePublicIps() — libcurl's
                // built-in redirect follower would re-resolve a NEW
                // host without our SSRF guard.
                'allow_redirects' => false,
                // A slow or silent host must not hold a PHP worker.
                'timeout'         => 5,
                'connect_timeout' => 3,
                'curl'    => [
                    CURLOPT_RESOLVE   => $resolveSpec,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ],
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                ],
            ]);

            $html = $response->getBody()->read(self::MAX_BYTES);
        } catch (RequestException $e) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.fetch_failed')], 502);
        } catch (\Throwable $e) {
            // A failure that is not the remote site's goes in the log: the
            // member only sees "unexpected", so this is the only trace of it.
            $this->log->error('[social-groups] link preview failed: '.$e->getMessage(), ['exception' => $e]);

            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.unexpected')], 500);
        }

        $preview = $this->parseOgData($html, $url);

        $this->cache->put($cacheKey, $preview, self::CACHE_TTL);

        return new JsonResponse($preview);
    }

    /**
     * Resolve a host to every IP libcurl would dial, validate each, and
     * return them as a flat list — or null if any record fails the
     * public-routability check.
     *
     * Caller pins the returned IPs via CURLOPT_RESOLVE so libcurl skips
     * its own DNS resolver; this closes the DNS-rebinding window where
     * a sub-second-TTL attacker domain answers the check phase with a
     * public IP and the connect phase with 169.254.169.254 or
     * RFC-1918.
     *
     * IPv4 is screened by PHP's FILTER_FLAG_NO_PRIV_RANGE +
     * FILTER_FLAG_NO_RES_RANGE which together catch 10/8, 172.16/12,
     * 192.168/16, 169.254/16, 127/8, 0/8, multicast and reserved blocks.
     * IPv6 is filtered explicitly because PHP's reserved-range filter is
     * narrower for v6 (it allows ::1 and fe80::/10 through).
     *
     * @return list<string>|null
     */
    private function resolvePublicIps(string $host): ?array
    {
        if ($host === '') return null;

        // Trim trailing dot + lowercase + IDN-normalise to defeat
        // case / encoding / homoglyph bypass attempts.
        $host = rtrim(strtolower(rawurldecode($host)), '.');
        if (function_exists('idn_to_ascii')) {
            $canonical = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($canonical) && $canonical !== '') $host = $canonical;
        }

        /*
         * 🚨 An IP literal is taken only in its one canonical spelling.
         * Resolvers and libcurl disagree about the others: gethostbynamel()
         * read 0177.0.0.1 as the public 177.0.0.1 while curl dialled octal
         * 127.0.0.1, so the guard approved a loopback fetch. Anything made
         * of digits, dots and hex that is not a plain dotted quad (octal,
         * hex, dword, short forms) is refused outright, and there is no
         * fallback resolver to read it differently from curl.
         */
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ips = [$bare];
        } elseif (preg_match('/^(?:\d+|0x[0-9a-f]*)(?:\.(?:\d*|0x[0-9a-f]*))*$/i', $host)) {
            if (! self::isCanonicalIpv4($host)) return null;
            $ips = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            $ips = [];
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (! empty($r['ip']))   $ips[] = $r['ip'];
                    if (! empty($r['ipv6'])) $ips[] = $r['ipv6'];
                }
            }
        }

        if (empty($ips)) return null;

        foreach ($ips as $ip) {
            if (! $this->ipIsPublic($ip)) return null;
        }
        return array_values(array_unique($ips));
    }

    /** a.b.c.d, each 0-255 in plain decimal with no leading zero. */
    private static function isCanonicalIpv4(string $host): bool
    {
        return (bool) preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/', $host);
    }

    private function ipIsPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // 100.64.0.0/10 (carrier-grade NAT) is not covered by PHP's filters.
            if ((ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }

            return (bool) filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed === false) return false;

            $hex = bin2hex($packed);
            if (str_starts_with($hex, '00000000000000000000000000000000')) return false; // ::
            if (str_starts_with($hex, '00000000000000000000000000000001')) return false; // ::1
            if (str_starts_with($hex, '00000000000000000000ffff')) {
                // IPv4-mapped (::ffff:a.b.c.d) — recurse on the v4.
                $v4 = long2ip(hexdec(substr($hex, 24, 8)));
                return $this->ipIsPublic($v4);
            }
            $firstByte = hexdec(substr($hex, 0, 2));
            if (($firstByte & 0xfe) === 0xfc) return false;        // fc00::/7 ULA
            if (($firstByte & 0xff) === 0xff) return false;        // ff00::/8 multicast
            $firstWord = hexdec(substr($hex, 0, 4));
            if (($firstWord & 0xffc0) === 0xfe80) return false;    // fe80::/10 link-local

            return true;
        }

        return false;
    }

    private function parseOgData(string $html, string $requestUrl): array
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $og    = [];

        // Collect all <meta> tags
        foreach ($xpath->query('//head/meta') as $meta) {
            if (! $meta instanceof \DOMElement) {
                continue;
            }
            $prop    = $meta->getAttribute('property') ?: $meta->getAttribute('name');
            $content = $meta->getAttribute('content');
            if ($prop && $content) {
                $og[$prop] = $content;
            }
        }

        // Collect <title> fallback
        $titleEl   = $xpath->query('//head/title')->item(0);
        $pageTitle = $titleEl ? trim($titleEl->textContent) : '';

        $title       = $this->clean($og['og:title']       ?? $og['twitter:title']       ?? $pageTitle,       150);
        $description = $this->clean($og['og:description'] ?? $og['twitter:description'] ?? $og['description'] ?? '', 300);
        $siteName    = $this->clean($og['og:site_name']   ?? parse_url($requestUrl, PHP_URL_HOST) ?? '', 80);
        $canonicalUrl = $og['og:url'] ?? $requestUrl;

        // Resolve image URL
        $image = $og['og:image'] ?? $og['og:image:url'] ?? $og['twitter:image'] ?? null;
        if ($image && ! filter_var($image, FILTER_VALIDATE_URL)) {
            $parts = parse_url($requestUrl);
            $image = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
                . (str_starts_with($image, '/') ? '' : '/') . ltrim($image, '/');
        }
        if ($image && ! filter_var($image, FILTER_VALIDATE_URL)) {
            $image = null;
        }

        return [
            'url'         => filter_var($canonicalUrl, FILTER_VALIDATE_URL) ? $canonicalUrl : $requestUrl,
            'title'       => $title,
            'description' => $description,
            'image'       => $image,
            'siteName'    => $siteName,
        ];
    }

    private function clean(string $value, int $maxLen): string
    {
        return mb_substr(trim(strip_tags($value)), 0, $maxLen);
    }
}
