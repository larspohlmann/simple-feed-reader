<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The two database reads the engine-backed saved-search badge walk needs
 * (App\Service\Search\IndexedSavedSearchBadges): the engine returns ids only,
 * so a keyset cursor's sort instant and the unread/collapse/subscription
 * narrowing both come from here. Split out of EntryListRepository so that
 * class's public surface stays the entry-list projection it already is
 * (PHPMD TooManyPublicMethods) rather than growing a second, unrelated
 * responsibility.
 */
final class SavedSearchBadgeCandidateRepository extends AbstractEntryProjectionRepository
{
    /**
     * Ids per statement, well under the smallest placeholder limit in play.
     * The #496 collapse is scoped per chunk, so two copies of one article
     * split across a chunk boundary can both survive — the accepted trade
     * for the packet limit.
     */
    public const int ID_FILTER_CHUNK = 5000;

    /** @param positive-int $idFilterChunk */
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryScopePredicates $scope,
        private readonly DuplicateCollapseDql $collapse,
        private readonly int $idFilterChunk = self::ID_FILTER_CHUNK,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /**
     * The stored effectiveDate of each given entry id — the keyset cursor's
     * sort instant, since the engine's own result carries ids only. No user
     * scope: called only on ids a search already narrowed to feeds the
     * caller may see.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, \DateTimeImmutable> entry id => effectiveDate
     */
    public function effectiveDatesByIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{id: int, effectiveDate: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.id', 'e.effectiveDate')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[$row['id']] = $row['effectiveDate'];
        }

        return $dates;
    }

    /**
     * The given candidate ids narrowed to unread, subscription-scoped and
     * #496-collapsed — the badge walk's one database pass over its
     * engine-returned candidates. Chunked at $idFilterChunk; the
     * subscription join is the IDOR gate, same as
     * EntryListRepository::rowsByIdsForUser.
     *
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    public function unreadCollapsedSubscribedIds(array $entryIds, int $userId): array
    {
        $ids = [];
        foreach (array_chunk($entryIds, $this->idFilterChunk) as $chunk) {
            array_push($ids, ...$this->unreadCollapsedSubscribedIdsChunk($chunk, $userId));
        }

        return $ids;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    private function unreadCollapsedSubscribedIdsChunk(array $entryIds, int $userId): array
    {
        $applyScope = function (QueryBuilder $qb, EntryAliases $aliases) use ($entryIds): void {
            $this->scope->applyIds($qb, $aliases, $entryIds);
        };

        $qb = $this->unreadEntriesQueryBuilder($userId)->select('e.id');
        $applyScope($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyScope, $userId);

        return $this->scalarIds($qb);
    }
}
