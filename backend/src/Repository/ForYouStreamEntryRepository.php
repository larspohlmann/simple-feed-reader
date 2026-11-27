<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\ForYouStreamEntry;
use App\Enum\InclusionSurface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The For you stream's list and mark-read reads: the entries the sweep matched for the caller, while the
 * subscription, its network and its tags are switched in for For you.
 *
 * @extends ServiceEntityRepository<Entry>
 */
final class ForYouStreamEntryRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly DuplicateCollapseDql $collapse,
        private readonly EntryProjection $projection,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /** @return list<EntryListRow> */
    public function listMembers(int $userId, EntryListPage $page): array
    {
        $ordering = $page->ordering();
        $qb = $this->projection->orderedBy($this->projection->rowQueryBuilder($userId), $ordering)
            ->setMaxResults($page->limit);
        $this->restrictToMembers($qb, $userId);

        if ($page->onlyUnread) {
            $qb->andWhere(UnreadDql::predicate())->setParameter('notHidden', false, Types::BOOLEAN);
        }

        $this->projection->applyCursor($qb, $page->cursor, $ordering);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /** @return list<int> */
    public function findUnreadMemberIdsUpTo(int $userId, \DateTimeImmutable $until): array
    {
        $qb = $this->projection->unreadEntriesQueryBuilder($userId)
            ->select('e.id')
            ->andWhere('e.dates.effectiveDate <= :until')
            ->setParameter('until', $until);
        $this->restrictToMembers($qb, $userId);

        return $this->projection->scalarIds($qb);
    }

    private function restrictToMembers(QueryBuilder $qb, int $userId): void
    {
        $this->applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, CollapseScope::fromScope($this->applyMembership(...)), $userId);
    }

    private function applyMembership(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $member = 'streamMember' . ucfirst($aliases->entry);
        $qb->andWhere(\sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.entry = %s AND %s.user = :user)',
            ForYouStreamEntry::class,
            $member,
            $member,
            $aliases->entry,
            $member,
        ));
        InclusionScopeDql::restrict($qb, $aliases->subscription, InclusionSurface::ForYou);
    }
}
