<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Applies one tag and flag change across many subscriptions in a single
 * request.
 *
 * The per-feed tag work is delegated to SubscriptionTagSync, which owns two
 * rules that are easy to get subtly wrong: a newly added tag appends at that
 * tag's next position, and a feed that loses its LAST tag is appended to the
 * untagged list so a stale position does not float it to the top. Reproducing
 * either of them here would be the second copy this collaborator exists to
 * prevent.
 *
 * One flush for the whole request, after the loop. A flush per feed would turn
 * a 176-feed selection into 176 transactions.
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
    public function apply(BulkUpdateSubscriptionsRequest $request, int $userId): array
    {
        $this->assertNoContradictoryTagChange($request);

        $addTagIds = $this->assertOwnedTagIds($request->addTagIds, $userId);
        $removeTagIds = $this->assertOwnedTagIds($request->removeTagIds, $userId);
        // The eager variant: the controller serializes every changed
        // subscription's feed and tags into the response, and the plain
        // resolve() leaves both lazy — up to 500 extra SELECTs for one request.
        $byId = $this->ownedSubscriptions->resolveWithAssociations($request->subscriptionIds, $userId);

        $changed = [];
        foreach ($request->subscriptionIds as $subscriptionId) {
            $subscription = $byId[$subscriptionId];
            $tagIds = $this->resultingTagIds($subscription, $addTagIds, $removeTagIds);
            $this->tagSync->sync($subscription, $tagIds, $userId);
            $this->applyFlags($subscription, $request);
            $changed[] = $subscription;
        }

        $this->entityManager->flush();

        return $changed;
    }

    private function assertNoContradictoryTagChange(BulkUpdateSubscriptionsRequest $request): void
    {
        if ([] === array_intersect($request->addTagIds, $request->removeTagIds)) {
            return;
        }

        throw new UnprocessableEntityHttpException(
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

        $owned = $this->tags->findAllByIdsForUser($tagIds, $userId);
        if (\count($owned) !== \count($tagIds)) {
            throw new UnprocessableEntityHttpException(
                'addTagIds and removeTagIds must all be your tags, without duplicates.',
            );
        }

        return array_map(static fn (Tag $tag): int => (int) $tag->getId(), $owned);
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
            static fn (Tag $tag): int => (int) $tag->getId(),
            $subscription->getTags()->toArray(),
        );

        return array_values(array_diff(array_unique([...$current, ...$addTagIds]), $removeTagIds));
    }

    private function applyFlags(Subscription $subscription, BulkUpdateSubscriptionsRequest $request): void
    {
        if (null !== $request->includeInAllItems) {
            $subscription->setIncludeInAllItems($request->includeInAllItems);
        }
        if (null !== $request->includeInForYou) {
            $subscription->setIncludeInForYou($request->includeInForYou);
        }
    }
}
