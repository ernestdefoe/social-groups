<?php

namespace Ernestdefoe\SocialGroups\Support;

/**
 * A link preview arrives from the composer, so every field is re-checked
 * before it is stored: http(s) URLs only, tags stripped, lengths capped.
 */
final class LinkPreviewSanitizer
{
    /**
     * @param array<string, mixed> $raw
     * @return array{url: string, title: string, description: string, image: string|null, siteName: string}|null
     */
    public static function sanitize(array $raw): ?array
    {
        $url = self::webUrl($raw['url'] ?? '');
        if ($url === null) {
            return null;
        }

        $image = self::webUrl($raw['image'] ?? '');
        if ($image === null) {
            $path = (string) ($raw['image'] ?? '');
            $image = str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : null;
        }

        return [
            'url' => $url,
            'title' => mb_substr(strip_tags((string) ($raw['title'] ?? '')), 0, 200),
            'description' => mb_substr(strip_tags((string) ($raw['description'] ?? '')), 0, 500),
            'image' => $image ?: null,
            'siteName' => mb_substr(strip_tags((string) ($raw['siteName'] ?? '')), 0, 100),
        ];
    }

    private static function webUrl(mixed $value): ?string
    {
        $url = filter_var($value, FILTER_VALIDATE_URL);

        return $url && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }
}
