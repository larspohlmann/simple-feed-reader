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

    /** @return array<string, list<SocialNetwork>> by InclusionSurface value */
    public function excludedNetworksBySurface(int $userId): array
    {
        /** @var list<NetworkExclusion> $exclusions */
        $exclusions = $this->createQueryBuilder('exclusion')
            ->andWhere('exclusion.user = :user')
            ->setParameter('user', $userId)
            ->orderBy('exclusion.network', 'ASC')
            ->getQuery()
            ->getResult();

        $bySurface = [];
        foreach ($exclusions as $exclusion) {
            $bySurface[$exclusion->getSurface()->value][] = $exclusion->getNetwork();
        }

        return $bySurface;
    }

    public function findOneFor(int $userId, SocialNetwork $network, InclusionSurface $surface): ?NetworkExclusion
    {
        return $this->findOneBy(['user' => $userId, 'network' => $network, 'surface' => $surface]);
    }
}
