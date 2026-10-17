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

    /**
     * Subscriptions to Bluesky profile RSS feeds whose last fetch was refused with a 403, held by a user with a
     * connected Bluesky account: the ones a hidden profile's author feed may replace. Feed by feed.
     *
     * @return list<Subscription>
     */
    public function findOnRefusedBlueskyProfileFeeds(int $limit): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->createQueryBuilder('s')
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
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $subscriptions;
    }
}
