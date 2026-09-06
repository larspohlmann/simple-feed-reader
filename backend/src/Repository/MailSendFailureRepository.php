<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailSendFailure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The failure log stays bounded: {@see self::add()} prunes to the newest
 * RETENTION rows on every write, so a proxy outage sending every five minutes
 * for hours cannot grow it without limit (#882).
 *
 * @extends ServiceEntityRepository<MailSendFailure>
 */
final class MailSendFailureRepository extends ServiceEntityRepository
{
    public const int RETENTION = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailSendFailure::class);
    }

    public function add(MailSendFailure $failure): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($failure);
        $manager->flush();

        $this->pruneToRetention();
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

    private function pruneToRetention(): void
    {
        /** @var list<int> $ids */
        $ids = array_column(
            $this->createQueryBuilder('f')
                ->select('f.id AS id')
                ->orderBy('f.createdAt', 'DESC')
                ->addOrderBy('f.id', 'DESC')
                ->getQuery()
                ->getArrayResult(),
            'id',
        );

        $overflow = array_slice($ids, self::RETENTION);
        if ([] === $overflow) {
            return;
        }

        $this->createQueryBuilder('f')
            ->delete()
            ->where('f.id IN (:ids)')
            ->setParameter('ids', $overflow)
            ->getQuery()
            ->execute();
    }
}
