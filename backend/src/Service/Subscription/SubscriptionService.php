<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\AlreadySubscribedException;
use App\Exception\SubscriptionLimitReachedException;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Discovery\FeedDiscoveryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class SubscriptionService
{
    public const MAX_SUBSCRIPTIONS_PER_USER = 500;

    public function __construct(
        private FeedDiscoveryInterface $discovery,
        private SubscriptionRepository $subscriptions,
        private FeedRepository $feeds,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function subscribe(User $user, string $url, ?string $format = null): SubscribeOutcome
    {
        // A 'scraped' subscribe re-posts a candidate URL discovery itself just
        // produced: the page IS the feed. Running discovery again would
        // re-fetch and re-extract for nothing — or, worse, fail this time and
        // block a subscribe the user was already offered.
        if ('scraped' === $format) {
            return SubscribeOutcome::subscribed($this->createSubscription($user, $url, 'scraped'));
        }

        $result = $this->discovery->discover($url);

        if (!$result->isDirectFeed) {
            return SubscribeOutcome::candidates($result->candidates, $result->scrapeFailureReason);
        }

        return SubscribeOutcome::subscribed(
            $this->createSubscription($user, (string) $result->feedUrl, 'xml'),
        );
    }

    /**
     * The one place a subscription row comes into being — both the
     * discovery-confirmed path and the scraped shortcut go through here, so
     * the cap, the shared-feed lookup and the duplicate check can never
     * diverge between them.
     */
    private function createSubscription(User $user, string $feedUrl, string $sourceFormat): Subscription
    {
        $userId = (int) $user->getId();
        if ($this->subscriptions->countForUser($userId) >= self::MAX_SUBSCRIPTIONS_PER_USER) {
            throw new SubscriptionLimitReachedException(self::MAX_SUBSCRIPTIONS_PER_USER);
        }

        $feed = $this->feeds->findOneBy(['url' => $feedUrl]);
        if (null === $feed) {
            // New shared feed: nextFetchAt null => due immediately; the first
            // refresh fills in title/entries. Metadata is the refresh pipeline's
            // job, not the subscribe path's. The source format is set at
            // creation ONLY — a feed other users already share keeps the format
            // it was created with, no matter how a later subscriber arrived.
            $feed = new Feed($feedUrl);
            $feed->setSourceFormat($sourceFormat);
            $this->em->persist($feed);
            $this->em->flush(); // assign an id so the duplicate check is meaningful
        }

        if ($this->subscriptions->existsForUserAndFeed($userId, (int) $feed->getId())) {
            throw new AlreadySubscribedException();
        }

        $subscription = new Subscription($user, $feed, $this->clock->now());
        $subscription->setPosition($this->subscriptions->nextPositionForUser($userId));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
