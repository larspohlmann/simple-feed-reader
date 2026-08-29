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
 * would otherwise issue up to 500 near-identical tag queries, even though
 * every id it could possibly ask for was already validated once up front
 * (BulkSubscriptionUpdater::assertOwnedTagIds()). This collaborator holds what
 * has already been resolved so a repeated id costs a map lookup, not a query —
 * without threading a cache through sync()'s own signature (CLAUDE.md: a value
 * with no home gets a collaborator that holds it as a field, not a longer
 * parameter list).
 *
 * Keyed by user id so nothing could leak between accounts within one request.
 * That alone does not make the service safe to keep alive PAST one request,
 * though: this app's own functional tests reuse one container across several
 * requests via $client->disableReboot(), and a long-running worker process
 * could do the same. ResetInterface (auto-tagged kernel.reset by
 * FrameworkExtension's autoconfiguration) empties the cache between requests
 * so a stale entry — possibly bound to an EntityManager that has since been
 * reset — can never be served to a later one.
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
