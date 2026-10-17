<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\SocialLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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
     * @return array<int, true> the liked ones, by entry id
     */
    public function likedEntryIdsAmong(int $userId, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<int> $likedEntryIds e.id, unlike IDENTITY(), is hydrated as an int on every driver */
        $likedEntryIds = $this->createQueryBuilder('sl')
            ->select('e.id')
            ->join('sl.entry', 'e')
            ->andWhere('sl.user = :user')
            ->andWhere('e.id IN (:entryIds)')
            ->setParameter('user', $userId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($likedEntryIds, true);
    }

    public function copyLike(int $userId, int $sourceEntryId, int $targetEntryId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $conflictClause = DatabasePlatform::isMySql($connection) ? 'IGNORE' : 'OR IGNORE';

        $connection->executeStatement(
            sprintf(
                'INSERT %s INTO social_like (user_id, entry_id, social_account_id, remote_id, liked_at)'
                . ' SELECT user_id, ?, social_account_id, remote_id, liked_at FROM social_like'
                . ' WHERE user_id = ? AND entry_id = ?',
                $conflictClause,
            ),
            [$targetEntryId, $userId, $sourceEntryId],
            [Types::INTEGER, Types::INTEGER, Types::INTEGER],
        );
    }
}
