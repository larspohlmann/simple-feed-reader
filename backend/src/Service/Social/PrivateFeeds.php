<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\KnownSocialCollectionRepository;
use App\Repository\SocialFeedRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\PrivateFeedOutcome;
use App\Service\Social\Support\PrivateFeedSite;
use App\Service\Social\Support\SocialFeedUrl;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionCreator;
use App\Service\Subscription\SubscriptionService;
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
    ) {
    }

    /** @throws SubscriptionLimitReachedException with nothing written and the entity manager still open */
    public function create(SocialAccount $account, PrivateFeedModel $privateFeed): PrivateFeedOutcome
    {
        if (null !== $this->subscriptionOf($account, $privateFeed)) {
            return PrivateFeedOutcome::AlreadySubscribed;
        }
        $this->subscribe($account, $privateFeed, []);

        return PrivateFeedOutcome::Created;
    }

    /** A person's feed is the user's through any of their accounts; a collection only through this one. */
    public function subscriptionOf(SocialAccount $account, PrivateFeedModel $privateFeed): ?Subscription
    {
        return $this->socialFeeds->findSubscriptionFor($account, $privateFeed);
    }

    /**
     * For a caller that has just found no subscriptionOf() the feed.
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
    public function subscribeInPlaceOf(
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
        foreach ($this->socialFeeds->findAllOf($account) as $socialFeed) {
            $socialFeed->getFeed()->scheduleNextFetchAt($now);
        }
    }

    public function removeAllOf(SocialAccount $account): int
    {
        return $this->subscriptionService->unsubscribeAll($this->socialFeeds->subscriptionsOf($account));
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
        $subscription = $this->subscriptionCreator->create(
            $account->getUser(),
            SocialFeedUrl::of($account, $privateFeed),
            self::sourceFormatOf($account),
            $tags,
            $privateFeed->name,
        );
        $followsOnePerson = $privateFeed->kind->followsOnePerson();
        $subscription->setIncludeInAllItems($followsOnePerson);
        $subscription->setIncludeInForYou($followsOnePerson);
        $feed = $subscription->getFeed();
        $feed->setSiteUrl(PrivateFeedSite::of($account, $privateFeed));
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

    /** @return 'bluesky-api'|'mastodon-api' */
    private static function sourceFormatOf(SocialAccount $account): string
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => SourceFormat::BLUESKY_API,
            SocialNetwork::Mastodon => SourceFormat::MASTODON_API,
        };
    }
}
