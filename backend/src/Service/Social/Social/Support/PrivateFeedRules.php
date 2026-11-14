<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Model\PrivateFeedModel;

final class PrivateFeedRules
{
    private const string MASTODON_LIST = '/^\d{1,32}$/D';
    private const string MASTODON_TAG = '/^[\p{L}\p{M}\p{N}_\x{00B7}\x{200C}]{1,100}$/uD';

    public static function allows(SocialNetwork $network, PrivateFeedModel $privateFeed): bool
    {
        return match ($network) {
            SocialNetwork::Bluesky => self::bluesky($privateFeed),
            SocialNetwork::Mastodon => self::mastodon($privateFeed),
            SocialNetwork::Lemmy => self::lemmy($privateFeed),
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

    private static function bluesky(PrivateFeedModel $privateFeed): bool
    {
        $remoteId = $privateFeed->remoteId;

        return match ($privateFeed->kind) {
            SocialFeedKind::Home => true,
            SocialFeedKind::UserList => BlueskyRecordUri::isList($remoteId),
            SocialFeedKind::CustomFeed => BlueskyRecordUri::isFeedGenerator($remoteId),
            SocialFeedKind::Author => BlueskyActor::isDid($remoteId),
            SocialFeedKind::Search => self::isSearchQuery($remoteId),
            SocialFeedKind::Tag, SocialFeedKind::Trending, SocialFeedKind::Local, SocialFeedKind::All => false,
        };
    }

    private static function mastodon(PrivateFeedModel $privateFeed): bool
    {
        $remoteId = $privateFeed->remoteId;

        return match ($privateFeed->kind) {
            SocialFeedKind::Home, SocialFeedKind::Trending => true,
            SocialFeedKind::UserList => self::isMastodonListId($remoteId),
            SocialFeedKind::Tag => self::isMastodonTagName($remoteId),
            SocialFeedKind::Search => self::isSearchQuery($remoteId) && MastodonSearchQueries::fits($remoteId),
            SocialFeedKind::CustomFeed, SocialFeedKind::Author, SocialFeedKind::Local, SocialFeedKind::All => false,
        };
    }

    private static function lemmy(PrivateFeedModel $privateFeed): bool
    {
        return match ($privateFeed->kind) {
            SocialFeedKind::Home, SocialFeedKind::Local, SocialFeedKind::All => true,
            SocialFeedKind::Search => self::isSearchQuery($privateFeed->remoteId),
            SocialFeedKind::UserList,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Author,
            SocialFeedKind::Tag,
            SocialFeedKind::Trending => false,
        };
    }

    private static function isSearchQuery(string $query): bool
    {
        return '' !== $query && mb_strlen($query) <= SocialSearchQuery::MAX_LENGTH;
    }

    private static function matches(string $pattern, string $remoteId): bool
    {
        return 1 === preg_match($pattern, $remoteId);
    }

    private function __construct()
    {
    }
}
