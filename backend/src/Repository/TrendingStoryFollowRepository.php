<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingStoryFollow;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFollowedException;
use App\Service\Curation\Trending\Model\FollowedStoryRowModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrendingStoryFollow>
 */
final class TrendingStoryFollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrendingStoryFollow::class);
    }

    /** A story followed before keeps its first follow and its last visit. */
    public function follow(int $userId, int $storyId, \DateTimeImmutable $followedAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            \sprintf(
                'INSERT %s INTO trending_story_follow (user_id, story_id, followed_at, last_opened_at)'
                . ' VALUES (?, ?, ?, ?)',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            [$userId, $storyId, $followedAt, $followedAt],
            [Types::INTEGER, Types::INTEGER, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE],
        );
    }

    public function unfollow(int $userId, int $storyId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM trending_story_follow WHERE user_id = ? AND story_id = ?',
            [$userId, $storyId],
        );
    }

    /** @return int the rows changed; MySQL counts none when the visit already had that time */
    public function markOpened(int $userId, int $storyId, \DateTimeImmutable $openedAt): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE trending_story_follow SET last_opened_at = ? WHERE user_id = ? AND story_id = ?',
            [$openedAt, $userId, $storyId],
            [Types::DATETIME_IMMUTABLE, Types::INTEGER, Types::INTEGER],
        );
    }

    /** @throws TrendingStoryNotFollowedException */
    public function lastOpenedAt(int $userId, int $storyId): \DateTimeImmutable
    {
        $lastOpenedAt = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT last_opened_at FROM trending_story_follow WHERE user_id = ? AND story_id = ?',
            [$userId, $storyId],
        );
        if (!\is_string($lastOpenedAt)) {
            throw self::notFollowed($userId, $storyId);
        }

        return NaiveUtc::parse($lastOpenedAt);
    }

    /** @throws TrendingStoryNotFollowedException */
    public function requireFollowed(int $userId, int $storyId): void
    {
        $this->lastOpenedAt($userId, $storyId);
    }

    /**
     * @param list<int> $storyIds
     *
     * @return list<int>
     */
    public function followedStoryIds(int $userId, array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        /** @var list<int|string> $followed */
        $followed = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT story_id FROM trending_story_follow WHERE user_id = :user AND story_id IN (:storyIds)',
            ['user' => $userId, 'storyIds' => $storyIds],
            ['user' => ParameterType::INTEGER, 'storyIds' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (int|string $storyId): int => (int) $storyId, $followed);
    }

    /** @return list<FollowedStoryRowModel> newest joined first */
    public function followedBy(int $userId): array
    {
        /**
         * @var list<array{story_id: int|string, headline: ?string, new_count: int|string, last_joined_at: string}>
         *      $rows
         */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT follow.story_id, story.last_joined_at,'
            . ' ' . self::headline() . ' AS headline,'
            . ' ' . self::newCount() . ' AS new_count'
            . ' FROM trending_story_follow follow'
            . ' JOIN trending_story story ON story.id = follow.story_id'
            . ' WHERE follow.user_id = ?'
            . ' ORDER BY story.last_joined_at DESC, follow.story_id DESC',
            [$userId],
            [ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): FollowedStoryRowModel => new FollowedStoryRowModel(
                (int) $row['story_id'],
                $row['headline'],
                (int) $row['new_count'],
                NaiveUtc::parse($row['last_joined_at']),
            ),
            $rows,
        );
    }

    /** The newest member from a subscribed article feed, else the newest member of any subscribed feed. */
    private static function headline(): string
    {
        return '(SELECT headline_entry.title FROM trending_story_entry headline_member'
            . ' JOIN entry headline_entry ON headline_entry.id = headline_member.entry_id'
            . ' JOIN feed headline_feed ON headline_feed.id = headline_member.feed_id'
            . self::subscribedTo('headline_member')
            . ' WHERE headline_member.story_id = follow.story_id'
            . ' ORDER BY CASE WHEN headline_feed.network IS NULL THEN 0 ELSE 1 END,'
            . ' headline_entry.effective_date DESC, headline_entry.id DESC LIMIT 1)';
    }

    /**
     * The members from subscribed feeds joined after the last visit, counted as Trending counts sources: an article
     * once, by its address when it has one, and a post by its account, the post's author else its feed.
     */
    private static function newCount(): string
    {
        $article = 'new_feed.network IS NULL';
        $post = 'new_feed.network IS NOT NULL';

        return '(SELECT COUNT(DISTINCT CASE WHEN ' . $article . ' THEN new_entry.url_hash END)'
            . ' + COUNT(CASE WHEN ' . $article . ' AND new_entry.url_hash IS NULL THEN 1 END)'
            . ' + COUNT(DISTINCT CASE WHEN ' . $post . ' THEN new_entry.author_url END)'
            . ' + COUNT(DISTINCT CASE WHEN ' . $post . ' AND new_entry.author_url IS NULL THEN new_member.feed_id END)'
            . ' FROM trending_story_entry new_member'
            . ' JOIN entry new_entry ON new_entry.id = new_member.entry_id'
            . ' JOIN feed new_feed ON new_feed.id = new_member.feed_id'
            . self::subscribedTo('new_member')
            . ' WHERE new_member.story_id = follow.story_id AND new_member.joined_at > follow.last_opened_at)';
    }

    /** Inclusion rules apply at build time only; like the story's entries listing, this asks only for a subscription. */
    private static function subscribedTo(string $memberAlias): string
    {
        return \sprintf(
            ' JOIN subscription %1$s_reader'
            . ' ON %1$s_reader.feed_id = %1$s.feed_id AND %1$s_reader.user_id = follow.user_id',
            $memberAlias,
        );
    }

    private static function notFollowed(int $userId, int $storyId): TrendingStoryNotFollowedException
    {
        return new TrendingStoryNotFollowedException(
            \sprintf('User %d does not follow Trending story %d.', $userId, $storyId),
        );
    }
}
