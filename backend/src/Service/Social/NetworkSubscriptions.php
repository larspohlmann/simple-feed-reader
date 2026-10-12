<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Support\SocialFeedClassifier;

/** A user's subscriptions to one network's feeds, private and profile alike, whether All items shows them or not. */
final readonly class NetworkSubscriptions
{
    public function __construct(private SubscriptionRepository $subscriptions)
    {
    }

    /** @return list<Subscription> */
    public function of(int $userId, SocialNetwork $network): array
    {
        return array_values(array_filter(
            $this->subscriptions->findForUserWithTags($userId),
            static fn (Subscription $subscription): bool
                => SocialFeedClassifier::networkOf($subscription->getFeed()) === $network,
        ));
    }

    /** @return list<int> */
    public function idsOf(int $userId, SocialNetwork $network): array
    {
        return array_map(
            static fn (Subscription $subscription): int => $subscription->requireId(),
            $this->of($userId, $network),
        );
    }
}
