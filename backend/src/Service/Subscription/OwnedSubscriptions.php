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
    public function resolve(array $ids, int $userId): array
    {
        return $this->keyedById($this->subscriptions->findAllByIdsForUser($ids, $userId), $ids);
    }

    /**
     * Same guarantee as resolve(), but with each subscription's feed and tags
     * eager-loaded — for a caller that goes on to serialize the result (the
     * bulk-update response, say) rather than only write through it.
     *
     * @param list<int> $ids
     *
     * @return array<int, Subscription> the resolved subscriptions, keyed by id
     */
    public function resolveWithAssociations(array $ids, int $userId): array
    {
        $owned = $this->subscriptions->findAllByIdsForUserWithAssociations($ids, $userId);

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
            $byId[(int) $subscription->getId()] = $subscription;
        }

        return $byId;
    }
}
