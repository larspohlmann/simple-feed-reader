<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Repository\KnownSocialCollectionRepository;
use App\Repository\SocialFeedRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Subscription\Exception\AlreadySubscribedException;
use App\Service\Library\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Library\Subscription\SubscriptionCreator;
use App\Service\Library\Subscription\SubscriptionService;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\PrivateFeedOutcome;
use App\Service\Social\Social\Support\SocialFeedUrl;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PrivateFeeds
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private KnownSocialCollectionRepository $knownCollections,
        private SubscriptionCreator $subscriptionCreator,
        private SubscriptionService $subscriptionService,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private PrivateFeedRules $privateFeedRules,
    ) {
    }

    /** @throws SubscriptionLimitReachedException with nothing written and the entity manager still open */
    public function create(SocialAccount $account, PrivateFeedModel $privateFeed): PrivateFeedOutcome
    {
        if (null !== $this->findSubscription($account, $privateFeed)) {
            return PrivateFeedOutcome::AlreadySubscribed;
        }
        $this->subscribe($account, $privateFeed, []);

        return PrivateFeedOutcome::Created;
    }

    /** A person's feed is the user's through any of their accounts; a collection only through this one. */
    public function findSubscription(SocialAccount $account, PrivateFeedModel $privateFeed): ?Subscription
    {
        return $this->socialFeeds->findSubscriptionFor($account, $privateFeed);
    }

    /**
     * For a caller whose findSubscription() found none.
     *
     * @param list<Tag> $tags
     *
     * @throws SubscriptionLimitReachedException with nothing written and the entity manager still open
     * @throws AlreadySubscribedException
     */
    public function subscribe(SocialAccount $account, PrivateFeedModel $privateFeed, array $tags): Subscription
    {
        return $this->inOneTransaction(
            fn (): Subscription => $this->subscribed($account, $privateFeed, $tags),
        );
    }

    /**
     * subscribe(), with the new subscription taking over $previous's settings before anything is committed.
     *
     * @throws SubscriptionLimitReachedException with nothing written and the entity manager still open
     * @throws AlreadySubscribedException
     */
    public function subscribeKeepingSettings(
        SocialAccount $account,
        PrivateFeedModel $privateFeed,
        Subscription $previous,
    ): Subscription {
        return $this->inOneTransaction(function () use ($account, $privateFeed, $previous): Subscription {
            $subscription = $this->subscribed($account, $privateFeed, []);
            $subscription->takeSettingsFrom($previous);

            return $subscription;
        });
    }

    /** Makes the account's private feeds due at once, after a reconnect lifted their pause. The caller flushes. */
    public function resume(SocialAccount $account): void
    {
        $now = $this->clock->now();
        foreach ($this->socialFeeds->findAllForAccount($account) as $socialFeed) {
            $socialFeed->getFeed()->scheduleNextFetchAt($now);
        }
    }

    public function unsubscribeAllFeeds(SocialAccount $account): int
    {
        return $this->subscriptionService->unsubscribeAll($this->socialFeeds->findSubscriptions($account));
    }

    /** @param \Closure(): Subscription $subscribing */
    private function inOneTransaction(\Closure $subscribing): Subscription
    {
        $this->entityManager->beginTransaction();
        try {
            $subscription = $subscribing();
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $subscription;
    }

    /** @param list<Tag> $tags */
    private function subscribed(SocialAccount $account, PrivateFeedModel $privateFeed, array $tags): Subscription
    {
        $rule = $this->privateFeedRules->selectRule($account->getNetwork());
        $subscription = $this->subscriptionCreator->create(
            $account->getUser(),
            SocialFeedUrl::buildUrl($account, $privateFeed),
            $rule->sourceFormat(),
            $tags,
            $privateFeed->name,
        );
        $followsOnePerson = $privateFeed->kind->followsOnePerson();
        $subscription->changeInclusions(new InclusionChange(
            allItems: $followsOnePerson,
            forYou: $followsOnePerson,
            yourStream: $followsOnePerson,
        ));
        $feed = $subscription->getFeed();
        $feed->setSiteUrl($rule->buildSiteUrl($account, $privateFeed));
        $this->recordSocialFeed($account, $feed, $privateFeed);
        if ($privateFeed->kind->isRememberedCollection()) {
            $this->knownCollections->remember($account, $privateFeed->kind, $privateFeed->remoteId);
        }

        return $subscription;
    }

    private function recordSocialFeed(SocialAccount $account, Feed $feed, PrivateFeedModel $privateFeed): void
    {
        if (null === $this->socialFeeds->findOneFor($account, $privateFeed->kind, $privateFeed->remoteId)) {
            $this->entityManager->persist(new SocialFeed($account, $feed, $privateFeed->kind, $privateFeed->remoteId));
        }
    }
}
