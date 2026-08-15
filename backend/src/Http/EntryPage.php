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
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function of(array $rows, int $limit): array
    {
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $nextCursor = null;
        // A full page implies there may be more; hand back a cursor from the
        // last row. (A short page cannot have a next page.)
        if ($last !== null && \count($rows) >= min(max(1, $limit), EntryQuery::MAX_LIMIT)) {
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
