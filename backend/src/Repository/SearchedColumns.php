<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryTranscript;

/** The columns a search reads: title, summary and transcript text, any of which may hold a term. */
final readonly class SearchedColumns
{
    /**
     * A summary is nullable and an entry may have no transcript row, and NULL LIKE … is never true, so the ORs alone
     * handle both. The transcript is reached by a correlated EXISTS over its unique entry_id, never a join.
     *
     * @param \Closure(string): string $columnMatch
     */
    public static function anyOf(\Closure $columnMatch, string $parameter, string $entryAlias): string
    {
        $transcript = 'transcript' . $entryAlias . $parameter;

        return \sprintf(
            '(%s OR %s OR EXISTS (SELECT %s.id FROM %s %s WHERE %s.entry = %s AND %s))',
            $columnMatch($entryAlias . '.headline.title'),
            $columnMatch($entryAlias . '.summary'),
            $transcript,
            EntryTranscript::class,
            $transcript,
            $transcript,
            $entryAlias,
            $columnMatch($transcript . '.searchText'),
        );
    }
}
