<?php

namespace Ernestdefoe\SocialGroups\Service\LinkPreview;

/**
 * Reads a page's Open Graph card: title, description, image, site name.
 * Only http(s) URLs come back, so nothing else can reach an href or src.
 */
final class OpenGraph
{
    /**
     * @return array{url: string, title: string, description: string, image: string|null, siteName: string}
     */
    public static function parse(string $html, string $pageUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        $meta = [];
        foreach ($xpath->query('//head/meta') ?: [] as $tag) {
            if ($tag instanceof \DOMElement) {
                $key = $tag->getAttribute('property') ?: $tag->getAttribute('name');
                $value = $tag->getAttribute('content');
                if ($key !== '' && $value !== '') {
                    $meta[$key] = $value;
                }
            }
        }
        $pageTitle = trim((string) $xpath->query('//head/title')?->item(0)?->textContent);

        $image = $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? null;
        if ($image !== null && ! self::isWebUrl($image)) {
            $parts = parse_url($pageUrl);
            $image = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').'/'.ltrim($image, '/');
        }

        return [
            'url' => self::isWebUrl($meta['og:url'] ?? '') ? $meta['og:url'] : $pageUrl,
            'title' => self::clean($meta['og:title'] ?? $meta['twitter:title'] ?? $pageTitle, 150),
            'description' => self::clean($meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? '', 300),
            'image' => $image !== null && self::isWebUrl($image) ? $image : null,
            'siteName' => self::clean($meta['og:site_name'] ?? (string) parse_url($pageUrl, PHP_URL_HOST), 80),
        ];
    }

    public static function isWebUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function clean(string $value, int $length): string
    {
        return mb_substr(trim(strip_tags($value)), 0, $length);
    }
}
