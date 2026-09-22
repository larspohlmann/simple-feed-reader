# Persisted saved-search membership — design (#1116)

Date: 2026-09-22. Decided in a brainstorming session with Lars; every decision
below is settled unless it is listed under *§13 Open points*.

## 1. Problem

Two bugs, one cause: a saved search is recomputed on every read, by whichever
engine the host has, and three readers compute it three different ways.

1. **"Mark all read" on saved searches is very slow on Strato.** The list the
   user sees ("only unread") is one keyset page with a `LIMIT`
   (`SavedSearchEntryRepository::listForSavedSearches`). Mark-read must
   enumerate *every* match: `unreadMatchIdsForSavedSearches` runs an unbounded
   `SELECT DISTINCT` whose predicate is a `LIKE` over the unindexed `TEXT`
   columns `title` and `summary`, then `BulkEntryReadMarker` inserts one
   `entry_state` row per match. The scan grows with the whole entry table.
2. **On Docker the sidebar badge says 733 unread, the "only unread" list is
   empty.** Docker runs Meilisearch. Read state is not in the index, so
   `IndexedSavedSearchEntries::list` fetches the newest `limit` matches
   regardless of read state, caps them, and only then drops the read ones in
   PHP (`unreadOnly`). If the newest page of matches is read, the unread list
   is empty although hundreds of older unread matches exist. The badge path
   (`IndexedSavedSearchBadges`) pages the engine to exhaustion and filters in
   the database, so it is correct. Strato has no engine and takes the DB path,
   which puts the unread predicate inside the SQL `WHERE`, so it is correct
   there too. No test runs the engine path.

A fourth reader, the digest (`DigestEntryFinder::matchesSince`), runs its own
`LIKE` scan (`EntryListRepository::unreadMatchIdsSince`).

## 2. Decision in one paragraph

Saved-search membership becomes **a table**, `saved_search_entry`, filled by
**one incremental sweep** with a per-search high-water mark and read by every
consumer through **one join**. The table is a derived cache: always equal to
"what the current terms match", rebuildable at any time. The search engine is
untouched: it stays the ad-hoc search and becomes the sweep's *matcher* on
hosts that run it; the DB `LIKE` predicate is the matcher elsewhere. The
engine-versus-database split for saved searches (three `WithFallback` triples)
is deleted.

Facts the design rests on (verified in code on 2026-09-22):

- A saved search's terms are **immutable** (`SavedSearchController::update`
  only toggles `includeInDigest`; there is no `setTerm`). Edit is delete +
  create.
- The only ingest hook is `RefreshRunner::persistOutcome()`: flush, then
  `EntryIndexer::index()`. No events, no Messenger message per entry.
- Meilisearch writes are asynchronous (202 + task queue, no polling). The DB
  matcher has no lag.
- Duplicate collapse (`DuplicateCollapseDql`) is per user and per scope. It
  cannot be precomputed; it stays at read time.
- `EntryPruner::deleteByIds` deletes with bulk DQL: no ORM event fires. Only a
  DB `ON DELETE CASCADE` cleans dependants (`entry_state` already relies on it).

## 3. Data model

### 3.1 Table `saved_search_entry`

One row per (saved search, entry) the search's terms match.

| column | type | notes |
|---|---|---|
| `saved_search_id` | FK → `saved_search.id`, `ON DELETE CASCADE` | delete a search, its rows go |
| `entry_id` | FK → `entry.id`, `ON DELETE CASCADE` | covers the pruner's bulk `DELETE` |
| `matched_at` | `DATETIME`, naive UTC | for digest / alert "new since" later |

Primary key `(saved_search_id, entry_id)`. Secondary index `(entry_id)` for the
cascade and the future list-view marker join.

Entity `App\Entity\SavedSearchEntry`: composite key of two `ManyToOne`
associations, the shape of `EntryState`, so `doctrine:schema:validate` covers
it. `final`, constructor takes `(SavedSearch, Entry, \DateTimeImmutable
$matchedAt)`; no setters.

### 3.2 Column on `saved_search`

`matched_up_to_entry_id INT NOT NULL DEFAULT 0` — the high-water mark: every
entry with `id <= mark` has been checked against this search's terms. A new
search is born at 0. The entity gains `matchedUpToEntryId()` and
`advanceMatchedUpTo(int $entryId)`; the latter refuses to move backwards.

### 3.3 Scope

Membership is matched **globally** over all entries, independent of who
subscribes to the feed. Every reader gates by subscription at read time, as
every entry list already does (`Subscription` join in `rowQueryBuilder`). A
new subscription therefore surfaces its old matches at once; there is no
backfill-on-subscribe path to write. Row count is bounded by retention
(`EntryPruner`: 90 days, 2000 per feed).

## 4. The sweep — `App\Service\Search\Membership\SavedSearchMembershipSweep`

Shape of `ImageVerificationSweep` (#1109): budget-bounded, returns a report,
no lock of its own.

### 4.1 One run

1. **Ceiling.** `ceiling = MAX(entry.id) WHERE created_at <= now - 60 s`. Entries
   younger than the settle delay wait one tick; that absorbs the Meilisearch
   indexing lag. If no search has `mark < ceiling`, the run is one query and
   returns.
2. **Mark groups.** Searches are grouped by identical mark. All searches in a
   group walk together. After the first full pass every search sits at the
   same ceiling, so a steady-state tick is one matcher call per chunk for all
   searches at once.
3. **Chunks.** For a group, walk entry ids ascending in the range `(mark,
   ceiling]` in chunks of 500 (`SELECT id FROM entry WHERE id > :mark AND id <=
   :ceiling ORDER BY id LIMIT 500`). For each chunk: `matcher->matchingIds
   (searches, chunkIds)`; insert the rows with `matched_at = now`; advance every
   search in the group to the chunk's last id. **Insert and advance share one
   transaction.**
4. **Budget.** Stop when the budget is spent (`BUDGET_SECONDS`, see §4.4). The
   mark records where to continue. Groups are visited lowest mark first, so a
   new search's backfill is not starved by steady-state work.

### 4.2 Idempotence and concurrency

The per-chunk transaction is the only invariant: a run that dies mid-chunk
leaves neither half-inserted rows nor a skipped chunk. The insert is "insert
where not exists" (portable to SQLite and MySQL), so two overlapping runs are
safe: if the second reads a mark the first has already advanced it skips that
chunk; if it reads the same mark it re-derives the same rows and inserts none.
The sweep takes no lock: `MaintenanceTick` runs it after the refresh, and the
worker handles one message at a time.

### 4.3 Engine failure

`SearchEngineUnavailableException` from the matcher **stops the run and leaves
the mark untouched**; one warning is logged (the readers' existing wording).
The sweep never falls back to the DB matcher on an engine host: a table filled
by two matchers with different recall would be sticky and wrong. Retrying next
tick is the correct answer.

### 4.4 Scheduling

- `MaintenanceTick` (Strato cron, worker-less installs): after the refresh,
  under the existing aborted-EM guard, next to `ImageVerificationSweep`.
  `BUDGET_SECONDS = 10` inside the 20 s tick.
- Worker installs: new `SweepSavedSearchMemberships` message and handler,
  `RecurringMessage::every('1 minute', …)` in `WorkerSchedule`. Budget 60 s.
- `SavedSearchController::create()` runs the sweep **for that one search**
  synchronously with a budget of 8 s, then responds. Most searches complete
  before the response; the periodic sweep finishes the rest. This keeps
  today's "results appear at once" feel.

### 4.5 Report

`SavedSearchMembershipSweepReport { searchesSwept, entriesScanned,
matchesInserted, caughtUp: bool }` with `toArray()`, added to
`MaintenanceTickReport` and logged by the worker handler, as the other sweeps
are.

## 5. The matcher — `App\Service\Search\Membership\SavedSearchMatcher`

```php
interface SavedSearchMatcher
{
    /**
     * @param list<SavedSearchTerm> $searches
     * @param list<int>             $candidateEntryIds
     * @return array<int, list<int>>  saved-search id => matching ids among the candidates
     */
    public function matchingIds(array $searches, array $candidateEntryIds): array;
}
```

Two implementations; `services.yaml` picks one by `SearchEngineCapability
::isConfigured()`, the rule the deleted `WithFallback` classes used:

- **`DatabaseSavedSearchMatcher`** — one query per chunk: `SELECT e.id, CASE
  WHEN <predicate_0> THEN 1 ELSE 0 END AS match0, …` with `WHERE e.id IN
  (:chunk)`, the predicates from `SearchTermsPredicateBuilder`. This is the
  existing `matchIdsInOneScan` shape without the unread join. Up to
  `SEARCHES_PER_SCAN = 25` searches per statement (placeholder limit), so a
  group larger than that runs several statements per chunk.
- **`IndexedSavedSearchMatcher`** — one `/multi-search` per chunk, one query
  per search, `filter: id IN [chunk]`, `limit: 500`, `attributesToRetrieve:
  ['id']`. The query string uses the mode handling now private in
  `MeilisearchIndex::queryStringFor` (phrase / whole-word quoting); the plan
  extracts it to a shared value object rather than duplicating it.

The recall of the table equals the recall of the host's matcher — content and
typo matches on engine hosts, title/summary `LIKE` elsewhere — exactly the
difference the two hosts have today. The table persists it; it does not unify
it.

## 6. Readers

Every reader becomes a query on `saved_search_entry`. Every query carries
`sse.savedSearch.user = :user` in addition to the controller's own ownership
check, so a foreign search id yields no rows.

1. **Combined list** — `SavedSearchEntryRepository::listForSavedSearches`:
   `rowQueryBuilder` + `EXISTS (SELECT 1 FROM SavedSearchEntry sse WHERE
   sse.entry = e AND sse.savedSearch IN (:ids) AND sse.savedSearch.user =
   :user)` + `UnreadDql::predicate()` when `onlyUnread` + collapse + keyset
   cursor + `LIMIT`. `EXISTS`, not `JOIN`: an entry in several searches is one
   row, no `DISTINCT`. The per-card "matched search" badge
   (`matchedSavedSearchIds`) becomes one lookup of the page's entry ids in the
   table, first search in sidebar order.
2. **Sidebar badges** — `SavedSearchMatchIds::forAll` keeps its contract:
   unread entry ids per search (the client counts and drops one on read; no
   frontend change). One query: table `JOIN` entry `JOIN` subscription `LEFT
   JOIN` state, `UnreadDql::predicate()`, collapse, grouped by search. The
   "collapse per 5000-chunk may double-count" caveat of
   `SavedSearchBadgeCandidateRepository` disappears with the chunking.
3. **Mark-read** — `SavedSearchMarkReadService`: the badge query plus
   `e.effectiveDate <= :until`, then `BulkEntryReadMarker::markRead` unchanged.
   Per-entry `entry_state` rows stay: `isRead` means "hidden from every unread
   list", and a saved search spans feeds, so a per-search watermark would mark
   an entry read in the search and leave it unread in its feed list. The
   enumeration scan is what goes; the inserts stay (see §12).
4. **Digest** — `DigestEntryFinder::matchesSince`: unread rows in the table for
   one search, window on `e.effectiveDate > :since` (today's window; `matched_at`
   is reserved for a later "new since last digest"). `unreadMatchIdsSince` goes.

## 7. Deleted

- `Service/Search/`: `IndexedSavedSearchEntries`, `IndexedSavedSearchBadges`,
  `IndexedSavedSearchUnreadMatches`, `SavedSearchEntriesWithFallback`,
  `SavedSearchBadgesWithFallback`, `SavedSearchUnreadMatchesWithFallback`,
  `DatabaseSavedSearchEntries`, `DatabaseSavedSearchBadges`,
  `DatabaseSavedSearchUnreadMatches`.
- `Repository/SavedSearchBadgeCandidateRepository`.
- On `SavedSearchEntryRepository`: `anySearchMatches`, `matchIdsInOneScan`,
  `matchFlagExpression`, `firstMatchExpression`, `unreadMatchIdsBySavedSearch`,
  `unreadMatchIdsForSavedSearches` in their `LIKE` form (replaced by the table
  queries of §6).
- `EntryListRepository::unreadMatchIdsSince`.
- The interfaces `SavedSearchEntriesInterface`, `SavedSearchBadgeSource`,
  `SavedSearchUnreadMatchSource` collapse to one implementation each. Where the
  only implementation left is the repository, the interface goes and callers
  depend on the repository.

Kept: `SearchTermsPredicateBuilder`, `MeilisearchIndex`, `IndexedEntrySearch`
and `EntrySearchWithFallback` (ad-hoc search is unchanged), `SavedSearchTerms`
/ `SavedSearchTerm`, `UnreadDql`, `DuplicateCollapseDql`, `BulkEntryReadMarker`.

## 8. API

No request or response shape changes. Optional addition (§13): `backfillPending:
bool` on the saved-search JSON (`mark < ceiling`), so the sidebar can show a
"still indexing" state during a long backfill.

## 9. Migration and rollout

- One Doctrine migration: the table, its FKs and index, and the column with
  default 0. Verified by the existing migrate-from-empty CI leg on SQLite and
  MySQL plus `doctrine:schema:validate`.
- Every existing search starts at mark 0 and shares that mark, so the first
  deploy backfills **all of them in one walk**: ~100 chunks per 50 000 entries.
  Minutes on the worker; a few 20 s ticks on Strato. Until caught up, a search
  shows a partial list and badge. The PR states the expected window; the
  optional `backfillPending` flag makes it visible.
- No data migration of read state; `entry_state` is untouched.

## 10. Testing

- **Matchers**: `DatabaseSavedSearchMatcher` on SQLite for substring, whole-word
  and phrase modes and the `id IN` scoping (a matching entry outside the chunk
  is not returned); `IndexedSavedSearchMatcher` against a fake
  `SearchIndexReader` asserting one query per search, the `id IN` filter and
  the mode quoting.
- **Sweep**: the settle delay excludes entries younger than 60 s; searches at
  the same mark walk in one matcher call; the budget stops mid-walk and the
  next run resumes at the mark; an unavailable engine leaves the mark alone and
  inserts nothing; insert and advance are atomic (a matcher that throws after
  the insert leaves no row and no moved mark); lowest mark group first.
- **Repository**: with `onlyUnread`, the newest *unread* rows are returned when
  the newest matches are read (the Docker bug); badge ids equal the unread list
  rows for the same fixture; mark-read set honours `until`; a foreign search
  id yields no rows; an entry in two searches is one list row.
- **Digest**: `matchesSince` through the table.
- **Controller**: `create()` returns with the search's rows already present for
  a small corpus.
- **Migration**: the CI leg as is.
- **e2e** (`backend/tests/E2e`, owns its data): create a search, mark its newest
  matches read, assert badge count equals the unread list length.
- Infection over the touched files (`composer infection:diff`), the CI gate.

## 11. Code style notes for the plan

- New services `final readonly`, constructor promotion, interfaces injected.
- The matcher pair follows the `SearchEngineCapability`-keyed wiring in
  `services.yaml`; no service locator.
- No boolean flags: the sweep exposes `sweep(Budget $budget)` and
  `sweepOne(SavedSearch $search, Budget $budget)`, not `sweep(bool $one)`.
- Comments only where the code cannot say it: the per-chunk transaction
  invariant (§4.2) and the no-fallback rule (§4.3) qualify.

## 12. Follow-ups (separate issues, not in this change)

- List-view marker "this entry is in saved search X" — a `LEFT JOIN` on the new
  table in `EntryListRepository`.
- Digest "new since last digest" on `matched_at`.
- Multi-row `INSERT` for `BulkEntryReadMarker::createMissing` if Strato still
  feels mark-read after the scan is gone.

## 13. Open points

- `backfillPending` in the saved-search JSON (§8): include in this change, or
  leave out until a long backfill is observed. Default if not decided: leave
  out.
