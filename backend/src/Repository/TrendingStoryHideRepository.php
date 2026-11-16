<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingStoryHide;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrendingStoryHide>
 */
final class TrendingStoryHideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrendingStoryHide::class);
    }

    /** A story hidden before keeps its first time. */
    public function hide(int $userId, int $storyId, \DateTimeImmutable $hiddenAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            \sprintf(
                'INSERT %s INTO trending_story_hide (user_id, story_id, hidden_at) VALUES (?, ?, ?)',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            [$userId, $storyId, $hiddenAt],
            [Types::INTEGER, Types::INTEGER, Types::DATETIME_IMMUTABLE],
        );
    }
}
