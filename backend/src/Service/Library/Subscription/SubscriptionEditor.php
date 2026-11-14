<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription;

use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Foundation\Ordering\PositionReorderer;
use App\Service\Library\Subscription\Model\FeedMoveModel;
use App\Service\Library\Subscription\Model\SubscriptionChangeModel;
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

    public function update(Subscription $subscription, SubscriptionChangeModel $change): void
    {
        $subscription->setCustomTitle('' === $change->customTitle ? null : $change->customTitle);
        $this->tagSync->sync($subscription, $change->tagIds, $subscription->getUser()->requireId());
        $subscription->changeInclusions($change->inclusions);
        $this->entityManager->flush();
    }

    public function moveToTag(Subscription $subscription, FeedMoveModel $move): void
    {
        $this->feedTagMove->move($subscription, $move);
        $this->entityManager->flush();
    }

    /** @param list<int> $orderedSubscriptionIds */
    public function reorder(User $user, array $orderedSubscriptionIds): void
    {
        $this->reorderer->reorder(
            $orderedSubscriptionIds,
            $this->ownedSubscriptions->resolve($user->requireId(), $orderedSubscriptionIds),
        );
    }
}
