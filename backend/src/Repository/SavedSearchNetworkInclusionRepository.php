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
        $included = array_map(
            static fn (SavedSearchNetworkInclusion $inclusion): SocialNetwork => $inclusion->getNetwork(),
            $this->findBy(['user' => $userId]),
        );

        return array_values(array_filter(
            SocialNetwork::cases(),
            static fn (SocialNetwork $network): bool => \in_array($network, $included, true),
        ));
    }

    public function findOneFor(int $userId, SocialNetwork $network): ?SavedSearchNetworkInclusion
    {
        return $this->findOneBy(['user' => $userId, 'network' => $network]);
    }
}
