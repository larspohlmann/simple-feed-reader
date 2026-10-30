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

    private const string REFRESH_ROW = 'INSERT OR REPLACE INTO ' . self::TABLE . '(rowid, title, summary, transcript)'
        . ' SELECT e.id, e.title, e.summary, t.search_text FROM entry e'
        . ' LEFT JOIN entry_transcript t ON t.entry_id = e.id WHERE e.id = ';

    private const array TRIGGERS = [
        'entry_search_entry_insert' => ['AFTER INSERT ON entry', self::REFRESH_ROW . 'new.id'],
        'entry_search_entry_update' => ['AFTER UPDATE OF title, summary ON entry', self::REFRESH_ROW . 'new.id'],
        'entry_search_entry_delete' => [
            'AFTER DELETE ON entry',
            'DELETE FROM ' . self::TABLE . ' WHERE rowid = old.id',
        ],
        'entry_search_transcript_insert' => ['AFTER INSERT ON entry_transcript', self::REFRESH_ROW . 'new.entry_id'],
        'entry_search_transcript_update' => [
            'AFTER UPDATE OF search_text ON entry_transcript',
            self::REFRESH_ROW . 'new.entry_id',
        ],
        'entry_search_transcript_delete' => ['AFTER DELETE ON entry_transcript', self::REFRESH_ROW . 'old.entry_id'],
    ];

    /** @return list<string> the table, its triggers, and every existing entry indexed; safe to run again */
    public static function install(): array
    {
        $statements = [
            'CREATE VIRTUAL TABLE IF NOT EXISTS ' . self::TABLE . " USING fts5(title, summary, transcript, content='',"
            . " contentless_delete=1, tokenize='trigram remove_diacritics 1')",
        ];
        foreach (self::TRIGGERS as $name => [$event, $statement]) {
            $statements[] = \sprintf('CREATE TRIGGER IF NOT EXISTS %s %s BEGIN %s; END', $name, $event, $statement);
        }
        $statements[] = self::REFRESH_ROW . 'e.id';

        return $statements;
    }

    /** @return list<string> */
    public static function uninstall(): array
    {
        $statements = array_map(
            static fn (string $name): string => 'DROP TRIGGER IF EXISTS ' . $name,
            array_keys(self::TRIGGERS),
        );
        $statements[] = 'DROP TABLE IF EXISTS ' . self::TABLE;

        return $statements;
    }
}
