<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingStory;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\ReaderWindowModel;
use App\Service\Curation\Trending\Model\StoryTitleModel;
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
    /** A story is shown only when this many article feeds, or this many article sites, carry it. */
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
     * The stories the reader is shown, leaving out those they hid: widest first, then newest.
     *
     * @return list<RankedStoryModel>
     */
    public function rankedForUser(ReaderWindowModel $reader, int $limit): array
    {
        /** @var list<array{story_id: int|string, outlet_count: int|string, last_seen: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            self::visibleStories($reader)
            . self::visibleCoverage()
            . \sprintf(' ORDER BY outlet_count DESC, story.last_seen DESC, story.id DESC LIMIT %d', $limit),
            self::visibilityParameters($reader),
            self::VISIBILITY_TYPES,
        );

        return array_map(
            static fn (array $row): RankedStoryModel => new RankedStoryModel(
                (int) $row['story_id'],
                (int) $row['outlet_count'],
                NaiveUtc::parse($row['last_seen']),
            ),
            $rows,
        );
    }

    /**
     * @param list<int> $storyIds
     *
     * @return array<int, StoryTitleModel> the stories that have a title
     */
    public function titlesOf(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        /** @var list<array{id: int|string, title: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, COALESCE(llm_title, fallback_title) AS title FROM trending_story'
            . ' WHERE id IN (:storyIds) AND COALESCE(llm_title, fallback_title) IS NOT NULL',
            ['storyIds' => $storyIds],
            ['storyIds' => ArrayParameterType::INTEGER],
        );

        $titles = [];
        foreach ($rows as $row) {
            $titles[(int) $row['id']] = new StoryTitleModel($row['title']);
        }

        return $titles;
    }

    public function requireOwnedBy(int $userId, int $storyId): void
    {
        $found = $this->getEntityManager()->getConnection()
            ->fetchOne('SELECT 1 FROM trending_story WHERE id = ? AND user_id = ?', [$storyId, $userId]);
        if ($found === false) {
            throw new TrendingStoryNotFoundException(
                \sprintf('User %d has no Trending story with id %d.', $userId, $storyId),
            );
        }
    }

    /** @param list<int> $storyIds */
    public function shownEntriesByStory(TrendingWindow $window, array $storyIds): ShownEntriesModel
    {
        if ($storyIds === []) {
            return new ShownEntriesModel([]);
        }

        /** @var list<array{story_id: int|string, entry_id: int|string, url_hash: string|null}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT shown.story_id, shown.entry_id, ' . LinkLandingSql::URL_HASH . ' AS url_hash'
            . ' FROM trending_story_entry shown'
            . ' JOIN feed shown_feed ON shown_feed.id = shown.feed_id'
            . ' LEFT JOIN shared_link link ON link.id = shown.shared_link_id'
            . LinkLandingSql::JOIN
            . ' WHERE shown.story_id IN (:storyIds) AND ' . TrendingPlacementSql::placedIn($window, 'shown')
            . ' AND (shown_feed.network IS NULL OR shown.article_site IS NOT NULL)',
            ['storyIds' => $storyIds],
            ['storyIds' => ArrayParameterType::INTEGER],
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
     * @return list<int> the stories one of whose entries in the window, social ones included, the reader opened
     */
    public function viewedStoryIds(int $userId, TrendingWindow $window, array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        /** @var list<int|string> $viewed */
        $viewed = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT opened.story_id FROM trending_story_entry opened'
            . ' JOIN entry_state reading ON reading.entry_id = opened.entry_id'
            . ' AND reading.user_id = :user AND reading.is_viewed = 1'
            . ' WHERE opened.story_id IN (:storyIds) AND ' . TrendingPlacementSql::placedIn($window, 'opened'),
            ['user' => $userId, 'storyIds' => $storyIds],
            ['user' => ParameterType::INTEGER, 'storyIds' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (int|string $storyId): int => (int) $storyId, $viewed);
    }

    /**
     * The reader's stories of the window, counting their members in it, an article once and a post by its account,
     * leaving out those they hid or are shown in a shorter window; an `AND` on `story`, then visibleCoverage().
     * MySQL 8.4 drops rows when the hide test is a NOT EXISTS beside the placement semijoin: keep the LEFT JOIN.
     */
    private static function visibleStories(ReaderWindowModel $reader): string
    {
        return 'SELECT story.id AS story_id, story.last_seen,'
            . ' ' . self::sources() . ' AS outlet_count'
            . ' FROM trending_story story'
            . ' JOIN trending_story_entry story_member ON story_member.story_id = story.id'
            . ' JOIN feed member_feed ON member_feed.id = story_member.feed_id'
            . ' JOIN entry member_entry ON member_entry.id = story_member.entry_id'
            . ' LEFT JOIN trending_story_hide hidden ON hidden.story_id = story.id AND hidden.user_id = :user'
            . ' WHERE ' . implode(' AND ', [
                'story.user_id = :user',
                'hidden.story_id IS NULL',
                TrendingPlacementSql::placedIn($reader->window, 'story_member'),
                self::noEarlierCopy($reader->window),
                ...self::notShownInAShorterWindow($reader),
            ]);
    }

    /**
     * Without this guard an empty list would expand to `NOT IN (NULL)`, which leaves every story out.
     *
     * @return list<string>
     */
    private static function notShownInAShorterWindow(ReaderWindowModel $reader): array
    {
        return $reader->shorterWindowStoryIds === [] ? [] : ['story.id NOT IN (:shorterWindowStoryIds)'];
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
     * A story's sources (#2229, #2056): each article feed, and each social account whose
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
     * As in DuplicateCollapseDql, only the lowest-id copy of an article in the story counts. A post counts by its
     * account.
     */
    private static function noEarlierCopy(TrendingWindow $window): string
    {
        return '(member_feed.network IS NOT NULL'
            . ' OR NOT EXISTS (SELECT 1 FROM trending_story_entry earlier_member'
            . ' JOIN entry earlier_entry ON earlier_entry.id = earlier_member.entry_id'
            . ' JOIN feed earlier_feed ON earlier_feed.id = earlier_member.feed_id AND earlier_feed.network IS NULL'
            . ' WHERE earlier_member.story_id = story.id'
            . ' AND ' . TrendingPlacementSql::placedIn($window, 'earlier_member')
            . ' AND earlier_member.entry_id < story_member.entry_id'
            . ' AND earlier_entry.url_hash = member_entry.url_hash))';
    }

    /**
     * @return array{user: int, minimum: int, minimumArticleSources: int, shorterWindowStoryIds: list<int>}
     */
    private static function visibilityParameters(ReaderWindowModel $reader): array
    {
        return [
            'user' => $reader->userId,
            'minimum' => $reader->minimumOutlets,
            'minimumArticleSources' => self::MINIMUM_ARTICLE_SOURCES,
            'shorterWindowStoryIds' => $reader->shorterWindowStoryIds,
        ];
    }
}
