<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

use App\Service\Url\Model\YouTubeVideoModel;

/** Every rule for reading a YouTube URL: its hosts, its video id, and whether it is a Short. */
final class YouTubeUrl
{
    public const string VIDEO_ID_PATTERN = '[A-Za-z0-9_-]{11}';

    private const array COM_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];

    private const array NOCOOKIE_HOSTS = ['youtube-nocookie.com', 'www.youtube-nocookie.com'];

    private const array SHARE_LINK_HOSTS = ['youtu.be', 'www.youtu.be'];

    public const array HOSTS = [...self::COM_HOSTS, ...self::NOCOOKIE_HOSTS, ...self::SHARE_LINK_HOSTS];

    private const string SHORTS_PATH = '#^/shorts/(' . self::VIDEO_ID_PATTERN . ')/?\z#';

    private const string PLAYER_PATH = '#^/(?:embed/|v/)?(' . self::VIDEO_ID_PATTERN . ')\z#';

    private const string SHARE_LINK_PATH = '#^/(' . self::VIDEO_ID_PATTERN . ')/?\z#';

    public static function video(?string $url): ?YouTubeVideoModel
    {
        $parts = parse_url($url ?? '');
        if (!isset($parts['host'], $parts['path'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (\in_array($host, self::COM_HOSTS, true)) {
            return self::comVideo($parts['path'], $parts['query'] ?? '');
        }
        if (\in_array($host, self::NOCOOKIE_HOSTS, true)) {
            return self::pathVideo(self::PLAYER_PATH, $parts['path']);
        }

        return \in_array($host, self::SHARE_LINK_HOSTS, true)
            ? self::pathVideo(self::SHARE_LINK_PATH, $parts['path'])
            : null;
    }

    public static function isShort(?string $url): bool
    {
        return self::video($url)?->isShort === true;
    }

    public static function shortPlayerUrl(?string $url): ?string
    {
        $video = self::video($url);

        return $video?->isShort === true ? $video->playerUrl() : null;
    }

    public static function isShareLink(string $url): bool
    {
        return \in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::SHARE_LINK_HOSTS, true)
            && self::video($url) !== null;
    }

    public static function isComHost(string $host): bool
    {
        return \in_array(strtolower($host), self::COM_HOSTS, true);
    }

    public static function isVideoId(string $candidate): bool
    {
        return 1 === preg_match('#^' . self::VIDEO_ID_PATTERN . '\z#', $candidate);
    }

    private static function comVideo(string $path, string $query): ?YouTubeVideoModel
    {
        if (1 === preg_match(self::SHORTS_PATH, $path, $matches)) {
            return YouTubeVideoModel::short($matches[1]);
        }

        return self::pathVideo(self::PLAYER_PATH, $path) ?? self::queryVideo($query);
    }

    private static function pathVideo(string $pattern, string $path): ?YouTubeVideoModel
    {
        return 1 === preg_match($pattern, $path, $matches) ? YouTubeVideoModel::video($matches[1]) : null;
    }

    private static function queryVideo(string $query): ?YouTubeVideoModel
    {
        parse_str($query, $queryParameters);
        $id = $queryParameters['v'] ?? null;

        return \is_string($id) && self::isVideoId($id) ? YouTubeVideoModel::video($id) : null;
    }

    private function __construct()
    {
    }
}
