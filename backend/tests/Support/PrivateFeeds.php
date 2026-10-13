<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;

/** In-memory private Mastodon and Bluesky feeds of one account, for unit tests that never reach a network. */
final class PrivateFeeds
{
    use AssignsEntityIds;

    public const int ACCOUNT_ID = 7;

    public static function mastodonAccount(): SocialAccount
    {
        return self::withId(SocialAccounts::mastodon(), self::ACCOUNT_ID);
    }

    public static function of(
        SocialAccount $account,
        int $feedId,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        return match ($account->getNetwork()) {
            SocialNetwork::Mastodon => self::mastodon($account, $feedId, $kind, $remoteId),
            SocialNetwork::Bluesky => self::bluesky($account, $feedId, $kind, $remoteId),
        };
    }

    public static function mastodon(
        SocialAccount $account,
        int $feedId,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        return new SocialFeed($account, self::mastodonFeed($feedId), $kind, $remoteId);
    }

    public static function bluesky(
        SocialAccount $account,
        int $feedId,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        return new SocialFeed($account, self::blueskyFeed($feedId), $kind, $remoteId);
    }

    public static function mastodonFeed(int $feedId): Feed
    {
        return self::feed('mastodon', SourceFormat::MASTODON_API, $feedId);
    }

    public static function blueskyFeed(int $feedId): Feed
    {
        return self::feed('bluesky', SourceFormat::BLUESKY_API, $feedId);
    }

    private static function feed(string $network, string $sourceFormat, int $feedId): Feed
    {
        $feed = self::withId(new Feed(sprintf('social:%s:%d:%d', $network, self::ACCOUNT_ID, $feedId)), $feedId);
        $feed->setSourceFormat($sourceFormat);

        return $feed;
    }
}
