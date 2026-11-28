<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * Every active account that opted into a scheduled run; the finder decides which of them are due right now.
     *
     * @return list<RecommendationSettings>
     */
    public function findWithAutoGenerateInterval(\DateTimeImmutable $now): array
    {
        $qb = $this->withOwner()->andWhere('s.autoGenerateIntervalHours IS NOT NULL');
        ActiveAccountDql::restrict($qb, 'owner', $now);

        /** @var list<RecommendationSettings> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Every active account that scheduled its profile; the finder decides which of them are due right now.
     *
     * @return list<RecommendationSettings>
     */
    public function findWithProfileInterval(\DateTimeImmutable $now): array
    {
        $qb = $this->withOwner()->andWhere('s.profileTuning.intervalHours IS NOT NULL');
        ActiveAccountDql::restrict($qb, 'owner', $now);

        /** @var list<RecommendationSettings> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    private function withOwner(): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->join('s.user', 'owner')
            ->addSelect('owner');
    }
}
