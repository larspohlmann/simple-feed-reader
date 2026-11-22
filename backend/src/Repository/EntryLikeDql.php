<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscussionVote;
use App\Entity\SocialLike;

/** The one DQL definition of "the reader liked or upvoted its post"; the caller binds :user and :upvote. */
final class EntryLikeDql
{
    public static function exists(EntryAliases $aliases): string
    {
        return self::buildLikedCondition($aliases->entry);
    }

    public static function buildLikedCondition(string $entryAlias): string
    {
        $like = 'like' . ucfirst($entryAlias);
        $upvote = 'upvote' . ucfirst($entryAlias);

        return \sprintf(
            '(EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.canonicalItem = %5$s.canonicalItem AND %2$s.user = :user)'
            . ' OR EXISTS (SELECT 1 FROM %3$s %4$s WHERE %4$s.canonicalItem = %5$s.canonicalItem'
            . ' AND %4$s.user = :user AND %4$s.score = :upvote))',
            SocialLike::class,
            $like,
            DiscussionVote::class,
            $upvote,
            $entryAlias,
        );
    }
}
