<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Moves one feed between the sidebar's lists the way a drag does: out of the
 * source tag and into the destination at the dropped position. The destination
 * is a tag's own feed list, or the untagged "Feeds" list when the feed loses
 * its last tag. Placement renumbers the destination densely so the other feeds
 * shift to make room.
 */
final readonly class FeedTagMove
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private SubscriptionRepository $subscriptions,
    ) {
    }

    public function move(
        Subscription $subscription,
        ?int $fromTagId,
        ?int $toTagId,
        ?int $position,
        int $userId,
    ): void {
        $fromTag = $this->ownedTagOrNull($fromTagId, $userId);
        $toTag = $this->ownedTagOrNull($toTagId, $userId);

        if (null !== $fromTag) {
            $subscription->removeTag($fromTag);
        }

        if (null !== $toTag) {
            $this->placeInTag($subscription, $toTag, $position);

            return;
        }

        if ($subscription->getTags()->isEmpty()) {
            $this->placeInUntaggedList($subscription, $userId, $position);
        }
    }

    private function ownedTagOrNull(?int $tagId, int $userId): ?Tag
    {
        if (null === $tagId) {
            return null;
        }

        return $this->tags->findOneOwnedBy($tagId, $userId)
            ?? throw new UnprocessableEntityHttpException('The tag must be one of yours.');
    }

    private function placeInTag(Subscription $subscription, Tag $tag, ?int $position): void
    {
        $others = $this->tagFeedsExcept($tag, (int) $subscription->getId());
        $subscription->addTag($tag);
        $ordered = $this->spliceIn($others, $this->tagJoin($subscription, $tag), $position);

        foreach ($ordered as $index => $join) {
            $join->setPosition($index);
        }
    }

    private function placeInUntaggedList(Subscription $subscription, int $userId, ?int $position): void
    {
        $others = $this->untaggedFeedsExcept($userId, (int) $subscription->getId());
        $ordered = $this->spliceIn($others, $subscription, $position);

        foreach ($ordered as $index => $feed) {
            $feed->setPosition($index);
        }
    }

    /**
     * The tag's feeds in per-tag order, without the moved feed — the siblings
     * the moved feed is inserted among.
     *
     * @return list<SubscriptionTag>
     */
    private function tagFeedsExcept(Tag $tag, int $subscriptionId): array
    {
        $joins = $this->subscriptionTags->forTagBySubscriptionId($tag);
        unset($joins[$subscriptionId]);
        $ordered = array_values($joins);
        usort(
            $ordered,
            static fn (SubscriptionTag $a, SubscriptionTag $b): int => $a->getPosition() <=> $b->getPosition(),
        );

        return $ordered;
    }

    /**
     * The user's untagged feeds in "Feeds" list order, without the moved feed —
     * the siblings it joins when it loses its last tag. Derived from the feeds
     * the sidebar already loads rather than a query of its own.
     *
     * @return list<Subscription>
     */
    private function untaggedFeedsExcept(int $userId, int $subscriptionId): array
    {
        $untagged = array_values(array_filter(
            $this->subscriptions->findForUserWithTags($userId),
            static fn (Subscription $feed): bool => $feed->getTags()->isEmpty()
                && (int) $feed->getId() !== $subscriptionId,
        ));
        usort($untagged, static fn (Subscription $a, Subscription $b): int => $a->getPosition() <=> $b->getPosition());

        return $untagged;
    }

    private function tagJoin(Subscription $subscription, Tag $tag): SubscriptionTag
    {
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ($join->getTag() === $tag) {
                return $join;
            }
        }
        throw new \LogicException('The tag was just added; its join must exist.');
    }

    /**
     * Insert one item into an ordered list at the given index, clamped into
     * range; a null index appends.
     *
     * @template T of object
     *
     * @param list<T> $items
     * @param T       $item
     *
     * @return list<T>
     */
    private function spliceIn(array $items, object $item, ?int $position): array
    {
        $index = null === $position ? \count($items) : max(0, min($position, \count($items)));
        array_splice($items, $index, 0, [$item]);

        return $items;
    }
}
