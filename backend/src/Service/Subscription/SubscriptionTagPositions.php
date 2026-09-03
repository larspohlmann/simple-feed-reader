<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hands out the next per-tag join position and the next untagged "Feeds" list
 * position, seeded from the database once per tag/user and advanced in
 * memory from there.
 *
 * SubscriptionTagSync::sync() used to ask the repositories directly, a
 * MAX(position) query every time — correct for one sync() call followed by
 * its own flush, but BulkSubscriptionUpdater::apply() calls sync() once per
 * subscription and flushes only ONCE after the loop, so a repeated MAX()
 * query never sees rows the earlier iterations just added and keeps
 * returning the same stale maximum. This collaborator is the counters' home:
 * seed once, then increment in memory, without lengthening sync()'s own
 * signature (CLAUDE.md).
 *
 * Implements ResetInterface so counters cannot survive into a later request
 * when a worker reuses the PHP process (see OwnedTagsCache for why that
 * assumption does not hold in this app's own test client).
 */
final class SubscriptionTagPositions implements ResetInterface
{
    /** @var array<int, int> next join position, keyed by tag id */
    private array $nextByTagId = [];

    /** @var array<int, int> next untagged position, keyed by user id */
    private array $nextByUserId = [];

    public function __construct(
        private readonly SubscriptionTagRepository $subscriptionTags,
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    public function nextForTag(Tag $tag): int
    {
        $tagId = (int) $tag->getId();
        $this->nextByTagId[$tagId] ??= $this->subscriptionTags->nextPositionForTag($tag);

        return $this->nextByTagId[$tagId]++;
    }

    public function nextUntaggedForUser(int $userId): int
    {
        $this->nextByUserId[$userId] ??= $this->subscriptions->nextPositionForUser($userId);

        return $this->nextByUserId[$userId]++;
    }

    public function reset(): void
    {
        $this->nextByTagId = [];
        $this->nextByUserId = [];
    }
}
