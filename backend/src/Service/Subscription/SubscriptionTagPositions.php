<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The next per-tag join position and the next untagged "Feeds" position, seeded from the database once and then
 * counted in memory: BulkSubscriptionUpdater flushes once after many sync() calls, so a MAX(position) query per call
 * would return the same stale maximum.
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
        $tagId = $tag->requireId();
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
