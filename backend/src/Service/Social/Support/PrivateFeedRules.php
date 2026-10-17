<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;

final class PrivateFeedRules
{
    private const string MASTODON_LIST = '/^\d{1,32}$/D';

    public static function allows(SocialNetwork $network, PrivateFeedModel $privateFeed): bool
    {
        return match ($privateFeed->kind) {
            SocialFeedKind::Home => true,
            SocialFeedKind::UserList => SocialNetwork::Bluesky === $network
                ? BlueskyRecordUri::isList($privateFeed->remoteId)
                : self::matches(self::MASTODON_LIST, $privateFeed->remoteId),
            SocialFeedKind::CustomFeed => SocialNetwork::Bluesky === $network
                && BlueskyRecordUri::isFeedGenerator($privateFeed->remoteId),
            SocialFeedKind::Author => SocialNetwork::Bluesky === $network
                && BlueskyActor::isDid($privateFeed->remoteId),
        };
    }

    public static function isMastodonListId(string $listId): bool
    {
        return self::matches(self::MASTODON_LIST, $listId);
    }

    private static function matches(string $pattern, string $remoteId): bool
    {
        return 1 === preg_match($pattern, $remoteId);
    }

    private function __construct()
    {
    }
}
