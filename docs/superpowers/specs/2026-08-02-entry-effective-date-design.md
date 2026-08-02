# Entry list sorts without an index — materialize `effective_date` (#245)

## Problem

Every entry-list request runs `EntryRepository::listForUser`, which orders by
`COALESCE(e.publishedAt, e.createdAt) DESC, e.id DESC` and repeats the same
expression in the cursor predicate and the unread-view predicate. No index can
serve an expression sort — the only index on `entry` is
`(feed_id, published_at)` — so MySQL joins all entries of all subscribed feeds
and filesorts them, on every request, for every page. Cost grows with total
entry count.

The same `COALESCE(publishedAt, createdAt)` expression appears in five places:

1. `EntryRepository::listForUser` — order by, cursor predicate, unread view.
2. `EntryStateRepository` — the unread-count query's watermark comparison.
3. `MarkReadService` — the mark-all-read watermark update.
4. `EntryPruner` — the age cutoff and the per-feed keep-newest ordering.
5. `EntryCursor` — documented as the cursor's date semantics.

A response cache was considered and rejected in #181: it inherits nine
invalidation paths, cross-user staleness, and torn cursor pagination. The
expense is self-inflicted; fix the query instead.

## Decision

### Retire the published-date repair path first

`EntryIngestor::correctPublishedDates` and `BackfillPublishedDatesCommand`
were a one-time repair for rows ingested before feed dates were normalised to
UTC (#48/#50). The repair has run; the path is dead weight and it is the only
thing that ever rewrites `publishedAt` after insert. Both are removed, along
with `CorrectPublishedDatesTest`. Nothing else references them.

### An entity-maintained column

With the repair path gone, `publishedAt` is written only at creation time —
by ingest and by the e2e seed command, both via `setPublishedAt()`. `Entry`
still owns the invariant, because it costs nothing and prevents any future
writer from causing drift:

- New non-null column `entry.effective_date` (`DateTimeImmutable`, naive UTC
  like every other datetime).
- The constructor initialises it to `createdAt`.
- `setPublishedAt()` recomputes it: `publishedAt ?? createdAt`.
- There is **no** public `setEffectiveDate()`. The column cannot be written
  directly, so it cannot disagree with its sources.

Both remaining writers — ingest and the e2e seed — go through
`setPublishedAt()` and stay correct without being touched.

### Two indexes, replacing one

- **`(effective_date, id)`** serves the default cross-feed list: MySQL walks
  the index newest-first, probes the subscription join per row, and stops at
  `LIMIT`. This is the top-N pattern that eliminates the filesort for the
  common "all subscribed feeds" views.
- **`(feed_id, effective_date)`** replaces `idx_entry_feed_published`. It
  serves the single-subscription list, the pruner's per-feed keep-newest
  ordering, and the mark-all-read watermark update. Nothing queries
  `published_at` directly, so the old index goes.

### Replace the expression everywhere

All five call sites switch to the column. This is a correctness point, not
just a speed point: the watermark comparisons (unread view, unread counts,
mark-all-read) must use the same date the list sorts by, or an entry can be
marked read by a watermark yet sort as unread. One column, one meaning.

`EntryCursor` needs no format change — its `date` field already carries
`publishedAt ?? createdAt`, which is exactly `effective_date`.

## Scope

### Removals

- `backend/src/Service/EntryIngestor.php` — delete `correctPublishedDates()`.
- `backend/src/Command/BackfillPublishedDatesCommand.php` — delete.
- `backend/tests/Service/CorrectPublishedDatesTest.php` — delete.

### `backend/src/Entity/Entry.php`

- Add the `effectiveDate` property, non-null, initialised in the constructor.
- `setPublishedAt()` also assigns `effectiveDate = publishedAt ?? createdAt`.
- Replace the `#[ORM\Index]` attributes as decided above.

### `backend/src/Repository/EntryRepository.php`

- `listForUser`: order by `e.effectiveDate DESC, e.id DESC` directly (the
  `HIDDEN` alias goes away); cursor predicate and unread-view predicate use
  the column.

### `backend/src/Repository/EntryStateRepository.php`

- The unread-count watermark comparison uses the column.

### `backend/src/Service/Reader/MarkReadService.php`

- The watermark `UPDATE`'s date comparison uses the column.

### `backend/src/Service/EntryPruner.php`

- The age cutoff and the per-feed ordering use the column; the "COALESCE
  can't sit in ORDER BY" workaround comment goes away.

### `backend/migrations/`

- One migration: add the column nullable, backfill with
  `UPDATE entry SET effective_date = COALESCE(published_at, created_at)`,
  tighten to `NOT NULL`, drop `idx_entry_feed_published`, create the two new
  indexes. Must run from empty and on a populated database, on both MySQL and
  SQLite — the dedicated CI migration leg is the real check, because the test
  bootstrap builds schema from ORM metadata and never executes migrations.

## What does not change

- The API: response shapes, cursor encoding, and pagination behaviour are
  identical.
- `publishedAt` and `createdAt` columns and their meanings.
- The refresh flow, and no cache of any kind is introduced.

## Verification

- Entity test: construction sets `effectiveDate` to `createdAt`;
  `setPublishedAt(date)` moves it; `setPublishedAt(null)` falls back to
  `createdAt`.
- Repository tests: existing ordering/cursor/view tests must pass unchanged —
  they pin the observable behaviour this change must preserve.
- Both suite legs: `php bin/phpunit` (SQLite) and
  `docker compose exec php vendor/bin/phpunit` (MySQL).
- Migration: the CI migration leg (migrate from empty on SQLite and MySQL,
  then `doctrine:schema:validate`).
- `EXPLAIN` the generated list SQL against the Docker MySQL with seeded data:
  before shows `Using filesort`; after shows an index-backed walk with no
  filesort for the default view.
- `composer check`, `composer md`, PhpStorm inspections on touched files.
