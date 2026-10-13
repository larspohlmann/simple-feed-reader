<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\SocialLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialLike>
 */
final class SocialLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialLike::class);
    }

    public function findOneFor(User $user, Entry $entry): ?SocialLike
    {
        return $this->findOneBy(['user' => $user, 'entry' => $entry]);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    public function likedEntryIdsAmong(int $userId, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{entryId: int|string}> $rows */
        $rows = $this->createQueryBuilder('sl')
            ->select('IDENTITY(sl.entry) AS entryId')
            ->andWhere('sl.user = :user')
            ->andWhere('sl.entry IN (:entryIds)')
            ->setParameter('user', $userId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['entryId'], $rows);
    }
}
