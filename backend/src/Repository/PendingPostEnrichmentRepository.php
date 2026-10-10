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
}
