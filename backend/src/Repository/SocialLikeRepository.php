<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CanonicalItem;
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

    public function findOneFor(User $user, CanonicalItem $item): ?SocialLike
    {
        return $this->findOneBy(['user' => $user, 'canonicalItem' => $item]);
    }

    /**
     * @param list<int> $itemIds
     *
     * @return array<int, true> the liked ones, by canonical item id
     */
    public function findLikedItemIds(int $userId, array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }
        /** @var list<int> $likedItemIds item.id, unlike IDENTITY(), is hydrated as an int on every driver */
        $likedItemIds = $this->createQueryBuilder('sl')
            ->select('item.id')
            ->join('sl.canonicalItem', 'item')
            ->andWhere('sl.user = :user')
            ->andWhere('item.id IN (:itemIds)')
            ->setParameter('user', $userId)
            ->setParameter('itemIds', $itemIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($likedItemIds, true);
    }
}
