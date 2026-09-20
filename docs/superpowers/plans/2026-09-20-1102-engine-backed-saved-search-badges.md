# Plan: #1102 — engine-backed saved-search badges with a database fallback

Spec: GitHub issue #1102 (binding authority). Architecture map (verify before relying):
`.superpowers/sdd/2026-09-20-1102-engine-backed-saved-search-badges/arch-map.md`.

## Context

`GET /api/saved-searches` computes each sidebar badge with a corpus-wide MySQL `LIKE`
scan over every unread entry (#584), 600–676 ms on a 19k-unread account — even when a
search engine is configured. It is also a correctness gap: the list and mark-read
already match through the engine (full content, typo tolerance) while the badge matches
through `LIKE` on title+summary, so the badge can disagree with the list it opens.

The list (#973, `SavedSearchEntriesWithFallback`) and mark-read
(`SavedSearchUnreadMatchesWithFallback`) already have an engine-path-with-DB-fallback.
The badge does not: `SavedSearchMatchIds` depends directly on `SavedSearchEntryRepository`.

## Global Constraints

- With an engine configured, the badge follows the ENGINE's matching (same rule #973 set),
  so badge, list and mark-read agree.
- The badge is EXACT: each search is keyset-paged through the engine to exhaustion. No cap;
  no change to Meilisearch `maxTotalHits`. Must be correct for searches with >1000 matches.
- Result stays UNREAD matching entry ids PER saved search (`array<savedSearchId, list<int>>`),
  not counts. Unread state, the subscription gate and the #496 collapse stay MySQL's job.
- Never mix an engine prefix with a `LIKE` tail: on not-configured OR any
  `SearchEngineUnavailableException`, recompute the WHOLE answer from the database.
- Wire format of `GET /api/saved-searches` is UNCHANGED; no frontend change; polling
  cadence unchanged; the DB fallback scan is not sped up here.
- House style: `final readonly` services, `declare(strict_types=1)`, guard clauses, intent
  names, comment-length rule, DI by interface. `composer check` + `composer md` clean;
  PhpStorm inspections clean. `infection:diff` 100% on changed lines.

## Design

### Interface + triple (mirror the two existing triples exactly)

- `App\Service\Search\SavedSearchBadgeSource` (interface):
  ```php
  /** @param list<SavedSearchTerm> $searches @return array<int, list<int>> savedSearchId => unread entryIds */
  public function unreadMatchIdsBySavedSearch(int $userId, array $searches): array;
  ```
- `DatabaseSavedSearchBadges implements SavedSearchBadgeSource` — delegates to
  `SavedSearchEntryRepository::unreadMatchIdsBySavedSearch` (today's behaviour, unchanged).
- `IndexedSavedSearchBadges implements SavedSearchBadgeSource` — the engine path (below).
  Depends on the RAW `SearchIndexReader` (not any fallback) so a mid-enumeration failure
  throws `SearchEngineUnavailableException` up to the WithFallback.
- `SavedSearchBadgesWithFallback implements SavedSearchBadgeSource` — copy the
  gate/try-catch/log body from `SavedSearchEntriesWithFallback` verbatim (message:
  `'Search engine unavailable; falling back to database saved-search badges.'`), delegating
  to `DatabaseSavedSearchBadges` on not-configured or exception.
- Wire in `config/services.yaml` next to the other two aliases:
  `App\Service\Search\SavedSearchBadgeSource: '@App\Service\Search\SavedSearchBadgesWithFallback'`.
- `SavedSearchMatchIds` constructor: depend on `SavedSearchBadgeSource` instead of
  `SavedSearchEntryRepository`; `forAll` calls `$this->badges->unreadMatchIdsBySavedSearch(...)`.
  `forAll`/`forOne` signatures and `SavedSearchController` do NOT change.

**Shape parity:** match the DB method's shape exactly so the WithFallback swap is
transparent — the DB method omits searches with zero matches (no key). `IndexedSavedSearchBadges`
must likewise omit a search with zero unread matches (do not emit an empty-list key). Prove
this with a parity assertion in tests.

### `IndexedSavedSearchBadges` algorithm

Constants: `ENGINE_PAGE = 1000` (named; Meilisearch default `maxTotalHits`),
`ID_FILTER_CHUNK = 5000`.

1. `feedIds = FeedRepository::idsSubscribedByUser($userId)`. If `feedIds === []` or
   `$searches === []` → return `[]` (no engine call).
2. **Enumerate candidate ids per search, keyset-paged to exhaustion.** Keep a per-search
   cursor (start `null` = newest) and a per-search accumulator. Each round:
   - Build one `IndexSearch($search->terms, $feedIds, $cursorForSearch, ENGINE_PAGE)` per
     *unexhausted* search, in a stable order; call `SearchIndexReader::findMany($batch)`
     (results returned in request order).
   - For each search: append the returned ids to its accumulator. If it returned exactly
     `ENGINE_PAGE` ids it MAY have more → keep it active and record its LAST id as the
     boundary; if it returned fewer, it is EXHAUSTED → drop it from the active set (never
     queried again).
   - Advance the still-active searches' cursors: resolve the boundary ids' `effectiveDate`s
     in ONE DB lookup (`effectiveDatesByIds`, below), and set each active search's next
     cursor to `new EntryCursor($effectiveDate[$lastId], $lastId)`. (The engine returns ids
     only, so the sort instant for the keyset cursor must come from the DB — `EntryCursor`
     needs `(effectiveDate, id)`.)
   - Stop when no search is active.
3. **Filter to unread in MySQL, once.** Union all candidate ids (dedup). Run
   `unreadCollapsedSubscribedIds(list<int> $ids, int $userId): list<int>` (new repository
   method, below) → the subset that is unread, survives the #496 collapse, and belongs to a
   subscribed feed. This selects `e.id` only, no hydration.
4. **Intersect per search.** For each search, keep its candidate ids that are in the unread
   set (preserve engine order). Omit a search whose intersection is empty (shape parity).

### New repository methods (on `EntryListRepository`, reusing its scope helpers)

- `effectiveDatesByIds(list<int> $entryIds): array<int, \DateTimeImmutable>` — a plain
  `SELECT e.id, e.effectiveDate ... WHERE e.id IN (:ids)` scalar query (no user scope: the
  ids are engine boundary ids already scoped to the caller's feeds). Return id => instant.
  Called only on boundary ids (few per round). Empty input → `[]`.
- `unreadCollapsedSubscribedIds(list<int> $entryIds, int $userId): list<int>` — the row
  query's subscription join + `EntryScopePredicates::applyIds` + `UnreadDql::predicate` +
  `DuplicateCollapseDql::apply` (scope = these ids, as `rowsByIdsForUser` does it), selecting
  `e.id` only via `scalarIds`. **Chunk the `IN` list at `ID_FILTER_CHUNK`** and merge the
  chunks' ids (dedup); empty input → `[]`. Reuse `EntryAliases`/`UnreadDql`/`DuplicateCollapseDql`
  exactly as `listForUser`/`rowsByIdsForUser` do — do not re-spell the unread or collapse SQL.

  NOTE the chunking/collapse edge: the #496 collapse is scoped per chunk, so two copies of one
  article split across a 5,000-id chunk boundary would both survive. This is the trade the
  issue accepts (chunk for packet/placeholder limits); note it in the PR.

## Testing (mirror `IndexedSavedSearchUnreadMatchesTest`, extend it — DbTestCase + fakes)

`IndexedSavedSearchBadgesTest` on the doubles (`FakeMultiSearchReader` for rounds; real
`EntryListRepository`/`FeedRepository` from the container), asserting a
`savedSearchId => list<entryId>` map:
- single round; every id under the right search.
- a search needing three rounds while another is exhausted after one — assert the exhausted
  one is NOT in later `receivedRounds` (dropped from active).
- cursor of round n+1 is built from the last id of round n (assert the `IndexSearch.cursor`
  the fake received on round 2 carries the round-1 last id and its DB effectiveDate).
- empty feeds → `[]`, no engine call. empty searches → `[]`, no engine call.
- an entry matching two searches appears under BOTH.
- read entries and `markedReadUntil`-covered entries are dropped; an unsubscribed feed's id
  is dropped (IDOR gate); a collapsed duplicate counted once; a chunk-boundary case for
  `unreadCollapsedSubscribedIds` (inject a small `ID_FILTER_CHUNK` if needed, or size the
  fixture — keep it small).
- `SavedSearchBadgesWithFallback`: not configured → database result; engine throws
  mid-enumeration → the WHOLE database result, a warning is logged, no partial engine data.
- **Parity**: for plain, whole-word and phrase searches, the badge map equals the unread rows
  of `GET /api/entries/saved-searches?unread=1` paged to the end (same doubles).

Repository test (both legs) for `unreadCollapsedSubscribedIds` and `effectiveDatesByIds`:
read entries dropped, `markedReadUntil` respected, unsubscribed feed's id dropped, collapsed
duplicate counted once, chunk boundary, empty input.

Functional test through the REAL wiring for `GET /api/saved-searches` (a direct-invocation
test alone is not enough — assert the endpoint answers and the shape is unchanged).

### Verify (RE-RUN `composer cs` + affected tests after EVERY edit incl. tests — CI fails on PSR-12 warnings)
- `composer cs && composer stan && composer md` clean on touched files; PhpStorm clean.
- Full `php bin/phpunit` (SQLite) green; affected tests on the MySQL leg via
  `docker compose exec php composer test -- --filter=...`.
- `composer infection:diff` 100% on changed lines. Watch for tautological tests.

### Manual verification (record in PR, dev stack with Meilisearch)
- Profiler shows NO `LIKE` corpus scan on `GET /api/saved-searches`; doctrine time in the
  tens of ms (was 600+ ms).
- Stop Meilisearch → the endpoint still answers (fallback) and the dev log shows the warning.

## Acceptance
- With an engine configured, `GET /api/saved-searches` runs no corpus-wide `LIKE` scan.
- Without an engine, or when it fails, results are exactly today's.
- Badge ids equal what the saved-search list shows unread, for every search mode, including
  searches with >1000 matches.
