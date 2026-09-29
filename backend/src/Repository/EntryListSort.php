<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\EntryView;

/**
 * Which instant a keyset list orders by: effectiveDate, except "viewed", a reading history ordered by viewedAt. The
 * ORDER BY column, the keyset predicate and the next cursor's instant all come from here, or cursor and list drift.
 */
enum EntryListSort
{
    case PublishedDate;
    case ViewedAt;

    /**
     * The sort every date-ordered list shares, and the fallback for a view
     * that names no history instant — only "viewed" reorders by view time.
     */
    public static function forView(EntryView $view): self
    {
        return $view === EntryView::Viewed ? self::ViewedAt : self::PublishedDate;
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
     * The row's instant for this sort, which becomes the next cursor. A null viewedAt means the projection and the
     * "viewed" filter disagree: a fault, never a case to paper over.
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
