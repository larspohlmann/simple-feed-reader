<?php

declare(strict_types=1);

namespace App\Service\Discovery\Support;

use App\Service\Reader\Media\Support\YouTubeVideoId;

final class YouTubePlaylistLink
{
    private const array PLAYLIST_PATHS = ['/playlist', '/watch'];

    private const string SHORT_LINK_HOST = 'youtu.be';

    private const string SHORT_LINK_PATH = '#^/' . YouTubeVideoId::PATTERN . '/?\z#';

    private const string LIST_ID = '#^[A-Za-z0-9_-]+\z#';

    private const array PERSONAL_LISTS = ['WL', 'LL', 'LM'];

    /** A mix or a personal list belongs to the viewer and has no feed: YouTube answers 404. */
    private const string MIX_PREFIX = 'RD';

    public static function listId(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (!self::isPlaylistPage($host, $path)) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $listId = $query['list'] ?? null;

        return \is_string($listId) && self::isSubscribable($listId) ? $listId : null;
    }

    private static function isPlaylistPage(string $host, string $path): bool
    {
        if (YouTubeVideoId::isYouTubeComHost($host)) {
            return \in_array($path, self::PLAYLIST_PATHS, true);
        }

        return self::SHORT_LINK_HOST === strtolower($host) && 1 === preg_match(self::SHORT_LINK_PATH, $path);
    }

    private static function isSubscribable(string $listId): bool
    {
        return 1 === preg_match(self::LIST_ID, $listId)
            && !str_starts_with($listId, self::MIX_PREFIX)
            && !\in_array($listId, self::PERSONAL_LISTS, true);
    }

    private function __construct()
    {
    }
}
