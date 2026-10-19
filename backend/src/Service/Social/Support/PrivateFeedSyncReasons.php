<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncReason;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Model\PrivateFeedModel;

final class PrivateFeedSyncReasons
{
    public static function added(SocialAccount $account, PrivateFeedModel $collection): PrivateFeedSyncReason
    {
        if (SocialFeedKind::Tag === $collection->kind) {
            return PrivateFeedSyncReason::Followed;
        }

        return self::isOwnedList($account, $collection)
            ? PrivateFeedSyncReason::Created
            : PrivateFeedSyncReason::Saved;
    }

    public static function removed(
        SocialAccount $account,
        PrivateFeedModel $collection,
        NetworkCollectionsModel $network,
    ): PrivateFeedSyncReason {
        if (SocialFeedKind::Tag === $collection->kind) {
            return PrivateFeedSyncReason::Unfollowed;
        }
        if (\in_array($collection->remoteId, $network->goneIds, true) || self::isOwnedList($account, $collection)) {
            return PrivateFeedSyncReason::Deleted;
        }

        return PrivateFeedSyncReason::Unsaved;
    }

    /** A Mastodon list is always the account's own; a Bluesky list is when its at-URI names the account's DID. */
    private static function isOwnedList(SocialAccount $account, PrivateFeedModel $collection): bool
    {
        if (SocialFeedKind::UserList !== $collection->kind) {
            return false;
        }

        return match ($account->getNetwork()) {
            SocialNetwork::Mastodon => true,
            SocialNetwork::Bluesky => str_starts_with($collection->remoteId, 'at://' . $account->getIdentity() . '/'),
        };
    }

    private function __construct()
    {
    }
}
