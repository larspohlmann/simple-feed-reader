<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Caches TagRepository::findAllByIdsForUser() for the lifetime of one request.
 *
 * SubscriptionTagSync::sync() re-resolves its requested tag ids on every call,
 * and a bulk write calls sync() once per subscription — a 500-feed tag change
 * would otherwise issue up to 500 near-identical queries, even though every id
 * was already validated up front (BulkSubscriptionUpdater::assertOwnedTagIds()).
 * This collaborator holds what has already been resolved so a repeated id
 * costs a map lookup, not a query, without lengthening sync()'s own signature
 * (CLAUDE.md: a value with no home gets a collaborator field, not a longer
 * parameter list).
 *
 * Keyed by user id so nothing leaks between accounts within one request. That
 * alone does not make it safe to keep alive PAST one request: this app's
 * functional tests reuse one container across requests via
 * $client->disableReboot(), and a worker process could do the same.
 * ResetInterface (auto-tagged kernel.reset) empties the cache between
 * requests so a stale entry — possibly bound to a since-reset EntityManager —
 * can never reach a later one.
 */
final class OwnedTagsCache implements ResetInterface
{
    /** @var array<int, array<int, Tag>> resolved tag, by id, by owning user id */
    private array $resolvedByUser = [];

    public function __construct(private readonly TagRepository $tags)
    {
    }

    public function reset(): void
    {
        $this->resolvedByUser = [];
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
