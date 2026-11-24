<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Ingestion\Feed\Factory\FeedFactory;
use App\Service\Library\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Library\Subscription\Model\BulkSubscribeResultModel;
use App\Service\Library\Subscription\Pass\BulkSubscribeBatch;
use App\Service\Library\Subscription\Pass\BulkSubscribePositions;
use App\Service\Library\Subscription\Pass\BulkSubscribeTagTree;
use App\Service\Library\Subscription\Support\SubscribableUrl;
use App\Service\Library\Tag\TagStructureLock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Subscribes a batch of feeds in one unit of work without fetching anything. Nothing flushes until the end, so a
 * BulkSubscribeBatch stands in for the rows this batch created.
 */
final readonly class BulkSubscriber
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FeedRepository $feeds,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private ClockInterface $clock,
        private SubscriptionLimitResolver $subscriptionLimits,
        private BulkTagger $bulkTagger,
        private FeedFactory $feedFactory,
        private TagStructureLock $tagStructureLock,
    ) {
    }

    /**
     * @param iterable<BulkSubscribeItemModel> $items
     */
    public function subscribeAll(User $user, iterable $items): BulkSubscribeResultModel
    {
        return $this->subscribeUnderLock($user, $items, $this->bulkTagger->tag(...));
    }

    /**
     * Tags each feed with the tags its item names where they already stand: a missing tag is created, a stored one
     * is never moved.
     *
     * @param iterable<BulkSubscribeItemModel> $items
     */
    public function subscribeAllInPlace(User $user, iterable $items): BulkSubscribeResultModel
    {
        return $this->subscribeUnderLock($user, $items, $this->bulkTagger->tagInPlace(...));
    }

    /**
     * @param iterable<BulkSubscribeItemModel> $items
     * @param \Closure(BulkSubscribeBatch, Subscription, BulkSubscribeItemModel): void $tagger
     */
    private function subscribeUnderLock(User $user, iterable $items, \Closure $tagger): BulkSubscribeResultModel
    {
        return $this->tagStructureLock->hold(
            $user,
            fn (): BulkSubscribeResultModel => $this->subscribeAllHeld($user, $items, $tagger),
        );
    }

    /**
     * @param iterable<BulkSubscribeItemModel> $items
     * @param \Closure(BulkSubscribeBatch, Subscription, BulkSubscribeItemModel): void $tagger
     */
    private function subscribeAllHeld(User $user, iterable $items, \Closure $tagger): BulkSubscribeResultModel
    {
        $batch = $this->open($user);
        foreach ($items as $item) {
            $this->subscribeOne($batch, $item, $tagger);
        }

        $this->entityManager->flush();

        return $batch->result();
    }

    private function open(User $user): BulkSubscribeBatch
    {
        $userId = $user->requireId();

        return new BulkSubscribeBatch(
            $user,
            $this->subscriptionLimits->resolve($user) - $this->subscriptions->countForUser($userId),
            new BulkSubscribePositions(
                $this->subscriptions->nextPositionForUser($userId),
                $this->tags->nextPositionForUser($userId),
            ),
            new BulkSubscribeTagTree($this->tags->findForUser($userId)),
        );
    }

    /** @param \Closure(BulkSubscribeBatch, Subscription, BulkSubscribeItemModel): void $tagger */
    private function subscribeOne(BulkSubscribeBatch $batch, BulkSubscribeItemModel $item, \Closure $tagger): void
    {
        $url = $item->feedUrl;
        if (!SubscribableUrl::isSubscribable($url)) {
            $batch->countInvalid();

            return;
        }
        if ($batch->hasSubscribed($url)) {
            $batch->countAlreadySubscribed();

            return;
        }

        // Looked up, not created yet: an item over the cap must not leave an orphan Feed row behind.
        $feed = $this->feeds->findOneBy(['url' => $url]);
        if ($this->isSubscribedTo($batch->user, $feed)) {
            $batch->countAlreadySubscribed();

            return;
        }
        if ($batch->isFull()) {
            $batch->countOverLimit();

            return;
        }

        $subscription = $this->persistSubscription($batch, $feed ?? $this->persistNewFeed($item));
        $tagger($batch, $subscription, $item);
        $batch->recordSubscribed($url, $subscription);
    }

    private function isSubscribedTo(User $user, ?Feed $feed): bool
    {
        return null !== $feed && $this->subscriptions->existsForUserAndFeed($user->requireId(), $feed->requireId());
    }

    private function persistNewFeed(BulkSubscribeItemModel $item): Feed
    {
        // Seeded for the sidebar before the first fetch; only on creation, since a shared row is not ours to retitle.
        $feed = $this->feedFactory->create($item->feedUrl, $item->sourceFormat, $item->feedTitle);
        $feed->scheduleNextFetchAt($this->clock->now());
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function persistSubscription(BulkSubscribeBatch $batch, Feed $feed): Subscription
    {
        $subscription = new Subscription($batch->user, $feed, $this->clock->now());
        $subscription->setPosition($batch->positions->takeSubscriptionPosition());
        $this->entityManager->persist($subscription);

        return $subscription;
    }
}
