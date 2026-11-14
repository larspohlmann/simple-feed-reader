<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Foundation\Ordering\PositionReorderer;
use App\Service\Library\Subscription\Exception\SocialTimelineLanguageException;
use App\Service\Library\Subscription\Model\FeedLanguageChangeModel;
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
        if (null !== $change->language) {
            $this->changeLanguage($subscription->getFeed(), $change->language);
        }
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

    private function changeLanguage(Feed $feed, FeedLanguageChangeModel $change): void
    {
        if (null !== $change->language && SourceFormat::isSocial($feed->getSourceFormat())) {
            throw new SocialTimelineLanguageException('A social timeline guesses each post\'s language on its own.');
        }

        $feed->recordTraits($feed->getTraits()->withLanguage($change->language));
    }
}
