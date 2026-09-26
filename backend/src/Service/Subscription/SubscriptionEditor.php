<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Dto\Subscription\MoveFeedToTagRequest;
use App\Dto\Subscription\ReorderSubscriptionsRequest;
use App\Dto\Subscription\UpdateSubscriptionRequest;
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

    public function update(Subscription $subscription, UpdateSubscriptionRequest $request): void
    {
        $subscription->setCustomTitle('' === $request->customTitle ? null : $request->customTitle);
        $this->tagSync->sync($subscription, $request->tagIds, $subscription->getUser()->requireId());
        $this->applyFlags($subscription, $request);
        $this->entityManager->flush();
    }

    public function moveToTag(Subscription $subscription, MoveFeedToTagRequest $request): void
    {
        $this->feedTagMove->move(
            $subscription,
            $request->fromTagId,
            $request->toTagId,
            $request->position,
            $subscription->getUser()->requireId(),
        );
        $this->entityManager->flush();
    }

    public function reorder(User $user, ReorderSubscriptionsRequest $request): void
    {
        $this->reorderer->reorder(
            $request->subscriptionIds,
            $this->ownedSubscriptions->resolve($request->subscriptionIds, $user->requireId()),
        );
    }

    private function applyFlags(Subscription $subscription, UpdateSubscriptionRequest $request): void
    {
        if (null !== $request->includeInAllItems) {
            $subscription->setIncludeInAllItems($request->includeInAllItems);
        }
        if (null !== $request->includeInForYou) {
            $subscription->setIncludeInForYou($request->includeInForYou);
        }
    }
}
