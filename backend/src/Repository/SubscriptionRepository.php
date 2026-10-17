<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Enum\SourceFormat;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
final class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly NextPosition $nextPosition)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function existsForUserAndFeed(int $userId, int $feedId): bool
    {
        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('s.feed = :feedId')->setParameter('feedId', $feedId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function findOneForUserAndFeed(int $userId, int $feedId): ?Subscription
    {
        return $this->findOneBy(['user' => $userId, 'feed' => $feedId]);
    }

    /**
     * A user's subscriptions with their feed and tags eager-loaded (no N+1),
     * ordered by creation time then id for a stable list.
     *
     * @return list<Subscription>
     */
    public function findForUserWithTags(int $userId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->withTagsForUser($userId)->getQuery()->getResult();

        return $rows;
    }

    /**
     * What an OPML or backup export carries: a private social feed is read through an account neither file holds.
     *
     * @return list<Subscription>
     */
    public function findExportableForUserWithTags(int $userId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->withTagsForUser($userId)
            ->andWhere('f.sourceFormat NOT IN (:socialFormats)')
            ->setParameter('socialFormats', SourceFormat::SOCIAL)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    private function withTagsForUser(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.subscriptionTags', 'st')->addSelect('st')
            ->leftJoin('st.tag', 't')->addSelect('t')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC');
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

    public function countForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The user's subscriptions matching the given ids. Fewer results than ids
     * means one or more ids were invalid or belonged to another user.
     *
     * @param list<int> $subscriptionIds
     *
     * @return list<Subscription>
     */
    public function findAllByIdsForUser(int $userId, array $subscriptionIds): array
    {
        if ([] === $subscriptionIds) {
            return [];
        }

        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.id IN (:ids)')->setParameter('ids', $subscriptionIds)
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Same as findAllByIdsForUser(), with the feed and tags eager-loaded for a
     * caller that serializes the result. reorder() and bulkUnsubscribe() skip
     * these joins because they only write through the ids, never serialize.
     *
     * @param list<int> $subscriptionIds
     *
     * @return list<Subscription>
     */
    public function findAllByIdsForUserWithAssociations(int $userId, array $subscriptionIds): array
    {
        if ([] === $subscriptionIds) {
            return [];
        }

        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.subscriptionTags', 'st')->addSelect('st')
            ->leftJoin('st.tag', 't')->addSelect('t')
            ->andWhere('s.id IN (:ids)')->setParameter('ids', $subscriptionIds)
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function nextPositionForUser(int $userId): int
    {
        return $this->nextPosition->in(
            $this->createQueryBuilder('s')->andWhere('s.user = :userId')->setParameter('userId', $userId),
        );
    }

    public function getOneForUser(int $userId, int $subscriptionId): Subscription
    {
        /** @var Subscription|null $row */
        $row = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.subscriptionTags', 'st')->addSelect('st')
            ->leftJoin('st.tag', 't')->addSelect('t')
            ->andWhere('s.id = :id')->setParameter('id', $subscriptionId)
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row ?? throw new RecordNotFoundException('No such subscription.');
    }
}
