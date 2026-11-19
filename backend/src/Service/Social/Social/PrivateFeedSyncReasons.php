<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncReason;
use App\Enum\SocialFeedKind;
use App\Service\Social\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Social\Model\PrivateFeedModel;

final readonly class PrivateFeedSyncReasons
{
    public function __construct(private PrivateFeedRules $privateFeedRules)
    {
    }

    public function added(SocialAccount $account, PrivateFeedModel $collection): PrivateFeedSyncReason
    {
        if (SocialFeedKind::Tag === $collection->kind) {
            return PrivateFeedSyncReason::Followed;
        }

        return $this->isOwnedList($account, $collection)
            ? PrivateFeedSyncReason::Created
            : PrivateFeedSyncReason::Saved;
    }

    public function removed(
        SocialAccount $account,
        PrivateFeedModel $collection,
        NetworkCollectionsModel $network,
    ): PrivateFeedSyncReason {
        if (SocialFeedKind::Tag === $collection->kind) {
            return PrivateFeedSyncReason::Unfollowed;
        }
        if (\in_array($collection->remoteId, $network->goneIds, true) || $this->isOwnedList($account, $collection)) {
            return PrivateFeedSyncReason::Deleted;
        }

        return PrivateFeedSyncReason::Unsaved;
    }

    private function isOwnedList(SocialAccount $account, PrivateFeedModel $collection): bool
    {
        if (SocialFeedKind::UserList !== $collection->kind) {
            return false;
        }

        return $this->privateFeedRules->selectRule($account->getNetwork())->ownsList($account, $collection);
    }
}
