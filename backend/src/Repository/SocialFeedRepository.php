<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Enum\SocialFeedKind;
use App\Service\Social\Model\PrivateFeedModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialFeed>
 */
final class SocialFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialFeed::class);
    }

    /**
     * @param list<int> $feedIds
     *
     * @return array<int, SocialFeed> by feed id, each with its account loaded
     */
    public function findWithAccountByFeedIds(array $feedIds): array
    {
        if ([] === $feedIds) {
            return [];
        }
        /** @var list<SocialFeed> $socialFeeds */
        $socialFeeds = $this->createQueryBuilder('sf')
            ->addSelect('sa')
            ->join('sf.socialAccount', 'sa')
            ->andWhere('sf.feed IN (:feedIds)')
            ->setParameter('feedIds', $feedIds)
            ->getQuery()
            ->getResult();
        $byFeedId = [];
        foreach ($socialFeeds as $socialFeed) {
            $byFeedId[$socialFeed->getFeed()->requireId()] = $socialFeed;
        }

        return $byFeedId;
    }

    public function findOneFor(SocialAccount $account, SocialFeedKind $kind, string $remoteId): ?SocialFeed
    {
        return $this->findOneBy(['socialAccount' => $account, 'kind' => $kind, 'remoteId' => $remoteId]);
    }

    /** @return list<SocialFeed> */
    public function findAllOf(SocialAccount $account): array
    {
        // @phpstan-ignore arrayValues.list (deliberate: findBy() promises a list only in its @phpstan-return)
        return array_values($this->findBy(['socialAccount' => $account], ['id' => 'ASC']));
    }

    /** @return list<Subscription> */
    public function subscriptionsOf(SocialAccount $account): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->join(SocialFeed::class, 'sf', 'ON', 'sf.feed = s.feed')
            ->join('sf.socialAccount', 'sa')
            ->andWhere('sf.socialAccount = :account')
            ->andWhere('s.user = sa.user')
            ->setParameter('account', $account)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $subscriptions;
    }

    /** @return list<array{kind: SocialFeedKind, remoteId: string, subscriptionId: int}> */
    public function subscribedCollectionsOf(SocialAccount $account): array
    {
        return $this->subscribedCollectionsOfKinds($account, SocialFeedKind::cases());
    }

    /**
     * @param list<SocialFeedKind> $kinds
     *
     * @return list<array{kind: SocialFeedKind, remoteId: string, subscriptionId: int}>
     */
    public function subscribedCollectionsOfKinds(SocialAccount $account, array $kinds): array
    {
        /** @var list<array{kind: SocialFeedKind, remoteId: string, subscriptionId: int}> $rows */
        $rows = $this->createQueryBuilder('sf')
            ->select('sf.kind AS kind', 'sf.remoteId AS remoteId', 's.id AS subscriptionId')
            ->join(Subscription::class, 's', 'ON', 's.feed = sf.feed')
            ->join('sf.socialAccount', 'sa')
            ->andWhere('sf.socialAccount = :account')
            ->andWhere('s.user = sa.user')
            ->andWhere('sf.kind IN (:kinds)')
            ->setParameter('account', $account)
            ->setParameter('kinds', $kinds)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /** A person's feed counts through any account of the user, a collection only through $account. */
    public function findSubscriptionFor(SocialAccount $account, PrivateFeedModel $privateFeed): ?Subscription
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->join(SocialFeed::class, 'sf', 'ON', 'sf.feed = s.feed')
            ->join('sf.socialAccount', 'sa')
            ->andWhere('s.user = sa.user')
            ->andWhere('sf.kind = :kind')
            ->andWhere('sf.remoteId = :remoteId')
            ->setParameter('kind', $privateFeed->kind)
            ->setParameter('remoteId', $privateFeed->remoteId)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1);
        if ($privateFeed->kind->followsOnePerson()) {
            $qb->andWhere('sa.user = :user')->setParameter('user', $account->getUser());
        } else {
            $qb->andWhere('sf.socialAccount = :account')->setParameter('account', $account);
        }
        /** @var Subscription|null $subscription */
        $subscription = $qb->getQuery()->getOneOrNullResult();

        return $subscription;
    }

    public function forFeed(Feed $feed): ?SocialFeed
    {
        return $this->findOneBy(['feed' => $feed]);
    }
}
