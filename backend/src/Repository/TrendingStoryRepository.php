<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingStory;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\ReaderWindowModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Service\Curation\Trending\Model\ShownEntriesModel;

/**
 * The per-reader reads of the Trending snapshot (#2017).
 *
 * @extends ServiceEntityRepository<TrendingStory>
 */
final class TrendingStoryRepository extends ServiceEntityRepository
{
    /** A story is shown only when this many article feeds the reader follows, or this many article sites, carry it. */
    private const int MINIMUM_ARTICLE_SOURCES = 2;

    private const string ARTICLE_FEEDS
        = 'COUNT(DISTINCT CASE WHEN member_feed.network IS NULL THEN story_member.feed_id END)';

    private const array VISIBILITY_TYPES = [
        'user' => ParameterType::INTEGER,
        'minimum' => ParameterType::INTEGER,
        'minimumArticleSources' => ParameterType::INTEGER,
        'shorterWindowStoryIds' => ArrayParameterType::INTEGER,
    ];

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
        /** @var list<array{story_id: int|string, outlet_count: int|string, last_seen: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            self::visibleStories()
            . ' AND NOT EXISTS (SELECT 1 FROM trending_story_entry hidden_member'
            . ' JOIN trending_hidden_entry hidden'
            . ' ON hidden.entry_id = hidden_member.entry_id AND hidden.user_id = :user'
            . ' WHERE hidden_member.story_id = story.id)'
            . self::visibleCoverage()
            . \sprintf(' ORDER BY outlet_count DESC, story.last_seen DESC, story.id DESC LIMIT %d', $limit),
            self::visibilityParameters($reader),
            self::VISIBILITY_TYPES,
        );

        return array_map(
            static fn (array $row): RankedStoryModel => new RankedStoryModel(
                (int) $row['story_id'],
                (int) $row['outlet_count'],
                new \DateTimeImmutable($row['last_seen'], new \DateTimeZone('UTC')),
            ),
            $rows,
        );
    }

    /**
     * @throws TrendingStoryNotFoundException when no story the reader is shown, hidden or not, holds the entry
     *                                        through a feed they follow
     */
    public function storyIdContaining(ReaderWindowModel $reader, int $entryId): int
    {
        $storyId = $this->getEntityManager()->getConnection()->fetchOne(
            self::visibleStories()
            . ' AND EXISTS (SELECT 1 FROM trending_story_entry asked'
            . ' JOIN subscription asker ON asker.feed_id = asked.feed_id AND asker.user_id = :user'
            . ' AND ' . TrendingInclusionSql::includes('asker')
            . ' WHERE asked.story_id = story.id AND asked.entry_id = :entry)'
            . self::visibleCoverage(),
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
     * @return ShownEntriesModel each story's articles and story-card posts of the feeds the reader follows
     */
    public function shownEntriesByStory(int $userId, array $storyIds): ShownEntriesModel
    {
        if ($storyIds === []) {
            return new ShownEntriesModel([]);
        }

        /** @var list<array{story_id: int|string, entry_id: int|string, url_hash: string|null}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT shown.story_id, shown.entry_id, COALESCE(landing.resolved_url_hash, link.url_hash) AS url_hash'
            . ' FROM trending_story_entry shown'
            . ' JOIN feed shown_feed ON shown_feed.id = shown.feed_id'
            . ' LEFT JOIN shared_link link ON link.id = shown.shared_link_id'
            . ' LEFT JOIN shared_link_resolution landing ON landing.shared_link_id = link.id'
            . ' JOIN subscription reader ON reader.feed_id = shown.feed_id AND reader.user_id = :user'
            . ' AND ' . TrendingInclusionSql::includes('reader')
            . ' WHERE shown.story_id IN (:storyIds)'
            . ' AND (shown_feed.network IS NULL OR shown.article_site IS NOT NULL)',
            ['user' => $userId, 'storyIds' => $storyIds],
            ['user' => ParameterType::INTEGER, 'storyIds' => ArrayParameterType::INTEGER],
        );

        $linkHashes = [];
        foreach ($rows as $row) {
            $linkHashes[(int) $row['story_id']][(int) $row['entry_id']] = $row['url_hash'];
        }

        return new ShownEntriesModel($linkHashes);
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

    /**
     * The window's stories, counting only feeds the reader follows in Trending, an article once and a post by its
     * account, leaving out any sharing an entry with a story the reader is shown in a shorter window; an `AND` on
     * `story`, then visibleCoverage(). An empty id list expands to `IN (NULL)`, which leaves nothing out.
     */
    private static function visibleStories(): string
    {
        return 'SELECT story.id AS story_id, story.last_seen,'
            . ' ' . self::sources() . ' AS outlet_count'
            . ' FROM trending_story story'
            . ' JOIN trending_story_entry story_member ON story_member.story_id = story.id'
            . ' JOIN feed member_feed ON member_feed.id = story_member.feed_id'
            . ' JOIN subscription subscribed'
            . ' ON subscribed.feed_id = story_member.feed_id AND subscribed.user_id = :user'
            . ' AND ' . TrendingInclusionSql::includes('subscribed')
            . ' JOIN entry member_entry ON member_entry.id = story_member.entry_id'
            . ' WHERE story.time_window = :window'
            . self::noEarlierCopy()
            . ' AND NOT EXISTS (SELECT 1 FROM trending_story_entry overlap_member'
            . ' JOIN trending_story_entry shorter_member ON shorter_member.entry_id = overlap_member.entry_id'
            . ' WHERE overlap_member.story_id = story.id AND shorter_member.story_id IN (:shorterWindowStoryIds))';
    }

    /** At least the reader's threshold of sources (the select's `outlet_count`), and two article feeds or two sites. */
    private static function visibleCoverage(): string
    {
        return ' GROUP BY story.id, story.last_seen'
            . ' HAVING outlet_count >= :minimum'
            . ' AND (' . self::ARTICLE_FEEDS . ' >= :minimumArticleSources'
            . ' OR COUNT(DISTINCT story_member.article_site) >= :minimumArticleSources)';
    }

    /**
     * A story's sources for the reader (#2229, #2056): each article feed they follow, and each social account whose
     * post shares a story card. An account is the post's author, else its feed when it names none, so a post's copies
     * in several timelines and two posts of one account count once.
     */
    private static function sources(): string
    {
        $sharesACard = 'member_feed.network IS NOT NULL AND story_member.article_site IS NOT NULL';

        return '(' . self::ARTICLE_FEEDS
            . ' + COUNT(DISTINCT CASE WHEN ' . $sharesACard . ' THEN member_entry.author_url END)'
            . ' + COUNT(DISTINCT CASE WHEN ' . $sharesACard . ' AND member_entry.author_url IS NULL'
            . ' THEN story_member.feed_id END))';
    }

    /**
     * As in DuplicateCollapseDql, only the lowest-id copy of an article the reader can see in the story counts;
     * the earlier copy must be one of theirs, or the article would count for no feed. A post counts by its account.
     */
    private static function noEarlierCopy(): string
    {
        return ' AND (member_feed.network IS NOT NULL'
            . ' OR NOT EXISTS (SELECT 1 FROM trending_story_entry earlier_member'
            . ' JOIN entry earlier_entry ON earlier_entry.id = earlier_member.entry_id'
            . ' JOIN feed earlier_feed ON earlier_feed.id = earlier_member.feed_id AND earlier_feed.network IS NULL'
            . ' JOIN subscription earlier_reader'
            . ' ON earlier_reader.feed_id = earlier_member.feed_id AND earlier_reader.user_id = :user'
            . ' AND ' . TrendingInclusionSql::includes('earlier_reader')
            . ' WHERE earlier_member.story_id = story.id AND earlier_member.entry_id < story_member.entry_id'
            . ' AND earlier_entry.url_hash = member_entry.url_hash))';
    }

    /**
     * @return array{
     *     user: int,
     *     window: string,
     *     minimum: int,
     *     minimumArticleSources: int,
     *     shorterWindowStoryIds: list<int>,
     * }
     */
    private static function visibilityParameters(ReaderWindowModel $reader): array
    {
        return [
            'user' => $reader->userId,
            'window' => $reader->window->value,
            'minimum' => $reader->minimumOutlets,
            'minimumArticleSources' => self::MINIMUM_ARTICLE_SOURCES,
            'shorterWindowStoryIds' => $reader->shorterWindowStoryIds,
        ];
    }
}
