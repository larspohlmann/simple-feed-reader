<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListRow;
use App\Repository\EntryListSort;
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
     * @param EntryListSort      $sort  the order the rows came back in, so the
     *                                  next cursor encodes the same instant the
     *                                  keyset predicate will compare against
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function of(array $rows, int $limit, EntryListSort $sort): array
    {
        return self::withMatchCount($rows, $limit, \count($rows), $sort);
    }

    /**
     * As of(), but for a caller whose row count can be lower than what the
     * underlying read actually matched: IndexedEntrySearch asks the search
     * engine for $limit ids, then hydrates through the caller's subscription
     * join, which silently drops any id the join's access check rejects (a
     * ghost id left by a failed async index delete, say). Deciding "is there
     * another page" from count($rows) then mistakes a full page of engine
     * matches for a short one and ends pagination early. $matchCount is the
     * read's own count before any such drop; search passes it explicitly, and
     * of() above just supplies count($rows) where nothing removes rows after.
     *
     * The cursor comes from the row the caller must resume past. That is the
     * last returned row for a plain read, but a post-filtered read (the indexed
     * unread search drops the read rows of a page after hydration) passes its
     * last candidate as $continuationRow so a fully-read page still advances
     * rather than ending the list. Either way the cursor names a real position,
     * never a dropped id's.
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
            'entries' => array_map(static fn ($r) => EntryJson::one($r), $rows),
            'nextCursor' => $nextCursor,
        ];
    }

    private static function cursorFromRow(?EntryListRow $row, EntryListSort $sort): ?string
    {
        // A full page whose every candidate was dropped by hydration leaves no
        // row to build a cursor from. Ending pagination here is the safe choice:
        // the ghost ids are cleared by the next app:search:reindex (or a later
        // page whose candidates DO survive reopens the cursor there).
        if ($row === null) {
            return null;
        }

        $entryId = $row->entry->getId() ?? throw new \LogicException(
            'An entry loaded from the database must have an id.',
        );

        return EntryCursor::encode($sort->instantOf($row), $entryId);
    }
}
