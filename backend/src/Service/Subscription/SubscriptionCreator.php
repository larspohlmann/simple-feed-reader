<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Feed\Factory\FeedFactory;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The row mechanics of subscribing one feed: the per-user cap, the shared Feed row, the duplicate check, the tag
 * positions. BulkSubscriber keeps its own copy of these rules, because it defers every flush to the end of an import.
 */
final readonly class SubscriptionCreator
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private FeedRepository $feeds,
        private SubscriptionTagRepository $subscriptionTags,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private SubscriptionLimitResolver $subscriptionLimits,
        private FeedFactory $feedFactory,
    ) {
    }

    /**
     * Both single-feed paths come through here, so the cap, the shared-feed
     * lookup and the duplicate check cannot diverge between them.
     *
     * @param 'xml'|'scraped'|'wp-json' $sourceFormat
     * @param list<Tag>                 $tags
     */
    public function create(
        User $user,
        string $feedUrl,
        string $sourceFormat,
        array $tags,
        ?string $initialTitle = null,
    ): Subscription {
        $userId = $user->requireId();
        $limit = $this->subscriptionLimits->resolve($user);
        if ($this->subscriptions->countForUser($userId) >= $limit) {
            throw new SubscriptionLimitReachedException($limit);
        }

        $feed = $this->feeds->findOneBy(['url' => $feedUrl]);
        if (null === $feed) {
            // New shared feed: nextFetchAt null => due immediately. XML and
            // scraped metadata still wait for the refresh pipeline.
            $feed = $this->feedFactory->create($feedUrl, $sourceFormat, $initialTitle);
            $this->entityManager->persist($feed);
            $this->entityManager->flush(); // assign an id so the duplicate check is meaningful
        } elseif (SourceFormat::XML === $sourceFormat && SourceFormat::SCRAPED === $feed->getSourceFormat()) {
            // One-way heal of a shared row poisoned as 'scraped': an 'xml' arrival means discovery parsed the URL as
            // a feed. Never the reverse: a 'scraped' arrival is only the user's assertion.
            $feed->setSourceFormat(SourceFormat::XML);
            // Flushed before the duplicate check can throw: re-adding the feed is how a subscriber repairs it, and
            // that throw would roll the heal back.
            $this->entityManager->flush();
        }

        if ($this->subscriptions->existsForUserAndFeed($userId, $feed->requireId())) {
            throw new AlreadySubscribedException();
        }

        $subscription = new Subscription($user, $feed, $this->clock->now());
        $subscription->setPosition($this->subscriptions->nextPositionForUser($userId));
        $this->attachTags($subscription, $tags);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    /**
     * Attach each tag at the end of its own list (one past the tag's current
     * max), so a feed added to a tag never floats above feeds already in it.
     * The join rows cascade-persist with the subscription.
     *
     * @param list<Tag> $tags
     */
    private function attachTags(Subscription $subscription, array $tags): void
    {
        foreach ($tags as $tag) {
            $subscription->addTag($tag, $this->subscriptionTags->nextPositionForTag($tag));
        }
    }
}
