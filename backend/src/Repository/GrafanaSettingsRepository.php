<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GrafanaSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Not final: the Grafana settings tests stub it instead of using a database.
 *
 * @extends ServiceEntityRepository<GrafanaSettings>
 */
class GrafanaSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GrafanaSettings::class);
    }

    public function findSingleton(): ?GrafanaSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
