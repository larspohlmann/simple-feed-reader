<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** The retention passes' queries; EntryPruner picks the feeds and chunks the deletes. */
final readonly class RetentionRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<int> */
    public function feedIdsFetchedBefore(\DateTimeImmutable $cutoff): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->em->createQuery(sprintf(
            'SELECT DISTINCT IDENTITY(e.feed) FROM %s e WHERE e.createdAt < :cutoff',
            Entry::class,
        ))
            ->setParameter('cutoff', $cutoff)
            ->getSingleColumnResult();

        return $feedIds;
    }

    /** @return list<int> */
    public function feedIdsOverCap(int $cap): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->em->createQuery(sprintf(
            'SELECT IDENTITY(e.feed) FROM %s e GROUP BY e.feed HAVING COUNT(e.id) > :cap',
            Entry::class,
        ))
            ->setParameter('cap', $cap)
            ->getSingleColumnResult();

        return $feedIds;
    }

    /** @return list<int> the feed's unprotected entries older than its `keep`-th newest */
    public function idsPastBoundary(int $feedId, int $keep): array
    {
        return self::idsOf($this->deletablePastBoundary($feedId, $keep));
    }

    /** @return list<int> */
    public function staleIdsPastBoundary(int $feedId, int $keep, \DateTimeImmutable $cutoff): array
    {
        $query = $this->deletablePastBoundary($feedId, $keep)
            ?->andWhere('e.createdAt < :cutoff')?->setParameter('cutoff', $cutoff);

        return self::idsOf($query);
    }

    /** @param list<int> $ids */
    public function deleteEntries(array $ids): void
    {
        $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.id IN (:ids)', Entry::class))
            ->setParameter('ids', $ids)
            ->execute();
    }

    /** A completed run whose items were all pruned; pending and running runs legitimately have none yet. */
    public function deleteEmptyCompletedRuns(): void
    {
        $this->em->createQuery(sprintf(
            'DELETE FROM %s r WHERE r.status = :completed AND NOT EXISTS (SELECT i.id FROM %s i WHERE i.run = r)',
            RecommendationRun::class,
            RecommendationItem::class,
        ))
            ->setParameter('completed', RecommendationRun::STATUS_COMPLETED)
            ->execute();
    }

    /** Null when the feed holds no more than `keep` entries. */
    private function deletablePastBoundary(int $feedId, int $keep): ?QueryBuilder
    {
        $boundary = $this->rankBoundaryBeyond($feedId, $keep);
        if (null === $boundary) {
            return null;
        }

        return $this->em->createQueryBuilder()
            ->select('e.id')
            ->from(Entry::class, 'e')
            ->where('e.feed = :feed')
            ->andWhere($this->pastBoundaryDql())
            ->andWhere($this->notProtectedDql())
            ->setParameter('feed', $feedId)
            ->setParameter('boundaryCreatedAt', $boundary->createdAt)
            ->setParameter('boundaryId', $boundary->id)
            ->setParameter('true', true, Types::BOOLEAN);
    }

    /**
     * Ranks protected entries too, so they cannot shift the boundary. setFirstResult() walks `keep` rows of
     * idx_entry_feed_created and stops; a correlated COUNT re-scanned the whole feed per row (#384).
     */
    private function rankBoundaryBeyond(int $feedId, int $keep): ?EntryRankBoundary
    {
        /** @var list<array{createdAt: \DateTimeImmutable, id: int}> $rows */
        $rows = $this->em->createQuery(sprintf(
            'SELECT e.createdAt AS createdAt, e.id AS id FROM %s e
             WHERE e.feed = :feed
             ORDER BY e.createdAt DESC, e.id DESC',
            Entry::class,
        ))
            ->setParameter('feed', $feedId)
            ->setFirstResult($keep - 1)
            ->setMaxResults(1)
            ->getResult();

        if ($rows === []) {
            return null;
        }

        return new EntryRankBoundary($rows[0]['createdAt'], $rows[0]['id']);
    }

    /** Strictly older than the boundary: a keyset range that idx_entry_feed_created serves. */
    private function pastBoundaryDql(): string
    {
        return '(e.createdAt < :boundaryCreatedAt
                 OR (e.createdAt = :boundaryCreatedAt AND e.id < :boundaryId))';
    }

    /** An entry is protected iff any user favorited or kept it. */
    private function notProtectedDql(): string
    {
        return sprintf(
            'NOT EXISTS (
                SELECT IDENTITY(s.user) FROM %s s
                WHERE s.entry = e AND (s.isFavorite = :true OR s.isKept = :true)
            )',
            EntryState::class,
        );
    }

    /** @return list<int> */
    private static function idsOf(?QueryBuilder $query): array
    {
        if (null === $query) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $query->getQuery()->getSingleColumnResult();

        return $ids;
    }
}
