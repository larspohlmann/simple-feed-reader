<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Support\YouTubePlaylistLink;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Reader\Media\Support\YouTubeVideoId;

/**
 * Maps a video link to its channel's feed. The watch page advertises no feed, but it names the channel. A video
 * watched inside a playlist is the playlist's link, and is left to YouTubePlaylistFeed.
 */
final readonly class YouTubeVideoChannelFeed implements ShareLinkFeedInterface
{
    private const string SHORT_LINK_HOST = 'youtu.be';

    private const string SHORT_LINK_PATH = '#^/(' . YouTubeVideoId::PATTERN . ')/?\z#';

    private const string VIDEO_PATH = '#^/(?:shorts|live)/(' . YouTubeVideoId::PATTERN . ')/?\z#';

    private const string CHANNEL_ID = '#"externalChannelId":"(UC[A-Za-z0-9_-]{22})"#';

    private const string WATCH_PAGE = 'https://www.youtube.com/watch?v=%s';

    private const string CHANNEL_FEED = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    public function feedUrl(string $enteredUrl): ?string
    {
        if (null !== YouTubePlaylistLink::listId($enteredUrl)) {
            return null;
        }

        $videoId = $this->videoId($enteredUrl);
        if (null === $videoId) {
            return null;
        }

        $channelId = $this->channelId($videoId);

        return null === $channelId ? null : sprintf(self::CHANNEL_FEED, $channelId);
    }

    private function videoId(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);
        if (self::SHORT_LINK_HOST === $host) {
            return 1 === preg_match(self::SHORT_LINK_PATH, $path, $match) ? $match[1] : null;
        }

        if (!YouTubeVideoId::isYouTubeComHost($host)) {
            return null;
        }

        if ('/watch' === $path) {
            return $this->watchedVideoId($enteredUrl);
        }

        return 1 === preg_match(self::VIDEO_PATH, $path, $match) ? $match[1] : null;
    }

    private function watchedVideoId(string $enteredUrl): ?string
    {
        parse_str((string) parse_url($enteredUrl, PHP_URL_QUERY), $query);
        $videoId = $query['v'] ?? null;

        return \is_string($videoId) && YouTubeVideoId::matches($videoId) ? $videoId : null;
    }

    private function channelId(string $videoId): ?string
    {
        try {
            $response = $this->fetcher->fetch(sprintf(self::WATCH_PAGE, $videoId));
        } catch (FetchException) {
            return null;
        }

        return 1 === preg_match(self::CHANNEL_ID, $response->modifiedBody(), $match) ? $match[1] : null;
    }
}
