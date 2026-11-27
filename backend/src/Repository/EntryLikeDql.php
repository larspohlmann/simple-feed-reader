<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscussionVote;
use App\Entity\EntryState;
use App\Entity\SocialLike;
use App\Enum\VoteScore;

/** The one DQL definition of "the reader liked or upvoted its post, or liked the entry itself"; the caller binds :user. */
final class EntryLikeDql
{
    public static function exists(EntryAliases $aliases): string
    {
        $like = 'like' . ucfirst($aliases->entry);
        $upvote = 'upvote' . ucfirst($aliases->entry);
        $localLike = 'localLike' . ucfirst($aliases->entry);

        return \sprintf(
            '(EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.canonicalItem = %5$s.canonicalItem AND %2$s.user = :user)'
            . ' OR EXISTS (SELECT 1 FROM %3$s %4$s WHERE %4$s.canonicalItem = %5$s.canonicalItem'
            . ' AND %4$s.user = :user AND %4$s.score = %6$d)'
            . ' OR EXISTS (SELECT 1 FROM %7$s %8$s WHERE %8$s.entry = %5$s AND %8$s.user = :user'
            . ' AND %8$s.likedAt IS NOT NULL))',
            SocialLike::class,
            $like,
            DiscussionVote::class,
            $upvote,
            $aliases->entry,
            VoteScore::Up->value,
            EntryState::class,
            $localLike,
        );
    }
}
