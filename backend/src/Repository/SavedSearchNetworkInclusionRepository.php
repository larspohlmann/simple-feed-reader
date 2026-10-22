<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearchNetworkInclusion;
use App\Enum\SocialNetwork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SavedSearchNetworkInclusion> */
final class SavedSearchNetworkInclusionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearchNetworkInclusion::class);
    }

    /** @return list<SocialNetwork> */
    public function includedNetworksFor(int $userId): array
    {
        /** @var list<string> $networks */
        $networks = $this->createQueryBuilder('inclusion')
            ->select('inclusion.network')
            ->andWhere('inclusion.user = :user')
            ->setParameter('user', $userId)
            ->orderBy('inclusion.network', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(SocialNetwork::from(...), $networks);
    }

    public function findOneFor(int $userId, SocialNetwork $network): ?SavedSearchNetworkInclusion
    {
        return $this->findOneBy(['user' => $userId, 'network' => $network]);
    }
}
