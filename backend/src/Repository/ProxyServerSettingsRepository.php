<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProxyServerSettings;
use App\Service\Proxy\StoredProxySettings\StoredProxySettingsInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Not final: the proxy settings tests stub it instead of using a database.
 *
 * @extends ServiceEntityRepository<ProxyServerSettings>
 */
final class ProxyServerSettingsRepository extends ServiceEntityRepository implements StoredProxySettingsInterface
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
