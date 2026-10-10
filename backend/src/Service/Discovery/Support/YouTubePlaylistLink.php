<?php

declare(strict_types=1);

namespace App\Service\Discovery\Support;

use App\Service\Reader\Media\Support\YouTubeVideoId;

final class YouTubePlaylistLink
{
    private const array PLAYLIST_PATHS = ['/playlist', '/watch'];

    private const string LIST_ID = '#^[A-Za-z0-9_-]+$#';

    /** An auto-generated mix is personal to the viewer and has no feed: YouTube answers 404. */
    private const string MIX_PREFIX = 'RD';

    public static function listId(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (!YouTubeVideoId::isYouTubeComHost($host) || !\in_array($path, self::PLAYLIST_PATHS, true)) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $listId = $query['list'] ?? null;

        return \is_string($listId) && 1 === preg_match(self::LIST_ID, $listId)
            && !str_starts_with($listId, self::MIX_PREFIX)
            ? $listId
            : null;
    }

    private function __construct()
    {
    }
}
