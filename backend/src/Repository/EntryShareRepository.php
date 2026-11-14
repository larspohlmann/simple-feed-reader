<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryShare;
use App\Entity\Subscription;
use App\Service\Operations\Backup\RestoreEntryShares\RestoreEntrySharesInterface;
use App\Service\Library\Reading\EntryShareStore\EntryShareStoreInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryShare>
 */
final class EntryShareRepository extends ServiceEntityRepository implements
    EntryShareStoreInterface,
    RestoreEntrySharesInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryShare::class);
    }

    /** Plain SQL, not ORM persist, which would race two shares of one entry into a duplicate-key flush. */
    public function record(int $userId, int $entryId, \DateTimeImmutable $when): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $inserted = $connection->executeStatement(
            sprintf(
                'INSERT %s INTO entry_share (user_id, entry_id, shared_at)'
                . ' SELECT s.user_id, e.id, ? FROM entry e'
                . ' JOIN subscription s ON s.feed_id = e.feed_id AND s.user_id = ?'
                . ' WHERE e.id = ?',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            [$when, $userId, $entryId],
            [Types::DATETIME_IMMUTABLE, Types::INTEGER, Types::INTEGER],
        );
        if ($inserted > 0) {
            return;
        }

        $connection->executeStatement(
            'UPDATE entry_share SET shared_at = ? WHERE user_id = ? AND entry_id = ? AND EXISTS ('
            . 'SELECT 1 FROM entry e JOIN subscription s ON s.feed_id = e.feed_id AND s.user_id = ?'
            . ' WHERE e.id = ?)',
            [$when, $userId, $entryId, $userId, $entryId],
            [
                Types::DATETIME_IMMUTABLE,
                Types::INTEGER,
                Types::INTEGER,
                Types::INTEGER,
                Types::INTEGER,
            ],
        );
    }

    public function remove(int $userId, int $entryId): void
    {
        $this->getEntityManager()->createQuery(sprintf(
            'DELETE FROM %s share WHERE IDENTITY(share.user) = :user AND IDENTITY(share.entry) = :entry',
            EntryShare::class,
        ))
            ->setParameter('user', $userId)
            ->setParameter('entry', $entryId)
            ->execute();
    }

    /** Read as a scalar, so a share written by record() is never answered from a stale identity map. */
    public function sharedAtFor(int $userId, int $entryId): ?\DateTimeImmutable
    {
        /** @var list<array{sharedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('share')
            ->select('share.sharedAt AS sharedAt')
            ->andWhere('IDENTITY(share.user) = :user')->setParameter('user', $userId)
            ->andWhere('IDENTITY(share.entry) = :entry')->setParameter('entry', $entryId)
            ->getQuery()
            ->getResult();

        return $rows[0]['sharedAt'] ?? null;
    }

    /** Gated to still-subscribed feeds like the shared list itself, so the badge never counts an orphaned share. */
    public function countForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('share')
            ->select('COUNT(share.entry)')
            ->join('share.entry', 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(share.user) = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The user's shares of the given entries keyed by entry id.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, EntryShare>
     */
    public function forUserByEntryIds(int $userId, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }

        /** @var list<EntryShare> $shares */
        $shares = $this->createQueryBuilder('share')
            ->andWhere('IDENTITY(share.user) = :user')
            ->andWhere('IDENTITY(share.entry) IN (:entryIds)')
            ->setParameter('user', $userId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getResult();

        $sharesByEntryId = [];
        foreach ($shares as $share) {
            $sharesByEntryId[$share->getEntry()->requireId()] = $share;
        }

        return $sharesByEntryId;
    }

    /**
     * The instants of the user's shares of the given entries, keyed by entry id.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function sharedAtByEntryIds(int $userId, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }

        /** @var list<array{entryId: int|string, sharedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('share')
            ->select('IDENTITY(share.entry) AS entryId', 'share.sharedAt AS sharedAt')
            ->andWhere('IDENTITY(share.user) = :user')
            ->andWhere('IDENTITY(share.entry) IN (:entryIds)')
            ->setParameter('user', $userId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getResult();

        $sharedAtByEntryId = [];
        foreach ($rows as $row) {
            $sharedAtByEntryId[(int) $row['entryId']] = $row['sharedAt'];
        }

        return $sharedAtByEntryId;
    }

    public function addMissingForUser(int $userId, array $sharedAtByEntryId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $statement = sprintf(
            'INSERT %s INTO entry_share (user_id, entry_id, shared_at) VALUES (?, ?, ?)',
            DatabasePlatform::insertIgnoreClause($connection),
        );
        $connection->transactional(static function () use ($connection, $statement, $userId, $sharedAtByEntryId): void {
            foreach ($sharedAtByEntryId as $entryId => $sharedAt) {
                $connection->executeStatement(
                    $statement,
                    [$userId, $entryId, $sharedAt],
                    [Types::INTEGER, Types::INTEGER, Types::DATETIME_IMMUTABLE],
                );
            }
        });
    }
}
