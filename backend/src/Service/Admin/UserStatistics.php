<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\UserFootprint;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\SubscriptionService;
use Psr\Clock\ClockInterface;

/**
 * The figures behind the admin's per-user detail screen.
 *
 * Deliberately one account at a time: it backs a detail page, not a list, so
 * the batched reads the list endpoint needs (SubscriptionRepository::
 * countsByUserIds) would buy nothing here.
 */
final readonly class UserStatistics
{
    /** No sign-in for this long marks an account dormant. */
    private const int DORMANT_AFTER_DAYS = 90;

    /** Refresh is manual, so a week without a fetch is the useful staleness mark. */
    private const int STALE_AFTER_DAYS = 7;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }

    public function forUser(User $user): UserFootprint
    {
        $subscriptions = $this->subscriptions->findForUserWithTags((int) $user->getId());
        $now = $this->clock->now();

        return new UserFootprint(
            feedsCount: \count($subscriptions),
            tagsCount: \count($this->tags->findForUser((int) $user->getId())),
            feedsLimit: SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER,
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

            // A feed that was never fetched is stale by definition, not fresh.
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

        return ($user->getLastLoginAt() ?? $user->getCreatedAt()) < $cutoff;
    }
}
