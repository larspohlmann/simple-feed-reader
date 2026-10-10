<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Support\YouTubePlaylistLink;

/** A playlist page advertises no feed, although YouTube serves one under the playlist's id. */
final readonly class YouTubePlaylistFeed implements ShareLinkFeedInterface
{
    private const string PLAYLIST_FEED = 'https://www.youtube.com/feeds/videos.xml?playlist_id=%s';

    public function feedUrl(string $enteredUrl): ?string
    {
        $listId = YouTubePlaylistLink::listId($enteredUrl);

        return null === $listId ? null : sprintf(self::PLAYLIST_FEED, $listId);
    }
}
