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
            ->join(SocialFeed::class, 'sf', 'WITH', 'sf.feed = s.feed')
            ->andWhere('sf.socialAccount = :account')
            ->setParameter('account', $account)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $subscriptions;
    }
}
