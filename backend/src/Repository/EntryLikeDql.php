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
        $localLike = 'localLike' . ucfirst($aliases->entry);

        return \sprintf(
            '(%1$s OR EXISTS (SELECT 1 FROM %2$s %3$s WHERE %3$s.entry = %4$s AND %3$s.user = :user AND %5$s))',
            self::buildNetworkLikeCondition($aliases),
            EntryState::class,
            $localLike,
            $aliases->entry,
            self::buildLocalLikeCondition($localLike),
        );
    }

    /** For a query that already joins the reader's own state of the entry as $aliases->state. */
    public static function existsOnJoinedState(EntryAliases $aliases): string
    {
        return \sprintf(
            '(%s OR %s)',
            self::buildNetworkLikeCondition($aliases),
            self::buildLocalLikeCondition($aliases->state),
        );
    }

    public static function buildLocalLikeCondition(string $stateAlias): string
    {
        return $stateAlias . '.likedAt IS NOT NULL';
    }

    public static function buildUpvoteCondition(string $voteAlias): string
    {
        return \sprintf('%s.score = %d', $voteAlias, VoteScore::Up->value);
    }

    private static function buildNetworkLikeCondition(EntryAliases $aliases): string
    {
        $like = 'like' . ucfirst($aliases->entry);
        $upvote = 'upvote' . ucfirst($aliases->entry);

        return \sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.canonicalItem = %5$s.canonicalItem AND %2$s.user = :user)'
            . ' OR EXISTS (SELECT 1 FROM %3$s %4$s WHERE %4$s.canonicalItem = %5$s.canonicalItem'
            . ' AND %4$s.user = :user AND %6$s)',
            SocialLike::class,
            $like,
            DiscussionVote::class,
            $upvote,
            $aliases->entry,
            self::buildUpvoteCondition($upvote),
        );
    }
}
