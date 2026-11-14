<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Service\Ingestion\Ingest\FeedTitle\FeedTitleInterface;
use App\Service\Social\Social\Support\ProfileFeedUrl;

/**
 * A Bluesky profile feed names its channel `@handle - Display Name`; the reader shows the person by their display
 * name. A Mastodon `@user.rss` channel already carries only the display name.
 */
final readonly class ProfileFeedTitle implements FeedTitleInterface
{
    private const string HANDLE_THEN_DISPLAY_NAME = '/^@\S+ - (.*)$/su';

    public function of(string $feedUrl, string $channelTitle): string
    {
        if (null === ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $feedUrl)) {
            return $channelTitle;
        }
        if (1 !== preg_match(self::HANDLE_THEN_DISPLAY_NAME, $channelTitle, $match) || '' === trim($match[1])) {
            return $channelTitle;
        }

        return trim($match[1]);
    }
}
