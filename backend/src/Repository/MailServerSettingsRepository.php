<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailServerSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailServerSettings>
 */
final class MailServerSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailServerSettings::class);
    }

    public function findSingleton(): ?MailServerSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
