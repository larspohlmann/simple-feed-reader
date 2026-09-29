<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The combined saved-search list and every other saved-search read, over the membership table.
 *
 * @extends ServiceEntityRepository<Entry>
 */
final class SavedSearchEntryRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly DuplicateCollapseDql $collapse,
        private readonly EntryProjection $projection,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Every member of any of the caller's searches, keyset-paged, with the unread test in the LIMIT's own statement.
     * EXISTS, not a join, so an entry in several searches is one row without DISTINCT.
     *
     * @return list<EntryListRow>
     */
    public function listMembers(SavedSearchListQuery $query): array
    {
        if ($query->savedSearchIds === []) {
            return [];
        }

        $ordering = $query->ordering();
        $qb = $this->projection->orderedBy($this->projection->rowQueryBuilder($query->userId), $ordering)
            ->setMaxResults($query->limit);
        $this->restrictToMembers($qb, $query->savedSearchIds, $query->userId);

        if ($query->onlyUnread) {
            $qb->andWhere(UnreadDql::predicate())->setParameter('notHidden', false, Types::BOOLEAN);
        }

        $this->projection->applyCursor($qb, $query->cursor, $ordering);

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

        // A join, not restrictToMembers(): this read projects the search id per
        // row, so only the collapse subquery takes the EXISTS scope.
        $qb = $this->projection->unreadEntriesQueryBuilder($userId)
            ->select('e.id AS id', 'ss.id AS searchId')
            ->join(SavedSearchEntry::class, 'sse', 'ON', 'sse.entry = e')
            ->join('sse.savedSearch', 'ss')
            ->andWhere('ss.id IN (:searchIds)')
            ->andWhere('ss.user = :user')
            ->setParameter('searchIds', $savedSearchIds)
            ->orderBy('e.id', 'ASC');
        $this->collapse->apply($qb, self::applyMembership(...), $userId);

        /** @var list<array{id: int, searchId: int}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            $idsBySearch[(int) $row['searchId']][] = (int) $row['id'];
        }

        return $idsBySearch;
    }

    /**
     * Saved-search id => how many members, read or not, the caller may see.
     * Every requested id keeps its key.
     *
     * @param list<int> $savedSearchIds
     *
     * @return array<int, int>
     */
    public function memberCountsBySavedSearch(int $userId, array $savedSearchIds): array
    {
        $countsBySearch = array_fill_keys($savedSearchIds, 0);
        if ($savedSearchIds === []) {
            return $countsBySearch;
        }

        /** @var list<array{searchId: int, memberCount: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('ss.id AS searchId', 'COUNT(e.id) AS memberCount')
            ->from(SavedSearchEntry::class, 'sse')
            ->join('sse.savedSearch', 'ss')
            ->join('sse.entry', 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = ss.user')
            ->andWhere('ss.id IN (:searchIds)')
            ->andWhere('ss.user = :user')
            ->groupBy('ss.id')
            ->setParameter('searchIds', $savedSearchIds)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getScalarResult();
        foreach ($rows as $row) {
            $countsBySearch[$row['searchId']] = (int) $row['memberCount'];
        }

        return $countsBySearch;
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

        $qb = $this->projection->unreadEntriesQueryBuilder($userId)
            ->select('e.id')
            ->andWhere('e.effectiveDate <= :until')
            ->setParameter('until', $until);
        $this->restrictToMembers($qb, $savedSearchIds, $userId);

        return $this->projection->scalarIds($qb);
    }

    /**
     * One search's unread members newer than $since, newest first: the digest's window.
     *
     * @return list<int>
     */
    public function unreadMemberIdsForUserSince(int $userId, int $savedSearchId, \DateTimeImmutable $since): array
    {
        $qb = $this->projection->unreadEntriesQueryBuilder($userId)
            ->select('e.id')
            ->andWhere('e.effectiveDate > :since')
            ->setParameter('since', $since);
        $this->restrictToMembers($qb, [$savedSearchId], $userId);

        return $this->projection->scalarIds($this->projection->newestFirst($qb));
    }

    /**
     * Entry id => every owned saved search it is a member of, newest-saved first
     * (search id DESC), each as {id, slug, term} — the pills a card shows. One
     * query for a page; entries in no search are absent.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, list<array{id: int, slug: string, term: string}>>
     */
    public function savedSearchesByEntry(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int, id: int, slug: string, term: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(sse.entry) AS entryId', 'ss.id AS id', 'ss.slug AS slug', 'ss.term AS term')
            ->from(SavedSearchEntry::class, 'sse')
            ->join('sse.savedSearch', 'ss')
            ->andWhere('sse.entry IN (:entryIds)')
            ->andWhere('ss.user = :user')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('user', $userId)
            ->orderBy('ss.id', 'DESC')
            ->getQuery()
            ->getScalarResult();

        $byEntry = [];
        foreach ($rows as $row) {
            $byEntry[(int) $row['entryId']][] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'term' => (string) $row['term'],
            ];
        }

        return $byEntry;
    }

    /**
     * Keeps only members of the given searches, on the primary alias and inside
     * the collapse subquery alike — the two scopes must agree, or a collapsed
     * copy punches a hole in the page (see DuplicateCollapseDql).
     *
     * @param non-empty-list<int> $savedSearchIds
     */
    private function restrictToMembers(QueryBuilder $qb, array $savedSearchIds, int $userId): void
    {
        $qb->setParameter('searchIds', $savedSearchIds);
        self::applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, self::applyMembership(...), $userId);
    }

    private static function applyMembership(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->andWhere(self::memberOfAnySearch($aliases));
    }

    /**
     * "A member of one of :searchIds, and that search belongs to :user", for
     * any entry alias; both parameters are bound once on the outer builder,
     * which the collapse subquery shares.
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
