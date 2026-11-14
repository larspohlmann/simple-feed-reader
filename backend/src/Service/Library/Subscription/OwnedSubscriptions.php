<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Exception\InvalidSelectionException;

/**
 * Resolves a request's subscription ids to the caller's own subscriptions, refusing foreign, absent and repeated
 * ids alike. A short result catches all three: `IN (...)` answers a duplicate once, so never compare against the
 * unique ids, which would let `[5, 5]` through.
 */
final readonly class OwnedSubscriptions
{
    public function __construct(private SubscriptionRepository $subscriptions)
    {
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Subscription> the resolved subscriptions, keyed by id
     */
    public function resolve(int $userId, array $ids): array
    {
        return $this->keyedById($this->subscriptions->findAllByIdsForUser($userId, $ids), $ids);
    }

    /**
     * resolve() with each subscription's feed and tags eager-loaded, for a caller that serializes the result.
     *
     * @param list<int> $ids
     *
     * @return array<int, Subscription> the resolved subscriptions, keyed by id
     */
    public function resolveWithAssociations(int $userId, array $ids): array
    {
        $owned = $this->subscriptions->findAllByIdsForUserWithAssociations($userId, $ids);

        return $this->keyedById($owned, $ids);
    }

    /**
     * @param list<Subscription> $owned
     * @param list<int>          $ids
     *
     * @return array<int, Subscription>
     */
    private function keyedById(array $owned, array $ids): array
    {
        if (\count($owned) !== \count($ids)) {
            throw new InvalidSelectionException(
                'subscriptionIds must all be your feeds, without duplicates.',
            );
        }

        $byId = [];
        foreach ($owned as $subscription) {
            $byId[$subscription->requireId()] = $subscription;
        }

        return $byId;
    }
}
