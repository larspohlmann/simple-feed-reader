<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReadingSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReadingSettings> */
final class ReadingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReadingSettings::class);
    }

    public function forUser(User $user): ReadingSettings
    {
        return UserSettingsRow::required($this->findOneBy(['user' => $user]), $user);
    }
}
