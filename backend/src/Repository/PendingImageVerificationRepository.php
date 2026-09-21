<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        $entries = $this->createQueryBuilder('e')
            ->andWhere('e.image.verifyAttempts IS NOT NULL')
            ->orderBy('e.image.verifyAttempts', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
