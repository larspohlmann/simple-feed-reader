<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Support;

final class YouTubeShortUrl
{
    public const string VIDEO_ID_PATTERN = '[A-Za-z0-9_-]{11}';

    private const array HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];

    public static function videoId(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['host'], $parts['path']) || !\in_array(strtolower($parts['host']), self::HOSTS, true)) {
            return null;
        }

        $pattern = '#^/shorts/(' . self::VIDEO_ID_PATTERN . ')/?$#';

        return preg_match($pattern, $parts['path'], $matches) === 1 ? $matches[1] : null;
    }

    public static function is(?string $url): bool
    {
        return $url !== null && self::videoId($url) !== null;
    }

    private function __construct()
    {
    }
}
