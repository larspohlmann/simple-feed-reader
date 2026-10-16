<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RetentionSetting;
use App\Service\Retention\StoredRetentionSetting\StoredRetentionSettingInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RetentionSetting>
 */
final class RetentionSettingRepository extends ServiceEntityRepository implements StoredRetentionSettingInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetentionSetting::class);
    }

    public function findSingleton(): ?RetentionSetting
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
