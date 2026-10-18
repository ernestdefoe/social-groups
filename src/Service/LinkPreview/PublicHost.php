<?php

namespace Ernestdefoe\SocialGroups\Service\LinkPreview;

/**
 * The SSRF guard. A host passes only if every address behind it is publicly
 * routable; the caller then pins those addresses for the fetch, so a
 * short-TTL domain cannot answer the check with a public address and the
 * connection with 169.254.169.254 (DNS rebinding).
 */
final class PublicHost
{
    /**
     * @return list<string>|null the addresses to pin, or null to refuse
     */
    public static function resolve(string $host): ?array
    {
        $host = rtrim(strtolower(rawurldecode($host)), '.');
        if ($host === '') {
            return null;
        }
        if (function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        $ips = self::addresses($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return null;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * 🚨 An IP literal counts only in its one canonical spelling. Resolvers
     * and libcurl disagree about the rest: 0177.0.0.1 resolved as the public
     * 177.0.0.1 while curl dialled the octal 127.0.0.1.
     *
     * @return list<string>
     */
    private static function addresses(string $host): array
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return [$bare];
        }

        if (preg_match('/^(?:\d+|0x[0-9a-f]*)(?:\.(?:\d*|0x[0-9a-f]*))*$/i', $host)) {
            return preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/', $host) ? [$host] : [];
        }

        $ips = [];
        foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }

    /**
     * PHP's private and reserved filters cover IPv4 except carrier-grade NAT;
     * for IPv6 they let loopback and link-local through, so those are checked
     * by hand.
     */
    private static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ((ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }

            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? inet_pton($ip) : false;
        if ($packed === false) {
            return false;
        }

        $hex = bin2hex($packed);
        if (str_starts_with($hex, '00000000000000000000ffff')) {
            return self::isPublic((string) long2ip((int) hexdec(substr($hex, 24, 8))));
        }

        $firstByte = hexdec(substr($hex, 0, 2));
        $firstWord = hexdec(substr($hex, 0, 4));

        return ! str_starts_with($hex, '0000000000000000000000000000000')
            && ($firstByte & 0xFE) !== 0xFC
            && $firstByte !== 0xFF
            && ($firstWord & 0xFFC0) !== 0xFE80;
    }
}
