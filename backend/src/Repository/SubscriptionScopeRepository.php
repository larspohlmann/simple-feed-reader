<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
final class SubscriptionScopeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /** @return list<Subscription> */
    public function findForUserByTagId(int $userId, int $tagId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->innerJoin('s.subscriptionTags', 'st')->innerJoin('st.tag', 't')
            ->andWhere('s.user = :user')->setParameter('user', $userId)
            ->andWhere('t.id = :tagId')->setParameter('tagId', $tagId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<Subscription> */
    public function findForUserOnNetwork(int $userId, SocialNetwork $network): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->join('s.feed', 'f')->addSelect('f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('f.network = :network')->setParameter('network', $network)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
