<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedOffer;

use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Reader\Media\Support\YouTubeVideoId;

/** Offers the channel feed of the video a YouTube watch page plays; the page names the channel but no feed. */
final readonly class YouTubeVideoChannelFeed implements FeedOfferInterface
{
    private const string CHANNEL_ID = '#"externalChannelId":"(UC[A-Za-z0-9_-]{22})"#';

    private const string CHANNEL_NAME = '#"ownerChannelName":("(?:[^"\\\\]|\\\\.)*")#';

    private const string CHANNEL_FEED = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';

    public function offer(string $body, string $pageUrl): ?FeedCandidateModel
    {
        if (
            !YouTubeVideoId::isYouTubeComHost((string) parse_url($pageUrl, PHP_URL_HOST))
            || 1 !== preg_match(self::CHANNEL_ID, $body, $match)
        ) {
            return null;
        }

        return new FeedCandidateModel(sprintf(self::CHANNEL_FEED, $match[1]), $this->channelName($body), 'atom');
    }

    private function channelName(string $body): ?string
    {
        $name = 1 === preg_match(self::CHANNEL_NAME, $body, $match) ? json_decode($match[1]) : null;

        return \is_string($name) && '' !== $name ? $name : null;
    }
}
