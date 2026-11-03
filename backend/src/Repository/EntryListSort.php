<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\EntryView;

/**
 * Which instant a keyset list orders by: effectiveDate, except "viewed" and "shared", histories ordered by when they
 * happened. The ORDER BY column, the keyset predicate and the next cursor's instant all come from here, or cursor and
 * list drift.
 */
enum EntryListSort
{
    case PublishedDate;
    case ViewedAt;
    case SharedAt;

    /**
     * The sort every date-ordered list shares, and the fallback for a view
     * that names no history instant — only "viewed" reorders by view time.
     */
    public static function forView(EntryView $view): self
    {
        return match ($view) {
            EntryView::Viewed => self::ViewedAt,
            EntryView::Shared => self::SharedAt,
            default => self::PublishedDate,
        };
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
            self::SharedAt => 'esh.sharedAt',
        };
    }

    /**
     * The row's instant for this sort, which becomes the next cursor. A null viewedAt or sharedAt means the
     * projection and the view's filter disagree: a fault, never a case to paper over.
     */
    public function instantOf(EntryListRow $row): \DateTimeImmutable
    {
        return match ($this) {
            self::PublishedDate => $row->entry->getEffectiveDate(),
            self::ViewedAt => $row->viewedAt ?? throw new \LogicException(
                'A viewed entry list row must carry a viewedAt instant.',
            ),
            self::SharedAt => $row->sharedAt ?? throw new \LogicException(
                'A shared entry list row must carry a sharedAt instant.',
            ),
        };
    }
}
