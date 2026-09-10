# Design: rank the combined saved-searches list through Meilisearch (#973)

Issue: [#973](https://github.com/larspohlmann/simple-feed-reader/issues/973) —
"Combined saved-searches list should rank through Meilisearch when available".

Template: PR #972 / #971 closed the same gap for the **individual** search
(`IndexedEntrySearch`, `EntrySearchWithFallback`, `EntrySearchResult::continuationRow`,
`EntryPage::withMatchCount`). This design extends that pattern to the combined list,
which ORs N saved searches rather than answering one.

## 1. Goal & scope

When Meilisearch is configured, both operations on the combined saved-searches list
match and rank through the engine, the same way `/api/entries/search` now does:

1. **The list** (`GET /api/entries/saved-searches`) — every entry matching ANY of
   the caller's saved searches, newest-first, keyset-paginated.
2. **Mark-read** (`POST /api/entries/saved-searches/mark-read`) — flip every unread
   entry that any saved search matches, up to the watermark.

When no engine is configured, or the engine is unavailable, both fall back to the
current LIKE query, byte-for-byte unchanged. Read filtering, subscription access, and
hydration stay in the database on both paths.

This is a **backend-only** change. The wire response keeps its shape
(`{entries, nextCursor, savedSearchIds}`), so the frontend does not change.

### Why mark-read also moves to the engine

The engine matches `title, summary, content, feedTitle` with typo tolerance; the LIKE
predicate matches only `title` + `summary`. So an engine-ranked list shows matches the
LIKE mark-read set would never contain. If mark-read stayed on LIKE, "mark all read"
would leave the engine-only matches unread — the list would not clear. To keep
mark-read flipping exactly what the list shows, the mark-read set is enumerated through
the engine too (decided in brainstorming).

This makes saved-search mark-read stronger than the **single-search** mark-read, which
stays on LIKE (`SearchMarkReadService` → `unreadMatchingEntryIdsForUser`). Aligning the
single-search path is **out of scope** here; the divergence is deliberate and noted.

### Out of scope (deferred, documented)

- **Single-search mark-read** stays on the LIKE predicate, unchanged.
- **Relevance ranking.** The list is ordered newest-first (`effectiveDate desc, id
  desc`), never by relevance score, on both the old and new paths. "Rank through the
  engine" here means *match quality* (typo tolerance, prefix, full-content, per-mode
  correctness), not a score-ordered list. So plain multi-search, not federation.
- **Badge attribution on the DB fallback** keeps `matchedSavedSearchIds` as-is.

## 2. The engine gateway: add multi-search

`MeilisearchIndex` calls `POST /indexes/entries/search`, one search per call. The
combined list needs N saved searches — each with its own whole-word/phrase mode — in
one round trip. One Meilisearch `q` with `matchingStrategy: all` cannot express an OR of
several term-sets in different modes, so we send N queries and merge their id-lists
ourselves.

Add one read method to the `SearchIndexReader` interface:

```php
/**
 * @param list<IndexSearch> $searches
 * @return list<IndexMatches> result i pairs with searches[i], in request order
 * @throws SearchEngineUnavailableException
 */
public function findMany(array $searches): array;
```

`MeilisearchIndex::findMany` sends **one** `POST /multi-search` request whose `queries`
array holds one entry per `IndexSearch`. Each query carries exactly the fields
`searchPayload()` builds for a single search today — its own `q`, its own `filter`
(`feedId IN [...]` plus the keyset predicate), `sort: [effectiveDate:desc, id:desc]`,
`matchingStrategy: all`, `limit`, and the highlight fields — with `indexUid: entries`
added per query. The response returns one hit list per query, in request order, so
result `i` maps to saved search `i`.

This is **plain** multi-search (`queries: [...]`), not federation (`federation: {...}`):
plain multi-search returns N independent hit lists, which preserves both newest-first
order per query and per-search attribution. Federation would merge into one
relevance-ranked list and lose both.

`find()` and `findMany([$one])` overlap, but `find()` stays as its own method: it is on
the hot single-search path, and the existing probe and tests pin its single-index
request shape. `findMany` is built and probed separately.

### 2.1 The `/multi-search` probe (required first task)

`MeilisearchIndex`'s contract is *measured against the running engine*, never taken from
upstream docs (`docs/meilisearch-wire-format.md`, v1.13). `POST /multi-search` is **not
yet probed** in this repo. Before the gateway code depends on it, probe the running
engine and add a section to `docs/meilisearch-wire-format.md` that records:

- the request shape (`{"queries": [{"indexUid": "entries", "q": ..., "filter": ...,
  "sort": [...], "matchingStrategy": "all", "limit": ..., "attributesToRetrieve":
  ["id"], "attributesToHighlight": ["title","summary"], "highlightPreTag": ...,
  "highlightPostTag": ...}, ...]}`),
- the response shape (`{"results": [{"indexUid": "entries", "hits": [...], ...}, ...]}`),
  and that results preserve request order,
- confirmation that per-query `sort`, `filter`, and the highlight fields behave inside
  `/multi-search` exactly as on the single-index `/search`.

If a probed fact contradicts this design, revise the design before coding. The probe
runs against the Docker stack's Meilisearch (`docker compose up`).

## 3. The list path: `IndexedSavedSearchEntries`

A new `final readonly` class, parallel to `IndexedEntrySearch`, behind a new
`SavedSearchEntriesInterface`.

```php
interface SavedSearchEntriesInterface
{
    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult;
}
```

`IndexedSavedSearchEntries::list(SavedSearchEntryQuery $query)`:

1. `$feedIds = $this->feeds->idsSubscribedByUser($query->userId)`. Empty → return an
   empty result without touching the engine (mirrors `IndexedEntrySearch`).
2. Build one `IndexSearch` per `SavedSearchTerm` in `$query->savedSearches` — the
   search's own `terms`, the shared `$feedIds`, the shared `$query->cursor`, the shared
   `$query->limit`.
3. `$perSearch = $this->index->findMany($indexSearches)` → N `IndexMatches`.
4. **Union with attribution.** Walk `$perSearch` in order; for each entry id, record the
   **first** (lowest-index) saved search whose result contained it. Produces the id
   union and the `entryId → savedSearchId` map in one pass.
5. `$candidates = $this->entries->rowsByIdsForUser($unionIds, $query->userId)` — hydrates
   newest-first and applies the subscription access gate. (This method already re-sorts
   by `effectiveDate desc, id desc` and ignores input id order, so no manual sort.)
6. Truncate `$candidates` to `$query->limit`. The last kept candidate is
   `continuationRow`.
7. For `$query->onlyUnread`, drop the read rows (`!$row->isHidden`) *after* truncation,
   exactly as `IndexedEntrySearch::unreadOnly` does; `continuationRow` still names the
   last candidate so a fully-read page advances.
8. `matchCount` = `count($unionIds)` — the engine's own frontier, before hydration drops
   ghosts and before the unread filter drops read rows (mirrors `IndexedEntrySearch`).

### 3.1 Why the union merge is correct

Each sub-query returns its own newest `limit` ids after the cursor. Claim: the newest
`limit` of the union is a subset of the union of per-query newest-`limit` results.

Take entry `e` in the global newest `limit` of the union. `e` matches some search `A`.
Within `A`'s stream `e`'s rank ≤ its rank in the union ≤ `limit` (because `A ⊆ union`).
So `A`'s query returned `e`. Therefore the union of per-query results holds every entry
the page must show; truncating a newest-first hydrate of that union yields the exact
page. The same argument re-applies at the next cursor, so pagination stays correct
across pages.

### 3.2 Why engine attribution is correct

For a **shown** entry `e` (in the global newest `limit`), *every* search that matches `e`
returned it (same rank argument as 3.1). So the lowest-index search that returned `e`
equals the lowest-index search that matches `e` — the same "first match in sidebar
order" the DB `firstMatchExpression` computes, but consistent with the engine's matching
instead of the LIKE predicate's. Dropped and read rows need no badge, so partial cover
below the truncation line does not matter.

### 3.3 The result object

```php
final readonly class SavedSearchEntriesResult
{
    /**
     * @param list<EntryListRow> $rows
     * @param array<int, int>    $savedSearchIds  entryId => first matching saved search id
     */
    public function __construct(
        public array $rows,
        public array $savedSearchIds,
        public int $matchCount,
        public ?EntryListRow $continuationRow = null,
    ) {}
}
```

`SavedSearchPage::of` moves from `EntryPage::of` to
`EntryPage::withMatchCount($result->rows, $limit, $result->matchCount,
EntryListSort::PublishedDate, $result->continuationRow)`, then appends
`savedSearchIds` (still cast to `(object)` so an empty map encodes as `{}`). The
controller reads `savedSearchIds` off the result instead of calling
`matchedSavedSearchIds` itself.

## 4. Engine-consistent mark-read

Mark-read needs the **set** of unread entry ids that any saved search matches with
`effectiveDate <= until`. Rather than reimplement engine paging, mark-read **drives the
indexed list's own pagination** — the list already unions the N searches, hydrates
newest-first, applies the access gate, drops read rows, and cursors correctly. Reusing
it keeps one engine matching path and needs no change to `IndexMatches`.

A new match source behind a fallback interface:

```php
interface SavedSearchUnreadMatchSource
{
    /** @param list<SavedSearchTerm> $savedSearches @return list<int> */
    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array;
}
```

`IndexedSavedSearchUnreadMatches` depends on `IndexedSavedSearchEntries` (the raw indexed
list, not the fallback — a mid-enumeration engine failure must surface so mark-read's own
fallback catches it) and enumerates:

1. Seed `$cursor = EntryCursor::inclusiveUpperBound($until)` (see 4.1) so the first page
   begins at `until`, skipping newer entries.
2. Loop: `$result = $indexedList->list(new SavedSearchEntryQuery($userId, $savedSearches,
   onlyUnread: true, cursor: $cursor, limit: self::ENUMERATION_PAGE))`. Collect
   `$result->rows`' entry ids — each is unread, matches an engine query, and has
   `effectiveDate <= until` by construction.
3. Stop when `$result->matchCount < self::ENUMERATION_PAGE` or `$result->continuationRow`
   is null (no further page). Otherwise advance
   `$cursor = new EntryCursor($result->continuationRow->entry->getEffectiveDate(),
   (int) $result->continuationRow->entry->getId())` and repeat.

Keyset pagination is strictly decreasing, so no id repeats across pages; each page also
dedups the union, so the collected ids are already distinct.

`SavedSearchMarkReadService::mark` then passes those ids to `BulkEntryReadMarker`,
exactly as today — only the id **source** changes. On an engine failure the exception
propagates out of the loop; the partial collection is discarded and the fallback recomputes
the full set from the database.

`ENUMERATION_PAGE` is `EntryQuery::MAX_LIMIT` (100) — the largest page `SavedSearchEntryQuery`
will accept, so it both cuts round trips and keeps the stop test honest: a larger value
would be clamped by `clampLimit` at construction, and the `matchCount < ENUMERATION_PAGE`
comparison would then test against a page size the query never used. It also stays under
Meilisearch's per-query `maxTotalHits` (keyset paging removes the cumulative cap, but each
page's `limit` must stay under it).

The `DatabaseSavedSearchUnreadMatches` fallback wraps the existing
`SavedSearchEntryRepository::unreadMatchIdsForSavedSearches`, unchanged.

### 4.1 The inclusive upper bound

The list cursor is strict — `effectiveDate < c.date OR (effectiveDate = c.date AND id <
c.id)` — so a plain `(until, id)` cursor would exclude the rows at `until`. To begin the
enumeration **at and below** `until`, seed the cursor at `(until, PHP_INT_MAX)` through a
named factory `EntryCursor::inclusiveUpperBound(\DateTimeImmutable $until)`. Entry ids are
DB auto-increment ints far below `PHP_INT_MAX`, so `id < PHP_INT_MAX` holds for every real
row; the first page then starts at the newest entry no newer than `until`. This cursor is
internal to mark-read and is never encoded for a client, so the sentinel id never leaves
the process.

**Alternative considered:** add an explicit `?int $maxEffectiveDate` to `IndexSearch` and
page each search directly. Rejected: it duplicates the list's paging logic and would force
`IndexMatches` to carry each hit's sort key so the next keyset cursor could be built
without hydration. Driving the existing list keeps `IndexSearch`/`IndexMatches` untouched
and states intent at the call site. Open to reversal at spec review.

## 5. Structure and fallback

Two small interface families, each with `Indexed…`, `Database…`, and `…WithFallback`,
mirroring `EntrySearchWithFallback`:

| Interface | Indexed | Database | Fallback |
|---|---|---|---|
| `SavedSearchEntriesInterface` | `IndexedSavedSearchEntries` | `DatabaseSavedSearchEntries` (wraps `listForSavedSearches` + `matchedSavedSearchIds`) | `SavedSearchEntriesWithFallback` |
| `SavedSearchUnreadMatchSource` | `IndexedSavedSearchUnreadMatches` | `DatabaseSavedSearchUnreadMatches` (wraps `unreadMatchIdsForSavedSearches`) | `SavedSearchUnreadMatchesWithFallback` |

Each `…WithFallback` holds the same rule as `EntrySearchWithFallback`:

- engine not configured (`SearchEngineCapability::isConfigured()` false) → database,
  silent (the permanent Strato case);
- `SearchEngineUnavailableException` → database, one `warning` with the exception;
- any other exception propagates.

The container aliases each interface to its `…WithFallback` in `services.yaml`, beside
the existing `EntrySearchInterface` alias.

`DatabaseSavedSearchEntries` assembles a `SavedSearchEntriesResult` from the repository
so both paths return the same type: it runs `listForSavedSearches`, then
`matchedSavedSearchIds` over the returned ids, and reports `matchCount =
count($rows)` with `continuationRow = null` (the LIKE list already returns exactly the
page it shows — no post-hydration drop, so the last row is the resume point, which
`withMatchCount` derives when `continuationRow` is null). Its behaviour is identical to
today's controller output.

`SavedSearchEntryQuery` remains the shared parameter object for both the list and the
`Database…` path.

## 6. Controller & service wiring

- `SavedSearchEntriesController::list` calls `SavedSearchEntriesInterface::list($query)`
  and returns `SavedSearchPage::of($result)`. It no longer builds the id list or calls
  `matchedSavedSearchIds` itself. (`SavedSearchPage::of` takes the result; the class
  still owns the wire shape and the `{}` cast.)
- `SavedSearchMarkReadService` depends on `SavedSearchUnreadMatchSource` instead of
  `SavedSearchEntryRepository` for the id set.

`ThinControllerRule` stays satisfied — the action reads the request, delegates, returns.

## 7. Testing

Mirror the #972 test additions, one level up (N searches, attribution):

**Service (engine path), `tests/Service/Search/`:**
- `IndexedSavedSearchEntriesTest` — union/dedup across searches; an entry matching two
  searches attributed to the first in order; a content/typo-only engine match that the
  LIKE predicate would miss still appears and is badged; the newest-`limit` truncation;
  `continuationRow` past the last candidate; the unread filter dropping read rows while
  the page still advances; a fully-read page still carries `continuationRow`; a ghost id
  dropped from rows but not from `matchCount`; a user with no subscriptions returns empty
  without asking the engine. Uses a `FakeMultiSearchReader` (a `findMany` fake shaped
  like `FakeSearchIndexReader`).
- `IndexedSavedSearchUnreadMatchesTest` — enumeration drives the list pagination across a
  multi-page match set and unions; the `until` upper bound excludes newer rows; read rows
  are dropped; paging stops at exhaustion; no subscriptions → empty.
- `SavedSearchEntriesWithFallbackTest` / `SavedSearchUnreadMatchesWithFallbackTest` —
  unconfigured engine never calls the reader and the database answers (silent);
  `SearchEngineUnavailableException` falls back and logs exactly one warning; any other
  exception propagates.

**Gateway, `tests/Service/Search/Index/`:**
- `MeilisearchIndexTest` gains `findMany` cases over `MockHttpClient`: the request is one
  `POST /multi-search` with N `queries`; results map back positionally; a non-2xx or
  transport error raises `SearchEngineUnavailableException`.

**HTTP, `tests/Http/`:**
- `SavedSearchPageTest` — the `continuationRow` decides the cursor; `savedSearchIds`
  encodes `{}` when empty and `{entryId: searchId}` otherwise; the shape matches the
  pre-change output for a plain page.

**Controller, `tests/Controller/Api/`:**
- `SavedSearchEntriesControllerTest` keeps its current cases green through the fallback
  (no engine in the test env by default) and gains an engine-backed case if the harness
  seeds a fake reader, asserting the list and mark-read agree on an engine-only match.

**Database path unchanged:** `SavedSearchEntryListTest`, `SavedSearchUnreadMatchIdsTest`
stay green and now also stand as the fallback's coverage.

Gates: `composer check` (cs + PHPStan max + tramp), `composer md`, `php bin/phpunit` on
SQLite and MySQL, `composer infection:diff` over the changed lines. PHPMD-clean every
touched file.

## 8. Cost

The list makes one `/multi-search` round trip per page (N queries batched), then one
hydrate. Mark-read makes one `/multi-search` round trip per page-depth round, then one
hydrate. The baseline is one unindexable LIKE scan across all the caller's feeds. Record
the measured comparison on the PR, as the issue asks. Mark-read is a deliberate user
action, not a hot path, so its paging cost is acceptable.

## 9. File inventory

**New (`backend/src/`):**
- `Service/Search/SavedSearchEntriesInterface.php`, `IndexedSavedSearchEntries.php`,
  `DatabaseSavedSearchEntries.php`, `SavedSearchEntriesWithFallback.php`
- `Service/Search/SavedSearchEntriesResult.php`
- `Service/Search/SavedSearchUnreadMatchSource.php`,
  `IndexedSavedSearchUnreadMatches.php`, `DatabaseSavedSearchUnreadMatches.php`,
  `SavedSearchUnreadMatchesWithFallback.php`

**Changed (`backend/src/`):**
- `Service/Search/Index/SearchIndexReader.php` — add `findMany`
- `Service/Search/Index/MeilisearchIndex.php` — implement `findMany` via `/multi-search`;
  extract the shared per-query payload builder
- `Http/EntryCursor.php` — add `inclusiveUpperBound()` (pending the 4.1 decision)
- `Http/SavedSearchPage.php` — take `SavedSearchEntriesResult`, use `withMatchCount`
- `Controller/Api/SavedSearchEntriesController.php` — delegate to the interfaces
- `Service/Reader/SavedSearchMarkReadService.php` — depend on `SavedSearchUnreadMatchSource`
- `config/services.yaml` — alias the two new interfaces to their `…WithFallback`

**Docs:**
- `docs/meilisearch-wire-format.md` — the `/multi-search` probe

**Unchanged, now fallback coverage:** `SavedSearchEntryRepository` (all methods keep
their current behaviour and callers on the database path).

## 10. Open decisions for review

1. **§4.1** inclusive-upper-bound cursor factory vs. an explicit `maxEffectiveDate` field
   on `IndexSearch`. Design picks the factory; reversible.
2. **§2.1** the `/multi-search` probe runs against the Docker Meilisearch during
   implementation; if a probed fact contradicts §2, the design is revised first.
