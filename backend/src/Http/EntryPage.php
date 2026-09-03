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
     * engine for $limit ids and then hydrates them through the caller's
     * subscription join, which silently drops any id the join's access
     * check rejects (a ghost id left behind by a failed async index delete,
     * for example). Deciding "is there another page" from count($rows) in
     * that case mistakes a full page of engine matches for a short one and
     * ends pagination early — the row count no longer means what of()
     * assumes. $matchCount is the read's own count, before any such drop;
     * search passes it explicitly, and of() above just supplies count($rows)
     * for every caller where nothing removes rows afterwards.
     *
     * The cursor value itself always comes from the last SURVIVING row, not
     * from $matchCount — the client only ever saw the rows it was handed, so
     * a cursor has to name one of those, never a dropped id's position.
     *
     * @param list<EntryListRow> $rows
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function withMatchCount(array $rows, int $limit, int $matchCount, EntryListSort $sort): array
    {
        $nextCursor = $matchCount >= $limit ? self::cursorFromLastRow($rows, $sort) : null;

        return [
            'entries' => array_map(static fn ($r) => EntryJson::one($r), $rows),
            'nextCursor' => $nextCursor,
        ];
    }

    /** @param list<EntryListRow> $rows */
    private static function cursorFromLastRow(array $rows, EntryListSort $sort): ?string
    {
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        // A full page of matches with every id dropped by hydration leaves no
        // surviving row to build a cursor from. Ending pagination here is the safe
        // choice: the ghost ids are cleared by the next app:search:reindex (or a
        // later page whose matches DO survive hydration reopens the cursor there).
        if ($last === null) {
            return null;
        }

        $entryId = $last->entry->getId() ?? throw new \LogicException(
            'An entry loaded from the database must have an id.',
        );

        return EntryCursor::encode($sort->instantOf($last), $entryId);
    }
}
