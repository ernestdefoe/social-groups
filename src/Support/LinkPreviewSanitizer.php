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
        $url = filter_var($raw['url'] ?? '', FILTER_VALIDATE_URL);
        if (! $url || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        $image = filter_var($raw['image'] ?? '', FILTER_VALIDATE_URL);
        if (! $image) {
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
}
