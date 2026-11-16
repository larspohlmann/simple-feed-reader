<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Subscription;
use App\Entity\TrendingStoryEntry;
use App\Enum\InclusionSurface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A followed Trending story's entries: its whole history, seen through the feeds the reader follows and includes in
 * Trending, the rule the followed story's new count uses.
 *
 * @extends ServiceEntityRepository<Entry>
 */
final class TrendingStoryEntryListRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly DuplicateCollapseDql $collapse,
        private readonly EntryProjection $projection,
        private readonly EntryScopePredicates $scopes,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /** @return list<EntryListRow> */
    public function listMembers(TrendingStoryListQuery $query): array
    {
        $ordering = $query->ordering();
        $qb = $this->projection->orderedBy($this->projection->rowQueryBuilder($query->userId), $ordering)
            ->setMaxResults($query->limit)
            ->setParameter('story', $query->storyId);
        $this->applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $this->applyMembership(...), $query->userId);
        if ($query->onlyUnread) {
            $this->scopes->unread($qb, EntryAliases::primary());
        }
        $this->projection->applyCursor($qb, $query->cursor, $ordering);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /**
     * The given entries that, or whose collapsed copy the reader sees, joined the story after $since.
     *
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    public function joinedAfter(TrendingStoryListQuery $query, array $entryIds, \DateTimeImmutable $since): array
    {
        if ($entryIds === []) {
            return [];
        }

        $joined = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(TrendingStoryEntry::class, 'joined')
            ->join('joined.entry', 'joinedEntry')
            ->join(
                Subscription::class,
                'joinedReader',
                'ON',
                'joinedReader.feed = joinedEntry.feed AND joinedReader.user = :user',
            )
            ->andWhere('joined.story = :story')
            ->andWhere('joined.joinedAt > :since')
            ->andWhere('joinedEntry = e OR joinedEntry.location.urlHash = e.location.urlHash');
        InclusionScopeDql::restrict($joined, 'joinedReader', InclusionSurface::Trending);

        $qb = $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.id IN (:entryIds)')
            ->andWhere('EXISTS (' . $joined->getDQL() . ')')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('user', $query->userId)
            ->setParameter('story', $query->storyId)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);

        return $this->projection->scalarIds($qb);
    }

    /** On the primary alias and inside the collapse subquery alike, or a collapsed copy punches a hole in the page. */
    private function applyMembership(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $member = 'storyMember' . ucfirst($aliases->entry);
        $qb->andWhere(\sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.entry = %3$s AND %2$s.story = :story)',
            TrendingStoryEntry::class,
            $member,
            $aliases->entry,
        ));
        InclusionScopeDql::restrict($qb, $aliases->subscription, InclusionSurface::Trending);
    }
}
