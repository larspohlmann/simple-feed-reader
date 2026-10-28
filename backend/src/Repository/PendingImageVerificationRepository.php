<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Service\Queue\Model\QueueBacklogModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The background image verify's work queue: entries whose image is pending verification.
 *
 * @extends ServiceEntityRepository<Entry>
 */
final class PendingImageVerificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * @return list<Entry>
     */
    public function findPendingImageVerification(int $limit): array
    {
        /** @var list<Entry> $entries */
        $entries = $this->pendingQuery()
            ->orderBy('e.image.verifyAttempts', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function backlog(): QueueBacklogModel
    {
        /** @var array{waiting: int|string, oldest: ?string} $row */
        $row = $this->pendingQuery()
            ->select('COUNT(e.id) AS waiting', 'MIN(e.createdAt) AS oldest')
            ->getQuery()
            ->getSingleResult();

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }

    private function pendingQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.image.verifyAttempts IS NOT NULL');
    }
}
