<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Retention: deletes entries older than 90 days unless any user marked them
 * favorite or kept. Selects ids first, then deletes in chunks — portable
 * across SQLite and MySQL. Read-state rows die with their entry via the DB
 * FK cascade.
 */
final class EntryPruner
{
    private const RETENTION_DAYS = 90;
    private const DELETE_CHUNK_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function prune(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS));

        /** @var list<int> $ids */
        $ids = $this->em->createQuery(sprintf(
            'SELECT e.id FROM %s e
             WHERE COALESCE(e.publishedAt, e.createdAt) < :cutoff
             AND NOT EXISTS (
                 SELECT IDENTITY(s.user) FROM %s s
                 WHERE s.entry = e AND (s.isFavorite = :true OR s.isKept = :true)
             )',
            Entry::class,
            EntryState::class,
        ))
            ->setParameter('cutoff', $cutoff)
            ->setParameter('true', true, \Doctrine\DBAL\Types\Types::BOOLEAN)
            ->getSingleColumnResult();

        if ($ids === []) {
            return 0;
        }

        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.id IN (:ids)', Entry::class))
                ->setParameter('ids', $chunk)
                ->execute();
        }

        return \count($ids);
    }
}
