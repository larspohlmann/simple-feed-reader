<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Reading\Model\ReadScopeKind;
use App\Service\Reading\Model\ReadScopeModel;

/** "Mark all read until T" for a scope: every subscription the scope covers is marked by watermark. */
final readonly class MarkReadService
{
    public function __construct(
        private EntryReadMarker $readMarker,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
    ) {
    }

    public function mark(User $user, ReadScopeModel $scope, \DateTimeImmutable $until): void
    {
        $userId = $user->requireId();
        $this->readMarker->markSubscriptionsReadUntil($userId, $this->subscriptionsIn($userId, $scope), $until);
    }

    /** @return list<Subscription> */
    private function subscriptionsIn(int $userId, ReadScopeModel $scope): array
    {
        return match ($scope->kind) {
            ReadScopeKind::All => $this->subscriptions->findIncludedInAllItemsForUser($userId),
            ReadScopeKind::Feed => [$this->subscriptions->getOneForUser($userId, $scope->targetId())],
            ReadScopeKind::Tag => $this->subscriptions->findForUserByTagId(
                $userId,
                $this->tags->getOneForUser($userId, $scope->targetId())->requireId(),
            ),
        };
    }
}
