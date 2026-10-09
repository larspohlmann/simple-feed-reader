<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Support;

final class YouTubeShortUrl
{
    public const string FRAGMENT = '#shorts';

    private const string PATH_PATTERN = '#^/shorts/(' . YouTubeVideoId::PATTERN . ')/?$#';

    public static function is(?string $url): bool
    {
        $parts = $url === null ? false : parse_url($url);

        return isset($parts['host'], $parts['path']) && self::videoId($parts['host'], $parts['path']) !== null;
    }

    public static function videoId(string $host, string $path): ?string
    {
        if (!YouTubeVideoId::isYouTubeComHost($host)) {
            return null;
        }

        return preg_match(self::PATH_PATTERN, $path, $matches) === 1 ? $matches[1] : null;
    }

    private function __construct()
    {
    }
}
