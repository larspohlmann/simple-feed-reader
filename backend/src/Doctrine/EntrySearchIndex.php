<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * The SQLite search index: FTS5 trigrams with accents removed, so MATCH finds a substring whatever its case or accents.
 * Triggers keep it current. A SQLite migration that rebuilds entry or entry_transcript drops them; run install() again.
 */
final readonly class EntrySearchIndex
{
    public const string TABLE = 'entry_search';

    private const string REFRESH_ROW = 'INSERT OR REPLACE INTO entry_search(rowid, title, summary, transcript)'
        . ' SELECT e.id, e.title, e.summary, t.search_text FROM entry e'
        . ' LEFT JOIN entry_transcript t ON t.entry_id = e.id WHERE e.id = %s';

    private const array TRIGGERS = [
        'entry_search_entry_insert' => ['AFTER INSERT ON entry', 'new.id'],
        'entry_search_entry_update' => ['AFTER UPDATE OF title, summary ON entry', 'new.id'],
        'entry_search_transcript_insert' => ['AFTER INSERT ON entry_transcript', 'new.entry_id'],
        'entry_search_transcript_update' => ['AFTER UPDATE OF search_text ON entry_transcript', 'new.entry_id'],
        'entry_search_transcript_delete' => ['AFTER DELETE ON entry_transcript', 'old.entry_id'],
    ];

    private const string ENTRY_DELETE_TRIGGER = 'entry_search_entry_delete';

    /** @return list<string> the table, its triggers, and every existing entry indexed; safe to run again */
    public static function install(): array
    {
        $statements = [
            "CREATE VIRTUAL TABLE IF NOT EXISTS entry_search USING fts5(title, summary, transcript, content='',"
            . " contentless_delete=1, tokenize='trigram remove_diacritics 1')",
        ];
        foreach (self::TRIGGERS as $name => [$event, $entryId]) {
            $statements[] = self::trigger($name, $event, \sprintf(self::REFRESH_ROW, $entryId));
        }
        $statements[] = self::trigger(
            self::ENTRY_DELETE_TRIGGER,
            'AFTER DELETE ON entry',
            'DELETE FROM entry_search WHERE rowid = old.id',
        );
        $statements[] = \sprintf(self::REFRESH_ROW, 'e.id');

        return $statements;
    }

    /** @return list<string> */
    public static function uninstall(): array
    {
        $statements = [];
        foreach ([...array_keys(self::TRIGGERS), self::ENTRY_DELETE_TRIGGER] as $name) {
            $statements[] = 'DROP TRIGGER IF EXISTS ' . $name;
        }
        $statements[] = 'DROP TABLE IF EXISTS entry_search';

        return $statements;
    }

    private static function trigger(string $name, string $event, string $statement): string
    {
        return \sprintf('CREATE TRIGGER IF NOT EXISTS %s %s BEGIN %s; END', $name, $event, $statement);
    }
}
