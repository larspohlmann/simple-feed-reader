<?php

declare(strict_types=1);

namespace App\Repository;

/** The one DQL definition of "unread"; the caller binds :notHidden to false as Types::BOOLEAN. */
final class UnreadDql
{
    public static function predicate(?EntryAliases $aliases = null): string
    {
        $alias = $aliases ?? EntryAliases::primary();

        return \sprintf(
            '%1$s.isHidden = :notHidden OR (%1$s.isHidden IS NULL AND '
            . '(%2$s.markedReadUntil IS NULL OR %3$s.effectiveDate > %2$s.markedReadUntil))',
            $alias->state,
            $alias->subscription,
            $alias->entry,
        );
    }
}
