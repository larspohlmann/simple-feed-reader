<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PendingPostEnrichment> */
final class PendingPostEnrichmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingPostEnrichment::class);
    }

    public function deleteQueuedBefore(Feed $feed, \DateTimeImmutable $cutoff): void
    {
        $this->getEntityManager()->createQuery(sprintf(
            'DELETE FROM %s pending WHERE pending.queuedAt < :cutoff'
            . ' AND IDENTITY(pending.entry) IN'
            . ' (SELECT queuedEntry.id FROM %s queuedEntry WHERE queuedEntry.feed = :feed)',
            PendingPostEnrichment::class,
            Entry::class,
        ))
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->setParameter('feed', $feed)
            ->execute();
    }

    public function hasPendingForFeed(Feed $feed): bool
    {
        return $this->createQueryBuilder('pending')
            ->select('pending.id')
            ->innerJoin('pending.entry', 'queuedEntry')
            ->andWhere('queuedEntry.feed = :feed')
            ->setParameter('feed', $feed)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult() !== [];
    }

    /** @return list<PendingPostEnrichment> */
    public function findOldestForFeed(Feed $feed, int $limit): array
    {
        /** @var list<PendingPostEnrichment> $rows */
        $rows = $this->createQueryBuilder('pending')
            ->innerJoin('pending.entry', 'queuedEntry')
            ->addSelect('queuedEntry')
            ->andWhere('queuedEntry.feed = :feed')
            ->setParameter('feed', $feed)
            ->orderBy('pending.queuedAt', 'ASC')
            ->addOrderBy('queuedEntry.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int> those among them still waiting for the AppView
     */
    public function queuedEntryIdsAmong(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<int|string> $queued */
        $queued = $this->createQueryBuilder('pending')
            ->select('IDENTITY(pending.entry)')
            ->andWhere('pending.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $queued);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<PendingPostEnrichment> the rows of those still waiting for the AppView, oldest queued first
     */
    public function findForEntries(array $entries): array
    {
        /** @var list<PendingPostEnrichment> $rows */
        $rows = $this->createQueryBuilder('pending')
            ->andWhere('pending.entry IN (:entries)')
            ->setParameter('entries', $entries)
            ->orderBy('pending.queuedAt', 'ASC')
            ->addOrderBy('pending.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
