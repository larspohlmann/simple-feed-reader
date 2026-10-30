# SQLite search through a native FTS5 index — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On SQLite, the database search ignores case *and* accents ("cafe" finds "Café", "arger" finds "Ärger") through SQLite's own FTS5 trigram index, and gets faster rather than slower. MySQL keeps the plain `LIKE` search `develop` has.

**Architecture:** An FTS5 virtual table `entry_search(title, summary, transcript)`, contentless with `contentless_delete=1` and `tokenize='trigram remove_diacritics 1'`, keyed by entry id. Six SQLite triggers on `entry` and `entry_transcript` keep it current, so no PHP write path changes. `SearchTermsPredicateBuilder` picks by platform: MySQL gets `develop`'s LIKE predicates unchanged (`LikeSearchPredicates`); SQLite gets `IndexSearchPredicates`. That class ANDs every term of three or more characters into one `MATCH` and adds a PHP-folded `LIKE` fallback for shorter terms, plus the whole-word check, which only runs on index candidates.

**Tech Stack:** SQLite ≥ 3.45 (trigram `remove_diacritics`), FTS5, Doctrine ORM 3 DQL functions, doctrine/migrations, PHP `intl` (`Normalizer`).

**Spec:** GitHub issue #1920 (extended to accents), replacing the PHP-folding approach first pushed to PR #1921.

## Global Constraints

- MySQL's SQL for search is byte-identical to `develop`'s.
- Probed facts (SQLite 3.53.4, `fts-probe.php`):
  - A trigram phrase under 3 characters matches nothing, and ANDed in, it empties the whole MATCH. Short pieces never reach the index.
  - `INSERT OR REPLACE` works on a contentless-delete table.
  - Triggers survive the FK cascade from `entry` to `entry_transcript`.
- FTS5 and `SearchFolding` fold alike for common letters (é, ä, ü, ó). Both leave ø, ł and ß alone, where MySQL would fold them. Accepted.
- The test bootstrap builds the schema from ORM metadata, so it must install the index itself.
- `schema_filter` must hide `entry_search*`, or `schema:validate` fails the CI migration leg.
- A SQLite migration that rebuilds `entry` or `entry_transcript` drops the triggers. `EntrySearchIndex`'s docblock says so, and the CLAUDE.md Gotchas gets one line.
- House style per CLAUDE.md. Commits `fix(#1920): …`.

## File Structure

| File | Role |
|---|---|
| Create `src/Doctrine/EntrySearchIndex.php` | The index DDL, triggers and backfill (install), and their removal |
| Create `src/Doctrine/SearchIndexMatchFunction.php` | DQL `SEARCH_INDEX_MATCHES(e.id, :query)` → `e.id IN (SELECT rowid FROM entry_search WHERE entry_search MATCH ?)` |
| Rename `src/Doctrine/CaseFolding.php` → `SearchFolding.php` | `fold()`: strip combining marks after NFD, then lower-case |
| Rename `src/Doctrine/FoldCaseFunction.php` → `SearchFoldFunction.php` | DQL/UDF `SEARCH_FOLD`, SQLite only |
| Modify `src/Doctrine/SqliteConnectionSetupDriver.php` | UDFs: `NORMALIZE_WORD_BOUNDARIES` = fold(normalize()), `SEARCH_FOLD` = fold() |
| Create `src/Service/Search/Support/SearchIndexQuery.php` | MATCH string from pieces; whole-word pieces; the 3-character floor |
| Modify `src/Service/Search/Support/LikePattern.php` | Back to `develop` (drop `anchor()`) |
| Create `src/Repository/LikeSearchPredicates.php` | `develop`'s builder body, moved verbatim (MySQL) |
| Create `src/Repository/IndexSearchPredicates.php` | The SQLite predicates |
| Create `src/Repository/SearchedColumns.php` | The title/summary/transcript-EXISTS OR both share |
| Modify `src/Repository/SearchTermsPredicateBuilder.php` | Platform dispatch only |
| Create `migrations/Version20261030170000.php` | SQLite: abort under 3.45, install; down: uninstall |
| Modify `tests/bootstrap.php` | Install the index on the SQLite test database |
| Modify `config/packages/doctrine.yaml` | Schema filter, DQL functions |

---

### Task 1: Folding, the index and its install

- [ ] Rename `CaseFolding` → `SearchFolding`:

```php
final readonly class SearchFolding
{
    public static function fold(string $value): string
    {
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);

        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $value : $decomposed) ?? $value);
    }
}
```

`isNeededFor()` goes; nothing uses it once LIKE stays native on MySQL.

- [ ] `EntrySearchIndex`:

```php
/**
 * The SQLite search index: FTS5 trigrams with diacritics removed, so MATCH finds a substring ignoring case and accents.
 * Triggers keep it current. A SQLite migration that rebuilds entry or entry_transcript drops them; run install() again.
 */
final readonly class EntrySearchIndex
{
    public const string TABLE = 'entry_search';

    private const string REFRESH = 'INSERT OR REPLACE INTO entry_search(rowid, title, summary, transcript)'
        . ' SELECT e.id, e.title, e.summary, t.search_text FROM entry e'
        . ' LEFT JOIN entry_transcript t ON t.entry_id = e.id WHERE e.id = %s;';

    /** @return list<string> */
    public static function install(): array
    {
        return [
            "CREATE VIRTUAL TABLE IF NOT EXISTS entry_search USING fts5(title, summary, transcript, content='',"
            . " contentless_delete=1, tokenize='trigram remove_diacritics 1')",
            self::trigger('entry_search_entry_insert', 'AFTER INSERT ON entry', \sprintf(self::REFRESH, 'new.id')),
            self::trigger('entry_search_entry_update', 'AFTER UPDATE OF title, summary ON entry', \sprintf(self::REFRESH, 'new.id')),
            self::trigger('entry_search_entry_delete', 'AFTER DELETE ON entry', 'DELETE FROM entry_search WHERE rowid = old.id;'),
            self::trigger('entry_search_transcript_insert', 'AFTER INSERT ON entry_transcript', \sprintf(self::REFRESH, 'new.entry_id')),
            self::trigger('entry_search_transcript_update', 'AFTER UPDATE OF search_text ON entry_transcript', \sprintf(self::REFRESH, 'new.entry_id')),
            self::trigger('entry_search_transcript_delete', 'AFTER DELETE ON entry_transcript', \sprintf(self::REFRESH, 'old.entry_id')),
            rtrim(\sprintf(self::REFRESH, 'e.id'), ';'),
        ];
    }
}
```

`uninstall()` drops the six triggers, then the table. The backfill's `WHERE e.id = e.id` covers every row.

- [ ] Migration `Version20261030170000`. `skipIf` not SQLite. `abortIf(version_compare(sqlite_version(), '3.45.0', '<'))` with the reason. `addSql` each `install()` statement. `down` does `uninstall()`.
- [ ] `tests/bootstrap.php`: after the SQLite `schema:create`, `new PDO('sqlite:' . $path)` and exec each statement.
- [ ] `doctrine.yaml`: `schema_filter: '~^(?!messenger_messages$|entry_search)~'`. `SEARCH_FOLD` replaces `FOLD_CASE`. `numeric_functions: SEARCH_INDEX_MATCHES`.
- [ ] Tests:
  - `SearchFoldingTest`: "ÄRGER Café Zürich" → "arger cafe zurich".
  - `SearchFoldOnSqliteTest`
  - `NormalizeWordBoundariesOnSqliteTest`: it folds too.
  - `EntrySearchIndexTest` (SQLite only, real entities through Doctrine, MATCH through DBAL): insert, title update, transcript insert/update/delete, entry delete.

### Task 2: Platform-split predicates

- [ ] `SearchedColumns::anyOf(\Closure $columnMatch, string $parameter, string $entryAlias): string`. This is `develop`'s `anyColumn()`, moved.
- [ ] `LikeSearchPredicates`: `develop`'s `build()`, `substringMatch()` and `wholeWordMatch()`, verbatim.
- [ ] `SearchIndexQuery`:
  - `MIN_PIECE_LENGTH = 3`
  - `isIndexable(string): bool`
  - `allOf(list<string>): string`, which double-quotes each piece (doubling `"`) and joins with ` AND `
  - `wholeWordPieces(string $term): list<string>`, the indexable parts of `WordBoundaries::normalize($term)` split on spaces
- [ ] `IndexSearchPredicates::build()`:
  - Collect the indexed pieces. A substring term of 3+ characters goes in whole; a whole-word term contributes its pieces.
  - A short substring term becomes `SearchedColumns::anyOf(SEARCH_FOLD(col) LIKE :p)`, with `p = containing(fold(term))`.
  - Every whole-word term becomes `anyOf(CONCAT(' ', NORMALIZE_WORD_BOUNDARIES(col), ' ') LIKE :w)`, with `w = wholeWord(fold(term))`.
  - When pieces exist, `SEARCH_INDEX_MATCHES(alias.id, :{prefix}Index) = 1` goes first.
- [ ] `SearchTermsPredicateBuilder(LikeSearchPredicates, IndexSearchPredicates)` dispatches on `SQLitePlatform`. Update the four `new SearchTermsPredicateBuilder()` call sites in tests.
- [ ] Tests:
  - `EntrySearchTest`: cafe/Café both ways; ärger/arger; short term "öl"; whole word "cafe " vs "Cafeteria"; transcript "uber den ".
  - `DatabaseSavedSearchMatcherTest`: "cafe".
  - `SearchIndexQueryTest`
  - `SearchTermsPredicateBuilderTest`: SQLite renders `SEARCH_INDEX_MATCHES` and no `LIKE` for "angular", and `SEARCH_FOLD` for "öl". MySQL renders three `LIKE`s and neither function.
  - Drop the `anchor()` tests.

### Task 3: Gates

- [ ] `composer check`, `composer md`, PhpStorm lint, both legs, `composer infection:diff`.
- [ ] Run the CI migration leg locally on SQLite: `migrations:migrate` from an empty file, then `doctrine:schema:validate`.
- [ ] Benchmark recap; `/simplify`; update #1920 and PR #1921.
