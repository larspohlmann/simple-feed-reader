<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Support\BlueskyWeb;
use App\Service\Social\Social\Model\PrivateFeedModel;

/** The web page a private feed stands for, so its favicon and "visit site" link never see `social:`. */
final class PrivateFeedSite
{
    public static function of(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::bluesky($account, $privateFeed),
            SocialNetwork::Mastodon => self::mastodon($account, $privateFeed),
            SocialNetwork::Lemmy => self::lemmy($account, $privateFeed),
        };
    }

    private static function bluesky(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        return match (true) {
            SocialFeedKind::Search === $privateFeed->kind => BlueskyWeb::searchUrl($privateFeed->remoteId),
            $privateFeed->kind->followsOnePerson() => BlueskyWeb::profileUrl($privateFeed->remoteId),
            default => BlueskyRecordUri::webUrl($privateFeed->remoteId)
                ?? BlueskyWeb::profileUrl($account->getIdentity()),
        };
    }

    private static function mastodon(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $instance = rtrim($account->getServiceUrl(), '/');

        return match ($privateFeed->kind) {
            SocialFeedKind::UserList => $instance . '/lists/' . rawurlencode($privateFeed->remoteId),
            SocialFeedKind::Tag => $instance . '/tags/' . rawurlencode($privateFeed->remoteId),
            SocialFeedKind::Trending => $instance . '/explore',
            SocialFeedKind::Search => $instance . '/search?q=' . rawurlencode($privateFeed->remoteId),
            SocialFeedKind::Home,
            SocialFeedKind::Author,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Local,
            SocialFeedKind::All => $instance,
        };
    }

    private static function lemmy(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $instance = rtrim($account->getServiceUrl(), '/');

        return match ($privateFeed->kind) {
            SocialFeedKind::Home => $instance . '/?listingType=Subscribed',
            SocialFeedKind::Local => $instance . '/?listingType=Local',
            SocialFeedKind::All => $instance . '/?listingType=All',
            SocialFeedKind::Search => $instance . '/search?q=' . rawurlencode($privateFeed->remoteId) . '&type=Posts',
            SocialFeedKind::UserList,
            SocialFeedKind::CustomFeed,
            SocialFeedKind::Author,
            SocialFeedKind::Tag,
            SocialFeedKind::Trending => $instance,
        };
    }

    private function __construct()
    {
    }
}
