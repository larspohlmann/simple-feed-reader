<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The single definition of "unread" in DQL, previously duplicated between
 * EntryRepository::applyView and EntryStateRepository::unreadCountsForUser
 * (and now needed a third time by the recommendation candidate pool).
 * Aliases are fixed: e = Entry, es = EntryState, s = Subscription.
 * Callers must bind :readFalse to false with Types::BOOLEAN.
 */
final class UnreadDql
{
    public static function predicate(): string
    {
        return 'es.isHidden = :readFalse '
            . 'OR (es.isHidden IS NULL AND (s.markedReadUntil IS NULL '
            . 'OR e.effectiveDate > s.markedReadUntil))';
    }
}
