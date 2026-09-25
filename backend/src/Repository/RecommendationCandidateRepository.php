<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** The recommendation candidate pool, gated by the reader's For You subscriptions and named by their customTitle. */
final readonly class RecommendationCandidateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DuplicateCollapseDql $collapse,
    ) {
    }

    /**
     * The newest entries since $since the reader has not favorited, kept or viewed, one copy per duplicate group.
     * Read entries stay eligible (#386).
     *
     * @return list<TitledEntry>
     */
    public function newestPool(int $userId, \DateTimeImmutable $since, int $poolSize): array
    {
        $qb = $this->candidateQueryBuilder($userId)
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setParameter('since', $since)
            ->setParameter('notInteracted', false, Types::BOOLEAN);

        $this->poolScope($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $this->poolScope(...), $userId);
        $qb->setMaxResults($poolSize);

        return $this->titledEntries($qb);
    }

    /**
     * No unread filter, so a resumed run retries its exact snapshot; a pruned or unsubscribed entry drops out.
     *
     * @param non-empty-list<int> $entryIds
     *
     * @return list<TitledEntry>
     */
    public function forIds(int $userId, array $entryIds): array
    {
        $qb = $this->candidateQueryBuilder($userId)
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds);

        return $this->titledEntries($qb);
    }

    /**
     * @param non-empty-list<int> $entryIds
     *
     * @return array{total: int, oldest: ?string, newest: ?string}
     */
    public function span(int $userId, array $entryIds): array
    {
        /** @var array{total: int, oldest: ?string, newest: ?string} $row */
        $row = $this->candidateQueryBuilder($userId)
            ->select('COUNT(e.id) AS total', 'MIN(e.effectiveDate) AS oldest', 'MAX(e.effectiveDate) AS newest')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getSingleResult();

        return $row;
    }

    /** Shared by the outer query and the collapse semi-join, so the two cannot drift and reopen a hole (#496). */
    private function poolScope(QueryBuilder $inner, EntryAliases $aliases): void
    {
        $inner->andWhere(\sprintf('%s.includeInForYou = true', $aliases->subscription))
            ->andWhere(\sprintf(
                '(%1$s.isFavorite = :notInteracted OR %1$s.isFavorite IS NULL)'
                . ' AND (%1$s.isKept = :notInteracted OR %1$s.isKept IS NULL)'
                . ' AND (%1$s.isViewed = :notInteracted OR %1$s.isViewed IS NULL)',
                $aliases->state,
            ))
            ->andWhere(\sprintf('%s.effectiveDate >= :since', $aliases->entry));
    }

    private function candidateQueryBuilder(int $userId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e', 'f', 's.customTitle AS customTitle')
            ->from(Entry::class, 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('s.includeInForYou = true')
            ->setParameter('user', $userId);
    }

    /** @return list<TitledEntry> */
    private function titledEntries(QueryBuilder $qb): array
    {
        /** @var list<array{0: Entry, customTitle: ?string}> $rows the joined feed folds into the Entry graph */
        $rows = $qb->getQuery()->getResult();

        return array_map(static fn (array $row): TitledEntry => TitledEntry::of($row[0], $row['customTitle']), $rows);
    }
}
