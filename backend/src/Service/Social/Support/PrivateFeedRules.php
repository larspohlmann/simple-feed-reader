<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\PrivateFeedModel;

final class PrivateFeedRules
{
    private const string MASTODON_LIST = '/^\d{1,32}$/D';
    private const string MASTODON_TAG = '/^[\p{L}\p{M}\p{N}_\x{00B7}\x{200C}]{1,100}$/uD';

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
            SocialFeedKind::Tag => SocialNetwork::Mastodon === $network
                && self::isMastodonTagName($privateFeed->remoteId),
        };
    }

    public static function isMastodonListId(string $listId): bool
    {
        return self::matches(self::MASTODON_LIST, $listId);
    }

    public static function isMastodonTagName(string $tagName): bool
    {
        return self::matches(self::MASTODON_TAG, $tagName);
    }

    private static function matches(string $pattern, string $remoteId): bool
    {
        return 1 === preg_match($pattern, $remoteId);
    }

    private function __construct()
    {
    }
}
