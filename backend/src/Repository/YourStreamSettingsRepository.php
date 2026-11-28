<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\YourStreamSettings;
use App\Entity\YourStreamTuning;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<YourStreamSettings> */
final class YourStreamSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, YourStreamSettings::class);
    }

    public function findForUser(User $user): ?YourStreamSettings
    {
        return $this->find($user);
    }

    public function findTuningForUser(int $userId): YourStreamTuning
    {
        return $this->find($userId)?->getTuning() ?? YourStreamTuning::defaults();
    }
}
