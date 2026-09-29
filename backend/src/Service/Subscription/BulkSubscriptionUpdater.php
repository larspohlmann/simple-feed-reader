<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Exception\InvalidSelectionException;
use App\Repository\TagRepository;
use App\Service\Subscription\Model\BulkSubscriptionChangeModel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies one tag and flag change across many subscriptions, with one flush after the loop. The per-feed tag rules
 * (append positions, the untagged list) belong to SubscriptionTagSync: never copy them here.
 */
final readonly class BulkSubscriptionUpdater
{
    public function __construct(
        private OwnedSubscriptions $ownedSubscriptions,
        private TagRepository $tags,
        private SubscriptionTagSync $tagSync,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Subscription> the changed subscriptions, in request order
     */
    public function apply(BulkSubscriptionChangeModel $change, int $userId): array
    {
        $this->assertNoContradictoryTagChange($change);

        $addTagIds = $this->assertOwnedTagIds($change->addTagIds, $userId);
        $removeTagIds = $this->assertOwnedTagIds($change->removeTagIds, $userId);
        // Eager: the response serializes every feed and its tags, which plain resolve() would load one by one.
        $byId = $this->ownedSubscriptions->resolveWithAssociations($userId, $change->subscriptionIds);

        $changed = [];
        foreach ($change->subscriptionIds as $subscriptionId) {
            $subscription = $byId[$subscriptionId];
            $tagIds = $this->resultingTagIds($subscription, $addTagIds, $removeTagIds);
            $this->tagSync->sync($subscription, $tagIds, $userId);
            $this->applyFlags($subscription, $change);
            $changed[] = $subscription;
        }

        $this->entityManager->flush();

        return $changed;
    }

    private function assertNoContradictoryTagChange(BulkSubscriptionChangeModel $change): void
    {
        if ([] === array_intersect($change->addTagIds, $change->removeTagIds)) {
            return;
        }

        throw new InvalidSelectionException(
            'A tag cannot be added and removed in the same request.',
        );
    }

    /**
     * @param list<int> $tagIds
     *
     * @return list<int>
     */
    private function assertOwnedTagIds(array $tagIds, int $userId): array
    {
        if ([] === $tagIds) {
            return [];
        }

        $owned = $this->tags->findAllByIdsForUser($userId, $tagIds);
        if (\count($owned) !== \count($tagIds)) {
            throw new InvalidSelectionException(
                'addTagIds and removeTagIds must all be your tags, without duplicates.',
            );
        }

        return array_map(static fn (Tag $tag): int => $tag->requireId(), $owned);
    }

    /**
     * The feed's tags after this request: what it has, plus what was added,
     * minus what was removed. The current ids come first and keep their order,
     * so every kept tag holds its per-tag position through the sync.
     *
     * @param list<int> $addTagIds
     * @param list<int> $removeTagIds
     *
     * @return list<int>
     */
    private function resultingTagIds(Subscription $subscription, array $addTagIds, array $removeTagIds): array
    {
        $current = array_map(
            static fn (Tag $tag): int => $tag->requireId(),
            $subscription->getTags()->toArray(),
        );

        return array_values(array_diff(array_unique([...$current, ...$addTagIds]), $removeTagIds));
    }

    private function applyFlags(Subscription $subscription, BulkSubscriptionChangeModel $change): void
    {
        if (null !== $change->includeInAllItems) {
            $subscription->setIncludeInAllItems($change->includeInAllItems);
        }
        if (null !== $change->includeInForYou) {
            $subscription->setIncludeInForYou($change->includeInForYou);
        }
    }
}
