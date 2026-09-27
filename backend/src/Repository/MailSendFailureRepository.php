<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailSendFailure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailSendFailure>
 */
final class MailSendFailureRepository extends ServiceEntityRepository
{
    public const int RETENTION = 50;

    public function __construct(ManagerRegistry $registry, private readonly RowIds $rowIds)
    {
        parent::__construct($registry, MailSendFailure::class);
    }

    public function add(MailSendFailure $failure): void
    {
        $this->getEntityManager()->persist($failure);
    }

    public function deleteAll(): void
    {
        $this->createQueryBuilder('f')->delete()->getQuery()->execute();
    }

    /** @return list<MailSendFailure> newest first */
    public function recent(int $limit): array
    {
        /** @var list<MailSendFailure> $rows */
        $rows = $this->createQueryBuilder('f')
            ->orderBy('f.createdAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setMaxResults(min($limit, self::RETENTION))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    /** Keeps the newest RETENTION rows: an outage retrying every five minutes must not grow the log (#882). */
    public function pruneToRetention(): void
    {
        $ids = $this->rowIds->selectedBy(
            $this->createQueryBuilder('f')
                ->select('f.id AS id')
                ->orderBy('f.createdAt', 'DESC')
                ->addOrderBy('f.id', 'DESC'),
        );

        $this->rowIds->delete(MailSendFailure::class, array_slice($ids, self::RETENTION));
    }
}
