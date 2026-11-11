<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SharingSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SharingSettings> */
final class SharingSettingsRepository extends ServiceEntityRepository
{
    /** @use UserSettingsRowLookup<SharingSettings> */
    use UserSettingsRowLookup;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharingSettings::class);
    }
}
