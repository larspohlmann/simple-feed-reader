<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Enum\FeedGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /** @return list<Subscription> the tag's carriers and its children's, each once */
    public function findForUserInTagScope(int $userId, int $tagId): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->andWhere('s.user = :user')->setParameter('user', $userId)
            ->orderBy('s.id', 'ASC');
        TagScopeDql::restrict($qb, 's', $tagId);

        /** @var list<Subscription> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /** @return list<Subscription> */
    public function findForUserInFeedGroup(int $userId, FeedGroup $group): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->inFeedGroupQueryBuilder($userId, $group)
            ->addSelect('f')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<int> */
    public function idsForUserInFeedGroup(int $userId, FeedGroup $group): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->inFeedGroupQueryBuilder($userId, $group)
            ->select('s.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /** @return list<int> */
    public function idsIncludedInAllItemsForUser(int $userId): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('s')
            ->select('s.id')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('s.includeInAllItems = true')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /** @return array<int, list<int>> tag id => the subscriptions carrying it or a tag below it, each once */
    public function idsPerTagScopeForUser(int $userId): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('DISTINCT scopeTag.id AS tagId', 's.id AS subscriptionId')
            ->innerJoin('s.subscriptionTags', 'subscriptionTag')
            ->innerJoin('subscriptionTag.tag', TagLineageDql::alias('lineage', 0));
        TagLineageDql::joinAncestors($qb, 'lineage');
        $qb->innerJoin(
            Tag::class,
            'scopeTag',
            'ON',
            TagLineageDql::reaches('lineage', 'scopeTag.id') . ' AND scopeTag.user = :userId',
        )
            ->andWhere('s.user = :userId')->setParameter('userId', $userId);

        $scopes = [];
        /** @var list<array{tagId: int|string, subscriptionId: int|string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            $scopes[(int) $row['tagId']][] = (int) $row['subscriptionId'];
        }

        return $scopes;
    }

    /** @return list<Subscription> */
    public function findIncludedInAllItemsForUser(int $userId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('s.includeInAllItems = true')
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<Subscription> */
    public function findForUserOnNetwork(int $userId, NetworkScope $networkScope): array
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.feed', 'f')->addSelect('f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.id', 'ASC');
        NetworkScopeDql::restrict($qb, 'f', $networkScope);

        /** @var list<Subscription> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    private function inFeedGroupQueryBuilder(int $userId, FeedGroup $group): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.feed', 'f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.id', 'ASC');
        FeedGroupDql::restrict($qb, 's', 'f', $group);

        return $qb;
    }
}
