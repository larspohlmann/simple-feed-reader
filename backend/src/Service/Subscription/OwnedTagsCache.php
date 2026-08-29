<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;
use App\Repository\TagRepository;

/**
 * Caches TagRepository::findAllByIdsForUser() for the lifetime of one request.
 *
 * SubscriptionTagSync::sync() re-resolves its requested tag ids on every call,
 * and a bulk write calls sync() once per subscription — a 500-feed tag change
 * would otherwise issue up to 500 near-identical tag queries, even though
 * every id it could possibly ask for was already validated once up front
 * (BulkSubscriptionUpdater::assertOwnedTagIds()). This collaborator holds what
 * has already been resolved so a repeated id costs a map lookup, not a query —
 * without threading a cache through sync()'s own signature (CLAUDE.md: a value
 * with no home gets a collaborator that holds it as a field, not a longer
 * parameter list).
 *
 * A plain (non-shared-across-requests) service: this app runs one PHP process
 * per request, so one instance lives exactly as long as one request. Keyed by
 * user id regardless, so nothing could leak between accounts even if that
 * ever changed.
 */
final class OwnedTagsCache
{
    /** @var array<int, array<int, Tag>> resolved tag, by id, by owning user id */
    private array $resolvedByUser = [];

    public function __construct(private readonly TagRepository $tags)
    {
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Tag>
     */
    public function findAllByIdsForUser(array $ids, int $userId): array
    {
        $this->resolveMissing($ids, $userId);

        $resolved = $this->resolvedByUser[$userId] ?? [];

        return array_values(array_filter(array_map(
            static fn (int $id): ?Tag => $resolved[$id] ?? null,
            $ids,
        )));
    }

    /**
     * @param list<int> $ids
     */
    private function resolveMissing(array $ids, int $userId): void
    {
        $known = $this->resolvedByUser[$userId] ?? [];
        $missing = array_values(array_unique(
            array_filter($ids, static fn (int $id): bool => !isset($known[$id])),
        ));
        if ([] === $missing) {
            return;
        }

        foreach ($this->tags->findAllByIdsForUser($missing, $userId) as $tag) {
            $this->resolvedByUser[$userId][(int) $tag->getId()] = $tag;
        }
    }
}
