<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SharingSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SharingSettings> */
final class SharingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharingSettings::class);
    }

    public function forUser(User $user): SharingSettings
    {
        return UserSettingsRow::required($this->findOneBy(['user' => $user]), $user);
    }
}
