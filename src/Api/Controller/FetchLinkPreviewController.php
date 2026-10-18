<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Service\LinkPreview\OpenGraph;
use Ernestdefoe\SocialGroups\Service\LinkPreview\PublicHost;
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

/**
 * The composer's link card. The forum fetches the page for the member, so
 * the fetch is guarded: public hosts on ports 80 and 443 only, addresses
 * pinned, no redirects, a size and time cap, and a per-member rate limit.
 */
class FetchLinkPreviewController implements RequestHandlerInterface
{
    private const CACHE_TTL = 3600;

    /** Enough to reach the Open Graph tags in <head>. */
    private const MAX_BYTES = 524288;

    /** Uncached fetches per member per minute. */
    private const PER_MINUTE = 20;

    private const USER_AGENT = 'flarum-social-groups/1.0 (+https://github.com/ernestdefoe/social-groups)';

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
        if (! OpenGraph::isWebUrl($url)) {
            return $this->error('invalid_url', 422);
        }

        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'http' ? 80 : 443));
        $ips = PublicHost::resolve($host);

        if ($ips === null || ! in_array($port, [80, 443], true)) {
            return $this->error('url_host_not_allowed', 422);
        }

        $cacheKey = 'sg-link-preview:'.hash('sha256', $url);
        if ($cached = $this->cache->get($cacheKey)) {
            return new JsonResponse($cached);
        }

        $rateKey = 'sg-link-preview-rate:'.$actor->id.':'.intdiv(time(), 60);
        $this->cache->add($rateKey, 0, 120);
        if ($this->cache->increment($rateKey) > self::PER_MINUTE) {
            return $this->error('too_many_previews', 429);
        }

        try {
            /*
             * Redirects stay off: following one would dial a new host that
             * never passed the guard.
             */
            $html = $this->http->request('GET', $url, [
                'stream' => true,
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
                'curl' => [
                    CURLOPT_RESOLVE => [sprintf('%s:%d:%s', strtolower($host), $port, implode(',', $ips))],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ],
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                ],
            ])->getBody()->read(self::MAX_BYTES);
        } catch (RequestException) {
            return $this->error('fetch_failed', 502);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] link preview failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('unexpected', 500);
        }

        $preview = OpenGraph::parse($html, $url);
        $this->cache->put($cacheKey, $preview, self::CACHE_TTL);

        return new JsonResponse($preview);
    }

    private function error(string $key, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.'.$key)], $status);
    }
}
