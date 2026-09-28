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
            ReadScopeKind::All => $this->includedInAllItems($this->subscriptions->findForUserWithTags($userId)),
            ReadScopeKind::Feed => [$this->subscriptions->getOneForUser($userId, $scope->targetId())],
            ReadScopeKind::Tag => $this->subscriptions->findForUserByTagId(
                $userId,
                $this->tags->getOneForUser($userId, $scope->targetId())->requireId(),
            ),
        };
    }

    /**
     * Scope "all" mirrors what the All-items list shows, so a feed hidden from
     * it must not have its watermark advanced or its entries flipped read.
     *
     * @param  list<Subscription> $subscriptions
     * @return list<Subscription>
     */
    private function includedInAllItems(array $subscriptions): array
    {
        return array_values(array_filter(
            $subscriptions,
            static fn (Subscription $subscription): bool => $subscription->isIncludeInAllItems(),
        ));
    }
}
