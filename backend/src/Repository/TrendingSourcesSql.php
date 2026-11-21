<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\TrendingWindow;

/** How Trending counts a story's sources over its members (aliased `story`, `story_member`, `member_feed`, `member_entry`, `member_item`). */
final class TrendingSourcesSql
{
    public const string MEMBERS = ' FROM trending_story story'
        . ' JOIN trending_story_entry story_member ON story_member.story_id = story.id'
        . ' JOIN feed member_feed ON member_feed.id = story_member.feed_id'
        . ' JOIN entry member_entry ON member_entry.id = story_member.entry_id'
        . ' LEFT JOIN canonical_item member_item ON member_item.id = member_entry.canonical_item_id';

    public const string ARTICLE_FEEDS
        = 'COUNT(DISTINCT CASE WHEN member_feed.network IS NULL THEN story_member.feed_id END)';

    /** Each post sharing a story card once, its timeline copies one post by their address. */
    public const string SHARES = 'COUNT(DISTINCT CASE WHEN ' . self::SHARES_A_CARD
        . " THEN COALESCE(member_entry.url_hash, CONCAT('e', member_entry.id)) END)";

    private const string SHARES_A_CARD = 'member_feed.network IS NOT NULL AND story_member.article_site IS NOT NULL';

    private function __construct()
    {
    }

    /**
     * A story's sources (#2229, #2056): each article feed, and each social account whose
     * post shares a story card. An account is the post's author, else its feed when it names none, so a post's copies
     * in several timelines and two posts of one account count once.
     */
    public static function sources(): string
    {
        return '(' . self::ARTICLE_FEEDS
            . ' + COUNT(DISTINCT CASE WHEN ' . self::SHARES_A_CARD . ' THEN member_entry.author_url END)'
            . ' + COUNT(DISTINCT CASE WHEN ' . self::SHARES_A_CARD . ' AND member_entry.author_url IS NULL'
            . ' THEN story_member.feed_id END))';
    }

    /**
     * Each copy counts once, among the window's placed members or, without a window, all the story's members.
     *
     * @return list<string>
     */
    public static function noEarlierCopies(?TrendingWindow $window): array
    {
        return [
            self::buildNoEarlierArticleCopy($window),
            self::buildNoEarlierItemCopy($window),
            self::buildNoEarlierCrossPost($window),
        ];
    }

    /** As in DuplicateCollapseDql, only the lowest-id copy of an article counts; a post counts by its account. */
    private static function buildNoEarlierArticleCopy(?TrendingWindow $window): string
    {
        return self::buildNoEarlierCopy(
            $window,
            'member_feed.network IS NOT NULL',
            ' JOIN feed earlier_feed ON earlier_feed.id = earlier_member.feed_id AND earlier_feed.network IS NULL',
            'earlier_entry.url_hash = member_entry.url_hash',
        );
    }

    /** Only the lowest-id copy of an item counts, in whatever feed: its copies' authors and feeds differ. */
    private static function buildNoEarlierItemCopy(?TrendingWindow $window): string
    {
        return self::buildNoEarlierCopy(
            $window,
            'member_entry.canonical_item_id IS NULL',
            '',
            'earlier_entry.canonical_item_id = member_entry.canonical_item_id',
        );
    }

    /** Only the lowest-id member of a cross-post group counts. */
    private static function buildNoEarlierCrossPost(?TrendingWindow $window): string
    {
        return self::buildNoEarlierCopy(
            $window,
            'member_item.cross_post_hash IS NULL',
            ' JOIN canonical_item earlier_item ON earlier_item.id = earlier_entry.canonical_item_id',
            'earlier_item.cross_post_hash = member_item.cross_post_hash',
        );
    }

    /** A member counts when `$exemption` holds, else when no placed member of its story with a lower entry id is `$sameCopy`. */
    private static function buildNoEarlierCopy(
        ?TrendingWindow $window,
        string $exemption,
        string $earlierJoin,
        string $sameCopy,
    ): string {
        $placed = $window === null ? [] : [TrendingPlacementSql::buildPlacedCondition($window, 'earlier_member')];

        return '(' . $exemption
            . ' OR NOT EXISTS (SELECT 1 FROM trending_story_entry earlier_member'
            . ' JOIN entry earlier_entry ON earlier_entry.id = earlier_member.entry_id' . $earlierJoin
            . ' WHERE ' . implode(' AND ', [
                'earlier_member.story_id = story.id',
                ...$placed,
                'earlier_member.entry_id < story_member.entry_id',
                $sameCopy,
            ]) . '))';
    }
}
