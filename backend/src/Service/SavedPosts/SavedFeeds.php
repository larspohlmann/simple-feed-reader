<?php

declare(strict_types=1);

namespace App\Service\SavedPosts;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SavedPosts\Factory\SavedFeedFactory;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use Doctrine\ORM\EntityManagerInterface;

/** Bypasses SubscriptionCreator on purpose: the cap would refuse the feed an unsubscribe needs to rescue into. */
final readonly class SavedFeeds
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private FeedRepository $feeds,
        private SavedFeedFactory $savedFeedFactory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function of(User $user, ?SocialNetwork $network): Subscription
    {
        $userId = $user->requireId();
        $url = SavedFeedUrl::of($userId, $network);
        $existing = $this->subscriptions->findOneForUserAndFeedUrl($userId, $url);
        if (null !== $existing) {
            return $existing;
        }

        $feed = $this->feeds->findOneBy(['url' => $url]) ?? $this->savedFeedFactory->feed($userId, $network);
        $subscription = $this->savedFeedFactory->subscription(
            $user,
            $feed,
            $this->subscriptions->nextPositionForUser($userId),
        );
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }
}
