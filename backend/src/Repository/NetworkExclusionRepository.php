<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NetworkExclusion;
use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NetworkExclusion> */
final class NetworkExclusionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NetworkExclusion::class);
    }

    /** @return list<SocialNetwork> */
    public function excludedNetworks(int $userId, InclusionSurface $surface): array
    {
        /** @var list<string> $networks */
        $networks = $this->createQueryBuilder('exclusion')
            ->select('exclusion.network')
            ->andWhere('exclusion.user = :user')
            ->andWhere('exclusion.surface = :surface')
            ->setParameter('user', $userId)
            ->setParameter('surface', $surface)
            ->orderBy('exclusion.network', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(SocialNetwork::from(...), $networks);
    }

    public function findOneFor(int $userId, SocialNetwork $network, InclusionSurface $surface): ?NetworkExclusion
    {
        return $this->findOneBy(['user' => $userId, 'network' => $network, 'surface' => $surface]);
    }
}
