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

    public function subscribe(User $user, string $url): SubscribeOutcome
    {
        $result = $this->discovery->discover($url);

        if (!$result->isDirectFeed) {
            return SubscribeOutcome::candidates($result->candidates);
        }

        $userId = (int) $user->getId();
        if ($this->subscriptions->countForUser($userId) >= self::MAX_SUBSCRIPTIONS_PER_USER) {
            throw new SubscriptionLimitReachedException(self::MAX_SUBSCRIPTIONS_PER_USER);
        }

        $feedUrl = (string) $result->feedUrl;
        $feed = $this->feeds->findOneBy(['url' => $feedUrl]);
        if (null === $feed) {
            // New shared feed: nextFetchAt null => due immediately; the first
            // refresh fills in title/entries. Metadata is the refresh pipeline's
            // job, not the subscribe path's.
            $feed = new Feed($feedUrl);
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

        return SubscribeOutcome::subscribed($subscription);
    }
}
