<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The single definition of "unread" in DQL, previously duplicated between
 * EntryRepository::applyView and EntryStateRepository::unreadCountsForUser
 * (and now needed a third time by the recommendation candidate pool).
 * Aliases default to the primary set (e = Entry, es = EntryState,
 * s = Subscription) but can be swapped for another EntryAliases instance.
 * Callers must bind :notHidden to false with Types::BOOLEAN.
 */
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
