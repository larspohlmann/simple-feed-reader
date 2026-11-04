<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingStory;
use App\Service\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Trending\Model\RankedStoryModel;
use App\Service\Trending\Model\ReaderWindowModel;
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

    private const array VISIBILITY_TYPES = [
        'user' => ParameterType::INTEGER,
        'minimum' => ParameterType::INTEGER,
        'minimumArticleFeeds' => ParameterType::INTEGER,
    ];

    /** The window's stories, counting only feeds the reader follows; an `AND` on `story`, then VISIBLE_COVERAGE. */
    private const string VISIBLE_STORIES = 'SELECT story.id AS story_id,'
        . ' COUNT(DISTINCT story_member.feed_id) AS outlet_count'
        . ' FROM trending_story story'
        . ' JOIN trending_story_entry story_member ON story_member.story_id = story.id'
        . ' JOIN feed member_feed ON member_feed.id = story_member.feed_id'
        . ' JOIN subscription subscribed'
        . ' ON subscribed.feed_id = story_member.feed_id AND subscribed.user_id = :user'
        . ' WHERE story.time_window = :window';

    /** At least the reader's threshold of their feeds took part (social ones count), two of them article feeds. */
    private const string VISIBLE_COVERAGE = ' GROUP BY story.id, story.last_seen'
        . ' HAVING COUNT(DISTINCT story_member.feed_id) >= :minimum'
        . ' AND COUNT(DISTINCT CASE WHEN member_feed.network IS NULL THEN story_member.feed_id END)'
        . ' >= :minimumArticleFeeds';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrendingStory::class);
    }

    /**
     * The stories the reader is shown, leaving out any holding an entry they hid: widest first, then newest.
     *
     * @return list<RankedStoryModel>
     */
    public function rankedForUser(ReaderWindowModel $reader, int $limit): array
    {
        /** @var list<array{story_id: int|string, outlet_count: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            self::VISIBLE_STORIES
            . ' AND NOT EXISTS (SELECT 1 FROM trending_story_entry hidden_member'
            . ' JOIN trending_hidden_entry hidden'
            . ' ON hidden.entry_id = hidden_member.entry_id AND hidden.user_id = :user'
            . ' WHERE hidden_member.story_id = story.id)'
            . self::VISIBLE_COVERAGE
            . \sprintf(' ORDER BY outlet_count DESC, story.last_seen DESC, story.id DESC LIMIT %d', $limit),
            self::visibilityParameters($reader),
            self::VISIBILITY_TYPES,
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
     * @throws TrendingStoryNotFoundException when no story the reader is shown, hidden ones aside, holds the entry
     *                                        through a feed they follow
     */
    public function storyIdContaining(ReaderWindowModel $reader, int $entryId): int
    {
        $storyId = $this->getEntityManager()->getConnection()->fetchOne(
            self::VISIBLE_STORIES
            . ' AND EXISTS (SELECT 1 FROM trending_story_entry asked'
            . ' JOIN subscription asker ON asker.feed_id = asked.feed_id AND asker.user_id = :user'
            . ' WHERE asked.story_id = story.id AND asked.entry_id = :entry)'
            . self::VISIBLE_COVERAGE,
            [...self::visibilityParameters($reader), 'entry' => $entryId],
            [...self::VISIBILITY_TYPES, 'entry' => ParameterType::INTEGER],
        );
        if (!is_numeric($storyId)) {
            throw new TrendingStoryNotFoundException(
                \sprintf('No %s story holds entry %d for this reader.', $reader->window->value, $entryId),
            );
        }

        return (int) $storyId;
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

    /** @return array{user: int, window: string, minimum: int, minimumArticleFeeds: int} */
    private static function visibilityParameters(ReaderWindowModel $reader): array
    {
        return [
            'user' => $reader->userId,
            'window' => $reader->window->value,
            'minimum' => $reader->minimumOutlets,
            'minimumArticleFeeds' => self::MINIMUM_ARTICLE_FEEDS,
        ];
    }
}
