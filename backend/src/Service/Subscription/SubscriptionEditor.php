<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscriptionEditor
{
    public function __construct(
        private SubscriptionTagSync $tagSync,
        private FeedTagMove $feedTagMove,
        private OwnedSubscriptions $ownedSubscriptions,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function update(Subscription $subscription, SubscriptionChange $change): void
    {
        $subscription->setCustomTitle('' === $change->customTitle ? null : $change->customTitle);
        $this->tagSync->sync($subscription, $change->tagIds, $subscription->getUser()->requireId());
        $this->applyFlags($subscription, $change);
        $this->entityManager->flush();
    }

    public function moveToTag(Subscription $subscription, TagMove $move): void
    {
        $this->feedTagMove->move($subscription, $move);
        $this->entityManager->flush();
    }

    /** @param list<int> $orderedSubscriptionIds */
    public function reorder(User $user, array $orderedSubscriptionIds): void
    {
        $this->reorderer->reorder(
            $orderedSubscriptionIds,
            $this->ownedSubscriptions->resolve($orderedSubscriptionIds, $user->requireId()),
        );
    }

    private function applyFlags(Subscription $subscription, SubscriptionChange $change): void
    {
        if (null !== $change->includeInAllItems) {
            $subscription->setIncludeInAllItems($change->includeInAllItems);
        }
        if (null !== $change->includeInForYou) {
            $subscription->setIncludeInForYou($change->includeInForYou);
        }
    }
}
