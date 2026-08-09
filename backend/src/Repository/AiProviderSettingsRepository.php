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

    public function findOwnedById(User $user, int $id): ?AiProviderSettings
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /** @return list<AiProviderSettings> */
    public function findAllForUser(User $user): array
    {
        return array_values($this->findBy(['user' => $user], ['id' => 'ASC']));
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }
}
