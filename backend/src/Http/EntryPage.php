<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListRow;
use App\Repository\EntryQuery;

/**
 * The `{entries, nextCursor}` shape every keyset-paginated entry list
 * returns. One rule, shared by the entry list and (later) entry search, so
 * the keyset-cursor decision exists exactly once.
 */
final readonly class EntryPage
{
    private function __construct()
    {
    }

    /**
     * @param list<EntryListRow> $rows
     * @param int                $limit the EFFECTIVE page size the read used
     *                                  (`EntryQuery::$limit` / `EntrySearchQuery::$limit`,
     *                                  both clamped at construction) — never a raw
     *                                  request value, or a page of MAX_LIMIT rows
     *                                  answered to `?limit=500` would look short
     *                                  and silently end the list
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function of(array $rows, int $limit): array
    {
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $nextCursor = null;
        // A full page implies there may be more; hand back a cursor from the
        // last row. (A short page cannot have a next page.)
        if ($last !== null && \count($rows) >= $limit) {
            $entry = $last->entry;
            $entryId = $entry->getId() ?? throw new \LogicException(
                'An entry loaded from the database must have an id.',
            );
            $nextCursor = EntryCursor::encode($entry->getEffectiveDate(), $entryId);
        }

        return [
            'entries' => array_map(static fn ($r) => EntryJson::one($r), $rows),
            'nextCursor' => $nextCursor,
        ];
    }
}
