<?php

declare(strict_types=1);

namespace App\Repository;

/** A Trending story's (aliased `story`) title: its written title, else its fallback, else the newest member headline
 * from a feed its reader subscribes to, an article feed's first. */
final class TrendingStoryTitleSql
{
    public const string TITLE = 'COALESCE(story.llm_title, story.fallback_title,'
        . ' (SELECT headline_entry.title FROM trending_story_entry headline_member'
        . ' JOIN entry headline_entry ON headline_entry.id = headline_member.entry_id'
        . ' JOIN feed headline_feed ON headline_feed.id = headline_member.feed_id'
        . ' JOIN subscription headline_reader'
        . ' ON headline_reader.feed_id = headline_member.feed_id AND headline_reader.user_id = story.user_id'
        . ' WHERE headline_member.story_id = story.id'
        . ' ORDER BY CASE WHEN headline_feed.network IS NULL THEN 0 ELSE 1 END,'
        . ' headline_entry.effective_date DESC, headline_entry.id DESC LIMIT 1))';

    private function __construct()
    {
    }
}
