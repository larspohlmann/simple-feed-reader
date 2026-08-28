<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;

/**
 * Aligns a subscription's tag set with the tags a PATCH request asked for,
 * preserving each kept tag's per-tag position instead of clearing and re-adding.
 */
final readonly class SubscriptionTagSync
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private SubscriptionRepository $subscriptions,
    ) {
    }

    /**
     * @param list<int> $requestedTagIds The user-owned tag ids the feed should carry
     */
    public function sync(Subscription $subscription, array $requestedTagIds, int $userId): void
    {
        $wasTagged = !$subscription->getTags()->isEmpty();

        // Sync by DIFF, not clear-and-re-add: a tag the feed keeps must retain
        // its per-tag position, and a newly added tag appends to the end of
        // that tag's list.
        $resolved = $this->tags->findAllByIdsForUser($requestedTagIds, $userId);
        $resolvedIds = array_map(static fn (Tag $tag): int => (int) $tag->getId(), $resolved);

        foreach ($subscription->getTags() as $existing) {
            if (!\in_array((int) $existing->getId(), $resolvedIds, true)) {
                $subscription->removeTag($existing);
            }
        }
        $currentIds = array_map(static fn (Tag $tag): int => (int) $tag->getId(), $subscription->getTags()->toArray());
        foreach ($resolved as $tag) {
            if (!\in_array((int) $tag->getId(), $currentIds, true)) {
                $subscription->addTag($tag, $this->subscriptionTags->nextPositionForTag($tag));
            }
        }

        // A feed that just lost its last tag joins the untagged "Feeds" list;
        // append it so its stale position doesn't float it to the top.
        if ($wasTagged && $subscription->getTags()->isEmpty()) {
            $subscription->setPosition($this->subscriptions->nextPositionForUser($userId));
        }
    }
}
