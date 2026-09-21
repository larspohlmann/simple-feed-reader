<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The background image verify's work queue: entries whose image was stored
 * optimistically and not yet confirmed. A focused repository so EntryRepository
 * stays within its method-count budget.
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
            ->andWhere('e.image.url IS NOT NULL')
            ->andWhere('e.image.checkedAt IS NULL')
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
