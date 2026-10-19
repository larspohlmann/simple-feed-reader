<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\BlueskyWeb;
use App\Service\Social\Model\PrivateFeedModel;

/** The web page a private feed stands for, so its favicon and "visit site" link never see `social:`. */
final class PrivateFeedSite
{
    public static function of(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => self::bluesky($account, $privateFeed),
            SocialNetwork::Mastodon => self::mastodon($account, $privateFeed),
        };
    }

    private static function bluesky(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        if ($privateFeed->kind->followsOnePerson()) {
            return BlueskyWeb::profileUrl($privateFeed->remoteId);
        }

        return BlueskyRecordUri::webUrl($privateFeed->remoteId)
            ?? BlueskyWeb::profileUrl($account->getIdentity());
    }

    private static function mastodon(SocialAccount $account, PrivateFeedModel $privateFeed): string
    {
        $instance = rtrim($account->getServiceUrl(), '/');

        return match ($privateFeed->kind) {
            SocialFeedKind::UserList => $instance . '/lists/' . rawurlencode($privateFeed->remoteId),
            SocialFeedKind::Tag => $instance . '/tags/' . rawurlencode($privateFeed->remoteId),
            default => $instance,
        };
    }

    private function __construct()
    {
    }
}
