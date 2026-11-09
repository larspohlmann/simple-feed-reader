<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Enum\SubscriptionSet;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /** @return list<Subscription> feed and tags eager-loaded, in creation order */
    public function findForUserWithTags(int $userId, SubscriptionSet $set): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.subscriptionTags', 'st')->addSelect('st')
            ->leftJoin('st.tag', 't')->addSelect('t')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC');
        PublicOrAuthorFeedDql::restrict($qb, 'f', $set->excludedFormats());
        /** @var list<Subscription> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function findOneForUserAndFeedUrl(int $userId, string $feedUrl): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = $this->createQueryBuilder('s')
            ->join('s.feed', 'f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('f.url = :feedUrl')->setParameter('feedUrl', $feedUrl)
            ->getQuery()
            ->getOneOrNullResult();

        return $subscription;
    }

    /** The count the cap holds against: a saved feed is never a subscription the user chose (#1666). */
    public function countForUser(int $userId): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.feed', 'f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId);
        SavedFeedDql::exclude($qb, 'f');

        return (int) $qb->getQuery()->getSingleScalarResult();
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
