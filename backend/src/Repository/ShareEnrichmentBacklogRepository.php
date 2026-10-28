<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Service\Queue\Model\QueueBacklogModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * What the share-enrichment queues still have to do, one backlog per kind.
 *
 * @extends ServiceEntityRepository<ShareEnrichment>
 */
final class ShareEnrichmentBacklogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareEnrichment::class);
    }

    public function backlogOf(ShareEnrichmentKind $kind): QueueBacklogModel
    {
        /** @var array{waiting: int|string, oldest: ?string} $row */
        $row = $this->createQueryBuilder('s')
            ->select('COUNT(s.id) AS waiting', 'MIN(s.queuedAt) AS oldest')
            ->andWhere('s.kind = :kind')->setParameter('kind', $kind)
            ->andWhere('s.doneAt IS NULL')
            ->getQuery()
            ->getSingleResult();

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
}
