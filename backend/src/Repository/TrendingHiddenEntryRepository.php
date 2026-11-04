<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingHiddenEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrendingHiddenEntry>
 */
final class TrendingHiddenEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrendingHiddenEntry::class);
    }

    /** Every entry of the story, social ones included; an entry hidden before keeps its first time. */
    public function hideStory(int $userId, int $storyId, \DateTimeImmutable $hiddenAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            \sprintf(
                'INSERT %s INTO trending_hidden_entry (user_id, entry_id, hidden_at)'
                . ' SELECT ?, member.entry_id, ? FROM trending_story_entry member WHERE member.story_id = ?',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            [$userId, $hiddenAt, $storyId],
            [Types::INTEGER, Types::DATETIME_IMMUTABLE, Types::INTEGER],
        );
    }
}
