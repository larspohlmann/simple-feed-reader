<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;

/** In-memory private Mastodon feeds of one account, for unit tests that never reach a network. */
final class PrivateFeeds
{
    use AssignsEntityIds;

    public const int ACCOUNT_ID = 7;

    public static function mastodonAccount(): SocialAccount
    {
        return self::withId(SocialAccounts::mastodon(), self::ACCOUNT_ID);
    }

    public static function mastodon(
        SocialAccount $account,
        int $feedId,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        return new SocialFeed($account, self::mastodonFeed($feedId), $kind, $remoteId);
    }

    public static function mastodonFeed(int $feedId): Feed
    {
        $feed = self::withId(new Feed(sprintf('social:mastodon:%d:%d', self::ACCOUNT_ID, $feedId)), $feedId);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        return $feed;
    }
}
