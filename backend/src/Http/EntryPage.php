<?php

declare(strict_types=1);

namespace App\Http;

use App\Pagination\EntryCursor;
use App\Repository\EntryListRow;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;

/** The `{entries, nextCursor}` shape of every keyset-paginated entry list; the one place the cursor is decided. */
final readonly class EntryPage
{
    private function __construct()
    {
    }

    /**
     * @param list<EntryListRow> $rows
     * @param int                $limit the clamped `EntryQuery::$limit`, never the raw `?limit=`: against a raw 500,
     *                                  a full page of MAX_LIMIT rows would look short and end the list
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function of(array $rows, int $limit, EntryListSort $sort): array
    {
        return self::withMatchCount($rows, $limit, \count($rows), $sort);
    }

    /**
     * As of(), for a read that can return fewer rows than it matched (search hydration drops ids the caller may not
     * see): $matchCount decides whether a next page exists, and a post-filtered read resumes past $continuationRow,
     * its last candidate, so a page whose rows were all filtered out still advances.
     *
     * @param list<EntryListRow> $rows
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function withMatchCount(
        array $rows,
        int $limit,
        int $matchCount,
        EntryListSort $sort,
        ?EntryListRow $continuationRow = null,
    ): array {
        $resumeAfter = $continuationRow ?? ($rows[array_key_last($rows)] ?? null);
        $nextCursor = $matchCount >= $limit ? self::cursorFromRow($resumeAfter, $sort) : null;

        return [
            'entries' => array_map(static fn ($row) => EntryJson::listRow($row), $rows),
            'nextCursor' => $nextCursor,
        ];
    }

    private static function cursorFromRow(?EntryListRow $row, EntryListSort $sort): ?string
    {
        // Every candidate was dropped by hydration: no row to resume past, so the list ends. The next
        // app:search:reindex clears the ghost ids.
        if ($row === null) {
            return null;
        }

        return EntryCursor::encode($sort->instantOf($row), $row->entry->requireId());
    }
}
