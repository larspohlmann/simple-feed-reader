<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\SavedSearchEntry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The combined saved-search list (#769) and every other saved-search read,
 * over the membership table (#1116).
 */
final class SavedSearchEntryRepository extends AbstractEntryProjectionRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly DuplicateCollapseDql $collapse,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /**
     * The combined list over the membership table: every member of any of the
     * caller's searches, newest first, keyset-paged, the unread test inside
     * the same statement as the LIMIT. EXISTS rather than a join, so an entry
     * in several searches is one row without a DISTINCT.
     *
     * @return list<EntryListRow>
     */
    public function listMembers(SavedSearchListQuery $query): array
    {
        if ($query->savedSearchIds === []) {
            return [];
        }

        $qb = $this->newestFirst($this->rowQueryBuilder($query->userId))
            ->setMaxResults($query->limit)
            ->setParameter('searchIds', $query->savedSearchIds);
        $applyMembership = static function (QueryBuilder $qb, EntryAliases $aliases): void {
            $qb->andWhere(self::memberOfAnySearch($aliases));
        };
        $applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyMembership, $query->userId);

        if ($query->onlyUnread) {
            $qb->andWhere(UnreadDql::predicate())->setParameter('notHidden', false, Types::BOOLEAN);
        }

        $this->applyCursor($qb, $query->cursor, EntryListSort::PublishedDate);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /**
     * Saved-search id => the ids of its unread members the caller may see —
     * one query for every badge. Every requested id keeps its key.
     *
     * @param list<int> $savedSearchIds
     *
     * @return array<int, list<int>>
     */
    public function unreadMemberIdsBySavedSearch(int $userId, array $savedSearchIds): array
    {
        $idsBySearch = array_fill_keys($savedSearchIds, []);
        if ($savedSearchIds === []) {
            return $idsBySearch;
        }

        $qb = $this->unreadEntriesQueryBuilder($userId)
            ->select('e.id AS id', 'ss.id AS searchId')
            ->join(SavedSearchEntry::class, 'sse', 'ON', 'sse.entry = e')
            ->join('sse.savedSearch', 'ss')
            ->andWhere('ss.id IN (:searchIds)')
            ->andWhere('ss.user = :user')
            ->setParameter('searchIds', $savedSearchIds)
            ->orderBy('e.id', 'ASC');
        $this->collapse->apply($qb, static function (QueryBuilder $qb, EntryAliases $aliases): void {
            $qb->andWhere(self::memberOfAnySearch($aliases));
        }, $userId);

        /** @var list<array{id: int, searchId: int}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            $idsBySearch[(int) $row['searchId']][] = (int) $row['id'];
        }

        return $idsBySearch;
    }

    /**
     * The ids the combined mark-read flips: every unread member of any of the
     * given searches no newer than $until, subscription-gated and collapsed.
     *
     * @param list<int> $savedSearchIds
     *
     * @return list<int>
     */
    public function unreadMemberIdsUpTo(int $userId, array $savedSearchIds, \DateTimeImmutable $until): array
    {
        if ($savedSearchIds === []) {
            return [];
        }

        $qb = $this->unreadEntriesQueryBuilder($userId)
            ->select('e.id')
            ->andWhere('e.effectiveDate <= :until')
            ->setParameter('until', $until)
            ->setParameter('searchIds', $savedSearchIds);
        $applyMembership = static function (QueryBuilder $qb, EntryAliases $aliases): void {
            $qb->andWhere(self::memberOfAnySearch($aliases));
        };
        $applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyMembership, $userId);

        return $this->scalarIds($qb);
    }

    /**
     * One search's unread members newer than $since, newest first — the
     * digest's window (#636).
     *
     * @return list<int>
     */
    public function unreadMemberIdsSince(int $savedSearchId, int $userId, \DateTimeImmutable $since): array
    {
        $qb = $this->unreadEntriesQueryBuilder($userId)
            ->select('e.id')
            ->andWhere('e.effectiveDate > :since')
            ->setParameter('since', $since)
            ->setParameter('searchIds', [$savedSearchId]);
        $applyMembership = static function (QueryBuilder $qb, EntryAliases $aliases): void {
            $qb->andWhere(self::memberOfAnySearch($aliases));
        };
        $applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyMembership, $userId);

        return $this->scalarIds($this->newestFirst($qb));
    }

    /**
     * Entry id => the first of the given searches (in the order given — the
     * sidebar's) that it is a member of. Entries in none are absent.
     *
     * @param list<int> $entryIds
     * @param list<int> $savedSearchIdsInSidebarOrder
     *
     * @return array<int, int>
     */
    public function firstMatchingSavedSearchIds(array $entryIds, array $savedSearchIdsInSidebarOrder): array
    {
        if ($entryIds === [] || $savedSearchIdsInSidebarOrder === []) {
            return [];
        }

        /** @var list<array{entryId: int, searchId: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(sse.entry) AS entryId', 'IDENTITY(sse.savedSearch) AS searchId')
            ->from(SavedSearchEntry::class, 'sse')
            ->andWhere('sse.entry IN (:entryIds)')
            ->andWhere('sse.savedSearch IN (:searchIds)')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('searchIds', $savedSearchIdsInSidebarOrder)
            ->getQuery()
            ->getScalarResult();

        $rank = array_flip($savedSearchIdsInSidebarOrder);
        $first = [];
        foreach ($rows as $row) {
            $entryId = (int) $row['entryId'];
            $searchId = (int) $row['searchId'];
            if (!isset($first[$entryId]) || $rank[$searchId] < $rank[$first[$entryId]]) {
                $first[$entryId] = $searchId;
            }
        }

        return $first;
    }

    /**
     * "This entry is a member of one of :searchIds, and that search belongs
     * to :user." Alias-parameterised so the collapse subquery can apply the
     * same scope to its own entry alias; both parameters are bound once on
     * the outer builder, which the subquery shares.
     */
    private static function memberOfAnySearch(EntryAliases $aliases): string
    {
        $member = 'member' . ucfirst($aliases->entry);
        $search = 'search' . ucfirst($aliases->entry);

        return \sprintf(
            'EXISTS (SELECT 1 FROM %s %s JOIN %s.savedSearch %s '
            . 'WHERE %s.entry = %s AND %s.id IN (:searchIds) AND %s.user = :user)',
            SavedSearchEntry::class,
            $member,
            $member,
            $search,
            $member,
            $aliases->entry,
            $search,
            $search,
        );
    }
}
