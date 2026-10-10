<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

use App\Service\Reader\Media\Support\YouTubeVideoId;

/**
 * A playlist page advertises no feed, although YouTube serves one under the playlist's id. A video watched inside a
 * playlist is the playlist's link; a list without a feed (a mix, Watch Later) falls back to the watch page.
 */
final readonly class YouTubePlaylistFeed implements ShareLinkFeedInterface
{
    private const array PLAYLIST_PATHS = ['/playlist', '/watch'];

    private const string SHORT_LINK_HOST = 'youtu.be';

    private const string SHORT_LINK_PATH = '#^/' . YouTubeVideoId::PATTERN . '\z#';

    private const string LIST_ID = '#^[A-Za-z0-9_-]+\z#';

    private const string PLAYLIST_FEED = 'https://www.youtube.com/feeds/videos.xml?playlist_id=%s';

    public function feedUrl(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        $path = rtrim((string) parse_url($enteredUrl, PHP_URL_PATH), '/');
        if (!$this->isPlaylistPage($host, $path)) {
            return null;
        }

        parse_str((string) parse_url($enteredUrl, PHP_URL_QUERY), $query);
        $listId = $query['list'] ?? null;

        return \is_string($listId) && 1 === preg_match(self::LIST_ID, $listId)
            ? sprintf(self::PLAYLIST_FEED, $listId)
            : null;
    }

    private function isPlaylistPage(string $host, string $path): bool
    {
        if (YouTubeVideoId::isYouTubeComHost($host)) {
            return \in_array($path, self::PLAYLIST_PATHS, true);
        }

        return self::SHORT_LINK_HOST === $host && 1 === preg_match(self::SHORT_LINK_PATH, $path);
    }
}
