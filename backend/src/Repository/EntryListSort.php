<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Which instant a keyset-paginated entry list orders by. Every list but the
 * "viewed" one ranks by the entry's publish instant (effectiveDate); the
 * "viewed" list is a reading history and ranks by when the caller opened each
 * entry (the per-caller EntryState.viewedAt).
 *
 * The choice lives in one place because it drives three things that MUST stay
 * in lockstep or pagination silently desyncs from its own cursor: the ORDER BY
 * column, the keyset "before" predicate, and the instant encoded into the next
 * cursor. Splitting them across the repository and the page serializer is
 * exactly how a cursor and its list drift apart.
 */
enum EntryListSort
{
    case PublishedDate;
    case ViewedAt;

    /**
     * The sort every date-ordered list shares, and the fallback for a view
     * that names no history instant — only "viewed" reorders by view time.
     */
    public static function forView(string $view): self
    {
        return $view === 'viewed' ? self::ViewedAt : self::PublishedDate;
    }

    /**
     * The DQL expression this sort orders and keyset-filters on. Both aliases
     * (`e` for the entry, `es` for the caller's state row) are present in the
     * shared list-row query builder, so either is safe to name here.
     */
    public function orderColumn(): string
    {
        return match ($this) {
            self::PublishedDate => 'e.effectiveDate',
            self::ViewedAt => 'es.viewedAt',
        };
    }

    /**
     * The instant of a row for this sort — the value that becomes the next
     * cursor. For ViewedAt it is EntryState.viewedAt, which the "viewed" view's
     * `es.isViewed = true` filter guarantees is set: a row cannot reach this
     * page unviewed, and markViewed() always stamps viewedAt. A null here would
     * therefore mean the projection and the filter disagree, so it is a fault,
     * not a case to paper over.
     */
    public function instantOf(EntryListRow $row): \DateTimeImmutable
    {
        return match ($this) {
            self::PublishedDate => $row->entry->getEffectiveDate(),
            self::ViewedAt => $row->viewedAt ?? throw new \LogicException(
                'A viewed entry list row must carry a viewedAt instant.',
            ),
        };
    }
}
