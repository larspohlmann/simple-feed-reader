<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingStory;
use App\Enum\TrendingWindow;
use App\Service\Trending\Model\RankedStoryModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The per-reader reads of the Trending snapshot (#2017).
 *
 * @extends ServiceEntityRepository<TrendingStory>
 */
final class TrendingStoryRepository extends ServiceEntityRepository
{
    /** A story is shown to a reader only when at least this many article feeds they follow cover it. */
    private const int MINIMUM_ARTICLE_FEEDS = 2;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrendingStory::class);
    }

    /**
     * The window's stories at least its threshold of the reader's feeds took part in (social ones count), covered by
     * at least two article feeds the reader follows: widest first, then newest.
     *
     * @return list<RankedStoryModel>
     */
    public function rankedForUser(int $userId, TrendingWindow $window, int $limit): array
    {
        /** @var list<array{story_id: int|string, outlet_count: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            \sprintf(
                'SELECT story.id AS story_id, COUNT(DISTINCT story_member.feed_id) AS outlet_count'
                . ' FROM trending_story story'
                . ' JOIN trending_story_entry story_member ON story_member.story_id = story.id'
                . ' JOIN feed member_feed ON member_feed.id = story_member.feed_id'
                . ' JOIN subscription subscribed'
                . ' ON subscribed.feed_id = story_member.feed_id AND subscribed.user_id = :user'
                . ' WHERE story.time_window = :window'
                . ' GROUP BY story.id, story.last_seen'
                . ' HAVING COUNT(DISTINCT story_member.feed_id) >= :minimum'
                . ' AND COUNT(DISTINCT CASE WHEN member_feed.network IS NULL THEN story_member.feed_id END)'
                . ' >= :minimumArticleFeeds'
                . ' ORDER BY outlet_count DESC, story.last_seen DESC, story.id DESC'
                . ' LIMIT %d',
                $limit,
            ),
            [
                'user' => $userId,
                'window' => $window->value,
                'minimum' => $window->minSubscribedFeeds(),
                'minimumArticleFeeds' => self::MINIMUM_ARTICLE_FEEDS,
            ],
            [
                'user' => ParameterType::INTEGER,
                'minimum' => ParameterType::INTEGER,
                'minimumArticleFeeds' => ParameterType::INTEGER,
            ],
        );

        return array_map(
            static fn (array $row): RankedStoryModel => new RankedStoryModel(
                (int) $row['story_id'],
                (int) $row['outlet_count'],
            ),
            $rows,
        );
    }

    /**
     * @param list<int> $storyIds
     *
     * @return array<int, list<int>> story id => its entries of the non-social feeds the reader follows
     */
    public function articleEntryIdsByStory(int $userId, array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        /** @var list<array{story_id: int|string, entry_id: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT shown.story_id, shown.entry_id FROM trending_story_entry shown'
            . ' JOIN feed article_feed ON article_feed.id = shown.feed_id AND article_feed.network IS NULL'
            . ' JOIN subscription reader ON reader.feed_id = shown.feed_id AND reader.user_id = :user'
            . ' WHERE shown.story_id IN (:storyIds)',
            ['user' => $userId, 'storyIds' => $storyIds],
            ['user' => ParameterType::INTEGER, 'storyIds' => ArrayParameterType::INTEGER],
        );

        $entryIds = [];
        foreach ($rows as $row) {
            $entryIds[(int) $row['story_id']][] = (int) $row['entry_id'];
        }

        return $entryIds;
    }

    /**
     * @param list<int> $storyIds
     *
     * @return list<int> the stories one of whose entries, social ones included, the reader opened
     */
    public function viewedStoryIds(int $userId, array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        /** @var list<int|string> $viewed */
        $viewed = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT opened.story_id FROM trending_story_entry opened'
            . ' JOIN entry_state reading ON reading.entry_id = opened.entry_id'
            . ' AND reading.user_id = :user AND reading.is_viewed = 1'
            . ' WHERE opened.story_id IN (:storyIds)',
            ['user' => $userId, 'storyIds' => $storyIds],
            ['user' => ParameterType::INTEGER, 'storyIds' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (int|string $storyId): int => (int) $storyId, $viewed);
    }
}
