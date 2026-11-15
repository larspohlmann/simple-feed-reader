<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SharedLinkResolution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SharedLinkResolution> */
final class SharedLinkResolutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedLinkResolution::class);
    }

    /**
     * Queues the stored links of these hashes; a link already queued or settled keeps its state.
     *
     * @param list<string> $urlHashes
     */
    public function queueByUrlHashes(array $urlHashes): void
    {
        if ($urlHashes === []) {
            return;
        }
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            sprintf(
                "INSERT %s INTO shared_link_resolution (shared_link_id, status, attempts)"
                . " SELECT id, 'pending', 0 FROM shared_link WHERE url_hash IN (?)",
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            [array_values(array_unique($urlHashes))],
            [ArrayParameterType::STRING],
        );
    }
}
