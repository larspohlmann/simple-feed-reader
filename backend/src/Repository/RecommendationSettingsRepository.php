<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecommendationSettings>
 */
final class RecommendationSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationSettings::class);
    }

    public function findForUser(User $user): ?RecommendationSettings
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Every account that opted into a scheduled run (#333); the finder decides
     * which of them are actually due right now.
     *
     * @return list<RecommendationSettings>
     */
    public function findWithAutoGenerateInterval(): array
    {
        /** @var list<RecommendationSettings> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.autoGenerateIntervalHours IS NOT NULL')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
