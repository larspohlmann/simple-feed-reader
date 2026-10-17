<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Fetch\Exception\FeedUnreachableException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;

/** @extends ServiceEntityRepository<Subscription> */
final class RefusedProfileSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /** @return list<Subscription> in feed, then subscription order, starting after $after */
    public function findOnRefusedBlueskyProfileFeeds(?Subscription $after, int $limit): array
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->addSelect('f')
            ->join('s.feed', 'f')
            ->andWhere('f.network = :bluesky')
            ->andWhere('f.sourceFormat = :xml')
            ->andWhere('f.fetchSchedule.lastErrorMessage LIKE :forbidden')
            ->andWhere(sprintf(
                'EXISTS (SELECT sa.id FROM %s sa WHERE sa.user = s.user AND sa.network = :bluesky'
                . ' AND sa.status = :connected)',
                SocialAccount::class,
            ))
            ->setParameter('bluesky', SocialNetwork::Bluesky)
            ->setParameter('xml', SourceFormat::XML)
            ->setParameter('forbidden', '%' . FeedUnreachableException::STATUS_MARKER . Response::HTTP_FORBIDDEN)
            ->setParameter('connected', SocialAccountStatus::Connected)
            ->orderBy('f.id', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults($limit);
        if (null !== $after) {
            $queryBuilder
                ->andWhere('f.id > :afterFeed OR (f.id = :afterFeed AND s.id > :afterSubscription)')
                ->setParameter('afterFeed', $after->getFeed()->requireId())
                ->setParameter('afterSubscription', $after->requireId());
        }
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $queryBuilder->getQuery()->getResult();

        return $subscriptions;
    }
}
