<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryState>
 */
class EntryStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryState::class);
    }

    public function findOneForUserEntry(int $userId, int $entryId): ?EntryState
    {
        /** @var EntryState|null $row */
        $row = $this->createQueryBuilder('es')
            ->andWhere('IDENTITY(es.user) = :user')->setParameter('user', $userId)
            ->andWhere('IDENTITY(es.entry) = :entry')->setParameter('entry', $entryId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * Total favourite and kept entries for the user, counting only entries whose
     * feed the user still subscribes to — the same subscription gate the
     * Favorites/Kept lists apply, so the sidebar badges match their lists (an
     * orphaned state left behind by an unsubscribe is not counted).
     *
     * @return array{favorites: int, kept: int}
     */
    public function favoriteAndKeptCountsForUser(int $userId): array
    {
        /** @var array{favorites: int|string, kept: int|string} $row */
        $row = $this->createQueryBuilder('es')
            ->select('SUM(CASE WHEN es.isFavorite = :true THEN 1 ELSE 0 END) AS favorites')
            ->addSelect('SUM(CASE WHEN es.isKept = :true THEN 1 ELSE 0 END) AS kept')
            ->join('es.entry', 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(es.user) = :user')
            ->setParameter('user', $userId)
            ->setParameter('true', true, Types::BOOLEAN)
            ->getQuery()
            ->getSingleResult();

        return [
            'favorites' => (int) $row['favorites'],
            'kept' => (int) $row['kept'],
        ];
    }
}
