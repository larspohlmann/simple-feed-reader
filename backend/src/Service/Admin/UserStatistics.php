<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Admin\Model\UserFootprintModel;
use App\Service\Subscription\SubscriptionLimitResolver;
use Psr\Clock\ClockInterface;

final readonly class UserStatistics
{
    /** No sign-in for this long marks an account dormant. */
    private const int DORMANT_AFTER_DAYS = 90;

    /** Refresh is manual, so a week without a fetch is the useful staleness mark. */
    private const int STALE_AFTER_DAYS = 7;

    public function __construct(
        private ClockInterface $clock,
        private SubscriptionLimitResolver $subscriptionLimits,
    ) {
    }

    /**
     * @param list<Subscription> $subscriptions
     * @param list<Tag> $tags
     */
    public function forUser(User $user, array $subscriptions, array $tags): UserFootprintModel
    {
        $now = $this->clock->now();

        return new UserFootprintModel(
            feedsCount: \count($subscriptions),
            tagsCount: \count($tags),
            feedsLimit: $this->subscriptionLimits->resolve($user),
            staleFeedsCount: $this->countStale($subscriptions, $now),
            lastRefreshAt: $this->newestFetch($subscriptions),
            dormant: $this->isDormant($user, $now),
        );
    }

    /**
     * @param list<Subscription> $subscriptions
     */
    private function countStale(array $subscriptions, \DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(\sprintf('-%d days', self::STALE_AFTER_DAYS));
        $stale = 0;

        foreach ($subscriptions as $subscription) {
            $fetchedAt = $subscription->getFeed()->getLastFetchedAt();

            // Inclusive (exactly 7 days is stale), unlike isDormant()'s strict `<`: the spec wants the asymmetry.
            if (null === $fetchedAt || $fetchedAt <= $cutoff) {
                ++$stale;
            }
        }

        return $stale;
    }

    /**
     * @param list<Subscription> $subscriptions
     */
    private function newestFetch(array $subscriptions): ?\DateTimeImmutable
    {
        $newest = null;

        foreach ($subscriptions as $subscription) {
            $fetchedAt = $subscription->getFeed()->getLastFetchedAt();

            if (null !== $fetchedAt && (null === $newest || $fetchedAt > $newest)) {
                $newest = $fetchedAt;
            }
        }

        return $newest;
    }

    /**
     * An account that never signed in is judged on its age instead, so a
     * registration from this morning does not read as abandoned.
     */
    private function isDormant(User $user, \DateTimeImmutable $now): bool
    {
        $cutoff = $now->modify(\sprintf('-%d days', self::DORMANT_AFTER_DAYS));

        // Exclusive (exactly 90 days is not yet dormant), unlike countStale()'s `<=`: the spec wants the asymmetry.
        return ($user->getLastLoginAt() ?? $user->getCreatedAt()) < $cutoff;
    }
}
