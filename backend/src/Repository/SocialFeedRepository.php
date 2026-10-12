<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Enum\SocialFeedKind;
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

    public function findOneByFeed(Feed $feed): ?SocialFeed
    {
        return $this->findOneBy(['feed' => $feed]);
    }

    public function findOneFor(SocialAccount $account, SocialFeedKind $kind, ?string $remoteId): ?SocialFeed
    {
        return $this->findOneBy([
            'socialAccount' => $account,
            'kind' => $kind,
            'remoteId' => SocialFeed::storedRemoteId($remoteId),
        ]);
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

    /** @return list<array{kind: SocialFeedKind, remoteId: ?string, subscriptionId: int}> */
    public function subscribedCollectionsOf(SocialAccount $account): array
    {
        /** @var list<array{kind: SocialFeedKind, remoteId: string, subscriptionId: int}> $rows */
        $rows = $this->createQueryBuilder('sf')
            ->select('sf.kind AS kind', 'sf.remoteId AS remoteId', 's.id AS subscriptionId')
            ->join(Subscription::class, 's', 'ON', 's.feed = sf.feed')
            ->join('sf.socialAccount', 'sa')
            ->andWhere('sf.socialAccount = :account')
            ->andWhere('s.user = sa.user')
            ->setParameter('account', $account)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'kind' => $row['kind'],
                'remoteId' => SocialFeed::storedRemoteId(null) === $row['remoteId'] ? null : $row['remoteId'],
                'subscriptionId' => $row['subscriptionId'],
            ],
            $rows,
        );
    }
}
