<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/** Bulk read-flips of existing entry-state rows; creating a missing row is the caller's job. */
final readonly class EntryReadMarkRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @param list<int> $feedIds */
    public function hideUnreadInFeedsUntil(ReadMarking $marking, array $feedIds, \DateTimeImmutable $until): void
    {
        $this->em->createQuery(sprintf(
            'UPDATE %s es SET es.isHidden = :true, es.hiddenAt = :now
                 WHERE es.user = :user AND es.isHidden = :false
                 AND es.entry IN (
                     SELECT e.id FROM %s e
                     WHERE e.feed IN (:feeds) AND e.effectiveDate <= :until
                 )',
            EntryState::class,
            Entry::class,
        ))
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('now', $marking->at, Types::DATETIME_IMMUTABLE)
            ->setParameter('user', $marking->userId)
            ->setParameter('feeds', $feedIds)
            ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
            ->execute();
    }

    /** @param list<int> $entryIds */
    public function hideUnreadAmong(ReadMarking $marking, array $entryIds): void
    {
        $this->em->createQuery(
            'UPDATE ' . EntryState::class . ' es
             SET es.isHidden = :true, es.hiddenAt = :now
             WHERE es.user = :user AND es.isHidden = :false AND IDENTITY(es.entry) IN (:ids)',
        )
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('now', $marking->at, Types::DATETIME_IMMUTABLE)
            ->setParameter('user', $marking->userId)
            ->setParameter('ids', $entryIds)
            ->execute();
    }
}
