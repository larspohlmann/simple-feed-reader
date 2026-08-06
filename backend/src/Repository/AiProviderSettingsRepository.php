<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiProviderSettings>
 */
final class AiProviderSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiProviderSettings::class);
    }

    public function findForUser(User $user): ?AiProviderSettings
    {
        return $this->findOneBy(['user' => $user]);
    }
}
