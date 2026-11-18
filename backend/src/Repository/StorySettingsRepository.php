<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StorySettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StorySettings> */
final class StorySettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StorySettings::class);
    }

    public function findForUser(User $user): ?StorySettings
    {
        return $this->find($user->requireId());
    }
}
