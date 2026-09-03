<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resolves a request's subscription ids to the caller's own subscriptions.
 *
 * Every endpoint taking a list of subscription ids needs the same refusal: an
 * id the caller does not own, an id that does not exist, and a duplicate all
 * get 422, nothing written. Three endpoints needed it (reorder, bulk update,
 * bulk unsubscribe), so the rule lives here instead of three times over.
 *
 * The count comparison catches all three at once: the repository only returns
 * rows the user owns, so a short result means an id was foreign or absent —
 * and a repeated id is short too, since `IN (...)` answers a duplicate once.
 * Comparing against the *unique* ids instead would let `[5, 5]` through,
 * which is the bug this replaces.
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
            throw new UnprocessableEntityHttpException(
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
