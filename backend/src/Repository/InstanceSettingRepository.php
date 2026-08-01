<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstanceSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstanceSetting>
 */
final class InstanceSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceSetting::class);
    }

    /**
     * The one row, or null when the instance has never been configured (in which
     * case the caller applies defaults). Ordered by id so a stray second row —
     * which update() prevents — never changes which row we read.
     */
    public function findSingleton(): ?InstanceSetting
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
