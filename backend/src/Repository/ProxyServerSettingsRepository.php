<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProxyServerSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Not final: ProxySettings unit-tests against a mock of this repository rather
 * than a real database, so it needs to stay doubleable.
 *
 * @extends ServiceEntityRepository<ProxyServerSettings>
 */
class ProxyServerSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProxyServerSettings::class);
    }

    public function findSingleton(): ?ProxyServerSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
