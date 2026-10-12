<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\SocialFeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\PrivateFeedOutcome;
use App\Service\Social\Support\PrivateFeedSite;
use App\Service\Social\Support\SocialFeedUrl;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionCreator;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PrivateFeeds
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private SubscriptionRepository $subscriptions,
        private SubscriptionCreator $subscriptionCreator,
        private SubscriptionService $subscriptionService,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @throws SubscriptionLimitReachedException with nothing written and the entity manager still open */
    public function create(SocialAccount $account, PrivateFeedModel $privateFeed): PrivateFeedOutcome
    {
        $this->entityManager->beginTransaction();
        try {
            $outcome = $this->createOnce($account, $privateFeed);
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $outcome;
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

    private function createOnce(SocialAccount $account, PrivateFeedModel $privateFeed): PrivateFeedOutcome
    {
        $socialFeed = $this->socialFeeds->findOneFor($account, $privateFeed->kind, $privateFeed->remoteId);
        if (null !== $socialFeed && $this->isSubscribed($account, $socialFeed->getFeed())) {
            return PrivateFeedOutcome::AlreadySubscribed;
        }

        $subscription = $this->subscriptionCreator->create(
            $account->getUser(),
            SocialFeedUrl::of($account, $privateFeed),
            self::sourceFormatOf($account),
            [],
            $privateFeed->name,
        );
        $subscription->setIncludeInAllItems(false);
        $subscription->setIncludeInForYou(false);
        $feed = $subscription->getFeed();
        $feed->setSiteUrl(PrivateFeedSite::of($account, $privateFeed));
        if (null === $socialFeed) {
            $this->entityManager->persist(new SocialFeed($account, $feed, $privateFeed->kind, $privateFeed->remoteId));
        }
        $this->entityManager->flush();

        return PrivateFeedOutcome::Created;
    }

    private function isSubscribed(SocialAccount $account, Feed $feed): bool
    {
        return $this->subscriptions->existsForUserAndFeed($account->getUser()->requireId(), $feed->requireId());
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
