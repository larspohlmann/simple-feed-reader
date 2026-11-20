<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bulk read-flips of existing entry-state rows, and the other copies of an item a read must reach; creating a
 * missing row is the caller's job.
 */
final readonly class EntryReadMarkRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param list<int> $feedIds */
    public function hideUnreadInFeedsUntil(ReadMarking $marking, array $feedIds, \DateTimeImmutable $until): void
    {
        $this->entityManager->createQuery(sprintf(
            'UPDATE %s es SET es.isHidden = :true, es.hiddenAt = :now
                 WHERE es.user = :user AND es.isHidden = :false
                 AND es.entry IN (
                     SELECT e.id FROM %s e
                     WHERE e.feed IN (:feeds) AND e.dates.effectiveDate <= :until
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
        $this->entityManager->createQuery(
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

    /**
     * @param list<int> $entryIds
     *
     * @return list<int> the caller's subscribed entries sharing a canonical item with $entryIds, those excluded
     */
    public function findCopyIdsForUser(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT e.id')
            ->from(Entry::class, 'source')
            ->join(Entry::class, 'e', 'ON', 'e.canonicalItem = source.canonicalItem')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('source.id IN (:ids)')
            ->andWhere('e.id NOT IN (:ids)')
            ->setParameter('user', $userId)
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /**
     * The copies a sweep of $feedIds up to $until must still reach: the caller's unread subscribed entries outside
     * those feeds whose canonical item has an entry inside them dated no later than $until.
     *
     * @param list<int> $feedIds
     *
     * @return list<int>
     */
    public function findUnreadCopyIdsOutsideFeeds(int $userId, array $feedIds, \DateTimeImmutable $until): array
    {
        if ($feedIds === []) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT e.id')
            ->from(Entry::class, 'swept')
            ->join(Entry::class, 'e', 'ON', 'e.canonicalItem = swept.canonicalItem')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere('swept.feed IN (:feeds)')
            ->andWhere('swept.dates.effectiveDate <= :until')
            ->andWhere('e.feed NOT IN (:feeds)')
            ->andWhere(UnreadDql::predicate())
            ->setParameter('user', $userId)
            ->setParameter('feeds', $feedIds)
            ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
            ->setParameter('notHidden', false, Types::BOOLEAN)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }
}
