<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resolves a request's subscription ids to the caller's own subscriptions.
 *
 * Every endpoint that takes a list of subscription ids needs the same refusal:
 * an id the caller does not own, an id that does not exist, and a duplicate are
 * all the same answer — 422, and nothing written. Three endpoints needed it
 * (reorder, bulk update, bulk unsubscribe), so the rule lives here rather than
 * three times in the controller.
 *
 * The count comparison catches all three cases at once: the repository only
 * returns rows the user owns, so a short result means at least one id was
 * foreign or absent — and a repeated id is short too, because `IN (...)`
 * answers a duplicate once. Comparing against the *unique* ids instead would
 * let `[5, 5]` through, which is the bug this replaces.
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
        $owned = $this->subscriptions->findAllByIdsForUser($ids, $userId);
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
