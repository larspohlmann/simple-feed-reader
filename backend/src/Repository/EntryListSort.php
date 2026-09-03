<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Which instant a keyset-paginated list orders by. Every list but "viewed"
 * ranks by publish instant (effectiveDate); "viewed" is a reading history
 * ranked by when the caller opened it (EntryState.viewedAt).
 *
 * Kept in one place because it drives three things that must stay in
 * lockstep or pagination desyncs from its cursor: the ORDER BY column, the
 * keyset predicate, and the next cursor's instant. Splitting these across
 * the repository and serializer is how a cursor and its list drift apart.
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
     * cursor. For ViewedAt it is EntryState.viewedAt, guaranteed set by the
     * "viewed" view's `es.isViewed = true` filter and by markViewed() always
     * stamping it. A null here means the projection and filter disagree — a
     * fault, not a case to paper over.
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
