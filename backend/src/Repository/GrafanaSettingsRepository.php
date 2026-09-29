<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GrafanaSettings;
use App\Service\Grafana\StoredGrafanaSettings\StoredGrafanaSettingsInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Not final: the Grafana settings tests stub it instead of using a database.
 *
 * @extends ServiceEntityRepository<GrafanaSettings>
 */
final class GrafanaSettingsRepository extends ServiceEntityRepository implements StoredGrafanaSettingsInterface
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
