<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForYouNetworkExclusion;
use App\Enum\SocialNetwork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ForYouNetworkExclusion> */
final class ForYouNetworkExclusionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForYouNetworkExclusion::class);
    }

    /** @return list<SocialNetwork> */
    public function excludedNetworksFor(int $userId): array
    {
        /** @var list<string> $networks */
        $networks = $this->createQueryBuilder('exclusion')
            ->select('exclusion.network')
            ->andWhere('exclusion.user = :user')
            ->setParameter('user', $userId)
            ->orderBy('exclusion.network', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(SocialNetwork::from(...), $networks);
    }

    public function findOneFor(int $userId, SocialNetwork $network): ?ForYouNetworkExclusion
    {
        return $this->findOneBy(['user' => $userId, 'network' => $network]);
    }
}
