<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReadingSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReadingSettings> */
final class ReadingSettingsRepository extends ServiceEntityRepository
{
    /** @use UserSettingsRowLookup<ReadingSettings> */
    use UserSettingsRowLookup;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReadingSettings::class);
    }
}
