# Saved-search engine ranking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rank and match the combined saved-searches list (and its mark-read) through Meilisearch when configured, falling back to the current LIKE query when not.

**Architecture:** Add a batched `findMany` to the search gateway (one `POST /multi-search`). A new `IndexedSavedSearchEntries` runs one engine query per saved search, unions the ids, hydrates once newest-first, truncates, and derives badges from which query matched. A `…WithFallback` decorator makes the engine optional, mirroring `EntrySearchWithFallback`. Mark-read reuses the indexed list: it drives the list's pagination from an inclusive-upper-bound cursor and collects the unread ids. Nothing in the DB fallback path changes.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, Meilisearch (optional), PHPUnit 12 (SQLite natively), Meilisearch talked to as a plain JSON API over `HttpClientInterface`.

**Spec:** [docs/superpowers/specs/2026-09-10-973-saved-search-engine-ranking-design.md](../specs/2026-09-10-973-saved-search-engine-ranking-design.md)

## Global Constraints

- **PSR-12**, `declare(strict_types=1)` in every file. PHPStan level max over `src` and `tests`; no new baselines, no `@phpstan-ignore` without a why-comment.
- **Clean Code is mandatory** (CLAUDE.md): intent-revealing names, one-thing functions, guard clauses, **no boolean flag parameters**, `final readonly class` with constructor promotion, depend on interfaces, typed namespaced exceptions, comment only the *why*. Every `src` file you touch must be **PHPMD-clean** before commit.
- **Thin controllers** (`ThinControllerRule`): an action reads the request, delegates, returns. No private methods that do real work.
- **PHPUnit 12:** data providers must use the `#[DataProvider]` attribute, never a `@dataProvider` docblock.
- **Datetimes are naive UTC.** Not relevant to new code here, but do not introduce offsets.
- **Meilisearch wire format is measured, never assumed** (`docs/meilisearch-wire-format.md`, v1.13). Task 1 probes `/multi-search` before any code depends on it.
- **Test env has no engine** (`MEILISEARCH_URL` empty), so `SearchEngineCapability::isConfigured()` is false and every functional test runs the DB fallback unless it constructs the engine path directly with a fake reader.
- **Commit format:** `type(#973): summary`. Feature branch `feature/973-saved-search-engine-ranking` (already checked out).
- **Gates before PR:** `composer check` (cs + PHPStan + tramp), `composer md`, `php bin/phpunit` (SQLite) and `docker compose exec php vendor/bin/phpunit` (MySQL), `composer infection:diff`.

---

### Task 1: Probe and document `POST /multi-search`

**Files:**
- Modify: `docs/meilisearch-wire-format.md` (append a new section)

**Interfaces:**
- Consumes: nothing.
- Produces: the confirmed request/response shape Task 2 depends on. If a measured fact contradicts the assumed shape below, STOP and reconcile the spec (§2) before Task 2.

This task is a measurement, not TDD. The gateway's contract is measured against the running engine, never taken from upstream docs.

- [ ] **Step 1: Bring up the stack and find the engine's URL and key**

Run: `docker compose up -d` from the repo root. Read `MEILISEARCH_URL` and `MEILISEARCH_KEY` from `backend/.env` / `backend/.env.local` / `compose.yaml` (whichever defines them). The engine is a container on the compose network; from the host it is reachable at the published port.

- [ ] **Step 2: Seed at least one document if the index is empty**

If `entries` is empty, run `docker compose exec php bin/console app:search:reindex` so `/multi-search` returns real hits.

- [ ] **Step 3: Probe `/multi-search` with two queries in different modes**

Run (substitute URL/key/port), one query bare and one whole-word-quoted, each scoped and sorted like the single search:

```bash
curl -s -X POST "$MEILISEARCH_URL/multi-search" \
  -H "Authorization: Bearer $MEILISEARCH_KEY" -H 'Content-Type: application/json' \
  -d '{"queries":[
    {"indexUid":"entries","q":"the","filter":"feedId IN [1,2,3]","sort":["effectiveDate:desc","id:desc"],"matchingStrategy":"all","limit":5,"attributesToRetrieve":["id"],"attributesToHighlight":["title","summary"],"highlightPreTag":"[[sfr:hl]]","highlightPostTag":"[[/sfr:hl]]"},
    {"indexUid":"entries","q":"\"news\"","filter":"feedId IN [1,2,3]","sort":["effectiveDate:desc","id:desc"],"matchingStrategy":"all","limit":5,"attributesToRetrieve":["id"],"attributesToHighlight":["title","summary"],"highlightPreTag":"[[sfr:hl]]","highlightPostTag":"[[/sfr:hl]]"}
  ]}' | jq .
```

- [ ] **Step 4: Record the measured facts in `docs/meilisearch-wire-format.md`**

Add a section that states, from the actual response:
- the response envelope is `{"results": [ {"indexUid":"entries","hits":[...], ...}, ... ]}`;
- `results` preserves request order (result `i` is `queries[i]`);
- per-query `sort`, `filter`, `matchingStrategy`, and the highlight fields behave inside `/multi-search` exactly as on the single-index `/search` (note any deviation);
- one 4xx/5xx envelope shape if the request is malformed (so Task 2's error handling matches reality).

Keep the section's voice consistent with the file: measurements against v1.13, "re-measure before trusting on another version."

- [ ] **Step 5: Commit**

```bash
git add docs/meilisearch-wire-format.md
git commit -m "docs(#973): probe Meilisearch /multi-search wire format"
```

---

### Task 2: Add `findMany` to the gateway

**Files:**
- Modify: `backend/src/Service/Search/Index/SearchIndexReader.php`
- Modify: `backend/src/Service/Search/Index/MeilisearchIndex.php`
- Modify: `backend/tests/Service/Search/FakeSearchIndexReader.php` (satisfy the widened interface)
- Create: `backend/tests/Service/Search/FakeMultiSearchReader.php`
- Test: `backend/tests/Service/Search/Index/MeilisearchIndexTest.php`

**Interfaces:**
- Consumes: `IndexSearch`, `IndexMatches` (unchanged), `SearchEngineUnavailableException`.
- Produces: `SearchIndexReader::findMany(array $searches): array` — `@param list<IndexSearch>`, `@return list<IndexMatches>`, result `i` pairs with `searches[i]`, `@throws SearchEngineUnavailableException`. `FakeMultiSearchReader` for later tasks.

- [ ] **Step 1: Check for other `SearchIndexReader` implementors**

Run: `grep -rl "implements SearchIndexReader" backend/src backend/tests`
Expected: only `MeilisearchIndex` and `FakeSearchIndexReader`. If anything else appears, it also needs a `findMany` in this task.

- [ ] **Step 2: Write the failing gateway tests**

Add to `backend/tests/Service/Search/Index/MeilisearchIndexTest.php`:

```php
public function testFindManySendsOneMultiSearchWithOneQueryPerSearch(): void
{
    $client = $this->clientCapturing(new MockResponse('{"results":[{"hits":[]},{"hits":[]}]}'));
    $this->index($client)->findMany([
        new IndexSearch(SearchTerms::fromInput('widgets'), [1, 2], null, 20),
        new IndexSearch(SearchTerms::fromInput('gizmos '), [1, 2], null, 20),
    ]);

    self::assertSame('POST', $this->capturedRequest['method']);
    self::assertSame(self::BASE_URL . '/multi-search', $this->capturedRequest['url']);
    $body = $this->capturedJsonObject();
    self::assertIsArray($body['queries']);
    self::assertCount(2, $body['queries']);
    self::assertSame('entries', $body['queries'][0]['indexUid']);
    self::assertSame('widgets', $body['queries'][0]['q']);
    self::assertSame('"gizmos"', $body['queries'][1]['q']);
}

public function testFindManyMapsResultSetsBackToTheQueryOrder(): void
{
    $client = $this->clientCapturing(new MockResponse(
        '{"results":[{"hits":[{"id":7}]},{"hits":[{"id":9},{"id":11}]}]}',
    ));
    $matches = $this->index($client)->findMany([
        new IndexSearch(SearchTerms::fromInput('widgets'), [1], null, 20),
        new IndexSearch(SearchTerms::fromInput('gizmos'), [1], null, 20),
    ]);

    self::assertSame([7], $matches[0]->entryIds);
    self::assertSame([9, 11], $matches[1]->entryIds);
}

public function testFindManyRaisesWhenTheResultCountDoesNotMatchTheQueryCount(): void
{
    $client = $this->clientCapturing(new MockResponse('{"results":[{"hits":[]}]}'));

    $this->expectException(SearchEngineUnavailableException::class);
    $this->index($client)->findMany([
        new IndexSearch(SearchTerms::fromInput('widgets'), [1], null, 20),
        new IndexSearch(SearchTerms::fromInput('gizmos'), [1], null, 20),
    ]);
}

public function testFindManyRaisesOnATransportFailure(): void
{
    $client = new MockHttpClient(static function (): MockResponse {
        throw new TransportException('boom');
    });

    $this->expectException(SearchEngineUnavailableException::class);
    $this->index($client)->findMany([new IndexSearch(SearchTerms::fromInput('widgets'), [1], null, 20)]);
}

public function testFindManyReturnsNothingForNoSearchesWithoutCallingTheEngine(): void
{
    self::assertSame([], $this->index($this->clientThatMustNotBeCalled())->findMany([]));
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Search/Index/MeilisearchIndexTest.php`
Expected: FAIL — `findMany` is not defined on `SearchIndexReader`/`MeilisearchIndex`.

- [ ] **Step 4: Widen the interface**

In `backend/src/Service/Search/Index/SearchIndexReader.php`, add:

```php
/**
 * @param list<IndexSearch> $searches
 *
 * @return list<IndexMatches> result i pairs with searches[i], in request order
 *
 * @throws SearchEngineUnavailableException
 */
public function findMany(array $searches): array;
```

(`IndexMatches` is already in this namespace; no new import needed.)

- [ ] **Step 5: Implement `findMany` in `MeilisearchIndex`, refactoring the hit extraction**

Extract the hit-list handling so `find()` and `findMany()` share it, then add `findMany`:

```php
public function findMany(array $searches): array
{
    if ($searches === []) {
        return [];
    }

    $body = $this->requestBody('POST', '/multi-search', [
        'json' => ['queries' => array_map($this->multiSearchQuery(...), $searches)],
    ]);

    return $this->manyMatchesFromResponse($body, \count($searches));
}

/** @return array<string, mixed> */
private function multiSearchQuery(IndexSearch $search): array
{
    return ['indexUid' => self::INDEX, ...$this->searchPayload($search)];
}

/** @return list<IndexMatches> */
private function manyMatchesFromResponse(string $body, int $expected): array
{
    $decoded = json_decode($body, true);
    if (!\is_array($decoded) || !isset($decoded['results']) || !\is_array($decoded['results'])) {
        throw new SearchEngineUnavailableException('The search engine answered with an unreadable response.');
    }

    /** @var list<array<mixed>> $results */
    $results = array_values(array_filter($decoded['results'], static fn (mixed $r): bool => \is_array($r)));
    if (\count($results) !== $expected) {
        throw new SearchEngineUnavailableException(
            'The search engine returned a different number of result sets than queries.',
        );
    }

    return array_map(function (array $result): IndexMatches {
        $hits = isset($result['hits']) && \is_array($result['hits']) ? $result['hits'] : [];

        return $this->matchesFromHits($hits);
    }, $results);
}
```

Refactor the existing `matchesFromResponse` to delegate to a new `matchesFromHits`:

```php
private function matchesFromResponse(string $body): IndexMatches
{
    $decoded = json_decode($body, true);
    if (!\is_array($decoded) || !isset($decoded['hits']) || !\is_array($decoded['hits'])) {
        throw new SearchEngineUnavailableException('The search engine answered with an unreadable response.');
    }

    return $this->matchesFromHits($decoded['hits']);
}

/**
 * @param array<mixed> $rawHits
 */
private function matchesFromHits(array $rawHits): IndexMatches
{
    /** @var list<array<mixed>> $hits */
    $hits = array_values(array_filter($rawHits, static fn (mixed $hit): bool => \is_array($hit)));

    return new IndexMatches($this->entryIdsOf($hits), $this->matchedWordsOf($hits));
}
```

- [ ] **Step 6: Satisfy the widened interface in `FakeSearchIndexReader`**

Add to `backend/tests/Service/Search/FakeSearchIndexReader.php`:

```php
/** @var list<IndexSearch>|null */
public ?array $receivedMany = null;

/**
 * @param list<IndexSearch> $searches
 *
 * @return list<IndexMatches>
 */
public function findMany(array $searches): array
{
    $this->receivedMany = $searches;

    if ($this->failure !== null) {
        throw $this->failure;
    }

    return array_map(fn (): IndexMatches => new IndexMatches($this->entryIds, $this->matchedWords), $searches);
}
```

- [ ] **Step 7: Create `FakeMultiSearchReader`**

Create `backend/tests/Service/Search/FakeMultiSearchReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * Drives the combined saved-search engine path without a running Meilisearch.
 * Each findMany() call answers the next queued round (a list of IndexMatches,
 * one per search in request order); it records every round it received so a
 * test can assert on the searches and the paging it drove. find() is unused
 * here — the combined path only ever batches.
 */
final class FakeMultiSearchReader implements SearchIndexReader
{
    /** @var list<list<IndexSearch>> */
    public array $receivedRounds = [];

    /** @param list<list<IndexMatches>> $rounds */
    public function __construct(
        private array $rounds = [],
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function find(IndexSearch $search): IndexMatches
    {
        throw new \LogicException('FakeMultiSearchReader answers findMany only.');
    }

    public function findMany(array $searches): array
    {
        $this->receivedRounds[] = $searches;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_shift($this->rounds)
            ?? array_map(static fn (): IndexMatches => new IndexMatches([], []), $searches);
    }
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Search/Index/MeilisearchIndexTest.php`
Expected: PASS. Then `php bin/phpunit tests/Service/Search` — the existing single-search tests still pass.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Search/Index backend/tests/Service/Search
git commit -m "feat(#973): batch engine reads through /multi-search"
```

---

### Task 3: `IndexedSavedSearchEntries` — the engine list

**Files:**
- Create: `backend/src/Service/Search/SavedSearchEntriesInterface.php`
- Create: `backend/src/Service/Search/SavedSearchEntriesResult.php`
- Create: `backend/src/Service/Search/IndexedSavedSearchEntries.php`
- Test: `backend/tests/Service/Search/IndexedSavedSearchEntriesTest.php`

**Interfaces:**
- Consumes: `SearchIndexReader::findMany`, `FeedRepository::idsSubscribedByUser(int): list<int>`, `EntryListRepository::rowsByIdsForUser(list<int>, int): list<EntryListRow>` (returns rows re-sorted newest-first, access-gated), `SavedSearchEntryQuery` (`userId`, `savedSearches: list<SavedSearchTerm>`, `onlyUnread`, `cursor`, `limit`), `SavedSearchTerm` (`id`, `terms`), `IndexSearch`, `IndexMatches`, `EntryListRow`.
- Produces:
  - `SavedSearchEntriesInterface::list(SavedSearchEntryQuery): SavedSearchEntriesResult`
  - `SavedSearchEntriesResult(list<EntryListRow> $rows, array<int,int> $savedSearchIds, int $matchCount, ?EntryListRow $continuationRow = null)`

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Search/IndexedSavedSearchEntriesTest.php` (mirror `IndexedEntrySearchTest`'s DB setup: a `User`, a `Feed`, a `Subscription`, an `entry()` helper, a `markRead()` helper). Build the subject with the real repositories and a `FakeMultiSearchReader`:

```php
private function list(FakeMultiSearchReader $reader, SavedSearchEntryQuery $query): SavedSearchEntriesResult
{
    $entryListRepository = self::getContainer()->get(EntryListRepository::class);
    self::assertInstanceOf(EntryListRepository::class, $entryListRepository);

    /** @var FeedRepository $feedRepository */
    $feedRepository = self::getContainer()->get(FeedRepository::class);

    return (new IndexedSavedSearchEntries($reader, $feedRepository, $entryListRepository))->list($query);
}

/** @param list<SavedSearchTerm> $savedSearches */
private function query(array $savedSearches, bool $onlyUnread = false, ?EntryCursor $cursor = null, int $limit = 50): SavedSearchEntryQuery
{
    return new SavedSearchEntryQuery(
        userId: $this->user->getId() ?? 0,
        savedSearches: $savedSearches,
        onlyUnread: $onlyUnread,
        cursor: $cursor,
        limit: $limit,
    );
}
```

Tests:

```php
public function testUnionsMatchesAcrossSearchesAndAttributesToTheFirst(): void
{
    $newest = $this->entry('newest', '2026-07-13T00:00:00Z');
    $middle = $this->entry('middle', '2026-07-12T00:00:00Z');
    $oldest = $this->entry('oldest', '2026-07-11T00:00:00Z');

    // search 10 matched newest+middle, search 20 matched middle+oldest.
    $reader = new FakeMultiSearchReader([[
        new IndexMatches([$newest->getId() ?? 0, $middle->getId() ?? 0], []),
        new IndexMatches([$middle->getId() ?? 0, $oldest->getId() ?? 0], []),
    ]]);

    $result = $this->list($reader, $this->query([
        new SavedSearchTerm(10, SearchTerms::fromInput('newest middle')),
        new SavedSearchTerm(20, SearchTerms::fromInput('middle oldest')),
    ]));

    self::assertSame(['newest', 'middle', 'oldest'], array_map(
        static fn (EntryListRow $row): string => $row->entry->getGuid(),
        $result->rows,
    ));
    self::assertSame(3, $result->matchCount);
    self::assertSame([
        $newest->getId() => 10,
        $middle->getId() => 10, // first search in order wins the badge
        $oldest->getId() => 20,
    ], $result->savedSearchIds);
}

public function testTruncatesToTheLimitAndResumesPastTheLastCandidate(): void
{
    $newest = $this->entry('newest', '2026-07-13T00:00:00Z');
    $middle = $this->entry('middle', '2026-07-12T00:00:00Z');
    $oldest = $this->entry('oldest', '2026-07-11T00:00:00Z');

    $reader = new FakeMultiSearchReader([[
        new IndexMatches([$newest->getId() ?? 0, $middle->getId() ?? 0, $oldest->getId() ?? 0], []),
    ]]);

    $result = $this->list($reader, $this->query([new SavedSearchTerm(1, SearchTerms::fromInput('post'))], limit: 2));

    self::assertSame(['newest', 'middle'], array_map(
        static fn (EntryListRow $row): string => $row->entry->getGuid(),
        $result->rows,
    ));
    self::assertNotNull($result->continuationRow);
    self::assertSame('middle', $result->continuationRow->entry->getGuid());
    self::assertSame(3, $result->matchCount);
}

public function testTheUnreadFilterDropsReadRowsButStillResumesPastThem(): void
{
    $readNewer = $this->entry('read-newer', '2026-07-13T00:00:00Z');
    $unread = $this->entry('unread', '2026-07-12T00:00:00Z');
    $readOlder = $this->entry('read-older', '2026-07-11T00:00:00Z');
    $this->markRead($readNewer);
    $this->markRead($readOlder);

    $reader = new FakeMultiSearchReader([[
        new IndexMatches([$readNewer->getId() ?? 0, $unread->getId() ?? 0, $readOlder->getId() ?? 0], []),
    ]]);

    $result = $this->list($reader, $this->query([new SavedSearchTerm(1, SearchTerms::fromInput('post'))], onlyUnread: true, limit: 3));

    self::assertSame(['unread'], array_map(
        static fn (EntryListRow $row): string => $row->entry->getGuid(),
        $result->rows,
    ));
    self::assertNotNull($result->continuationRow);
    self::assertSame('read-older', $result->continuationRow->entry->getGuid());
    self::assertSame(3, $result->matchCount, 'The engine frontier survives the unread filter.');
}

public function testAGhostIdIsDroppedFromRowsButNotFromTheMatchCount(): void
{
    $real = $this->entry('real');

    $reader = new FakeMultiSearchReader([[new IndexMatches([$real->getId() ?? 0, 999999], [])]]);

    $result = $this->list($reader, $this->query([new SavedSearchTerm(1, SearchTerms::fromInput('post'))]));

    self::assertSame(['real'], array_map(static fn (EntryListRow $row): string => $row->entry->getGuid(), $result->rows));
    self::assertSame(2, $result->matchCount);
}

public function testNoSavedSearchesReturnsEmptyWithoutAskingTheEngine(): void
{
    $reader = new FakeMultiSearchReader();

    $result = $this->list($reader, $this->query([]));

    self::assertSame([], $result->rows);
    self::assertSame([], $reader->receivedRounds);
}

public function testAUserWithNoSubscriptionsReturnsEmptyWithoutAskingTheEngine(): void
{
    $lonely = new User('lonely@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    $this->em->persist($lonely);
    $this->em->flush();
    $reader = new FakeMultiSearchReader();

    $result = (new IndexedSavedSearchEntries(
        $reader,
        self::getContainer()->get(FeedRepository::class),
        self::getContainer()->get(EntryListRepository::class),
    ))->list(new SavedSearchEntryQuery(
        userId: $lonely->getId() ?? 0,
        savedSearches: [new SavedSearchTerm(1, SearchTerms::fromInput('post'))],
    ));

    self::assertSame([], $result->rows);
    self::assertSame([], $reader->receivedRounds);
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Service/Search/IndexedSavedSearchEntriesTest.php`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Create the interface**

`backend/src/Service/Search/SavedSearchEntriesInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryQuery;

/**
 * The combined saved-search list as one operation, so the engine and the
 * database expose the same shape and services.yaml can swap them behind
 * SavedSearchEntriesWithFallback (mirrors EntrySearchInterface, #432/#973).
 */
interface SavedSearchEntriesInterface
{
    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult;
}
```

- [ ] **Step 4: Create the result object**

`backend/src/Service/Search/SavedSearchEntriesResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;

/**
 * What one combined saved-search read answers with. `savedSearchIds` maps each
 * shown entry to the first saved search (in sidebar order) that matched it —
 * the sidebar badge (#584). `matchCount` is the read's own frontier before
 * hydration drops ghosts and the unread filter drops read rows, so
 * EntryPage::withMatchCount can tell a full page from a short one.
 * `continuationRow` is the last candidate the page must resume past, which a
 * post-filtered (unread) page separates from its last shown row.
 */
final readonly class SavedSearchEntriesResult
{
    /**
     * @param list<EntryListRow> $rows
     * @param array<int, int>    $savedSearchIds entryId => first matching saved search id
     */
    public function __construct(
        public array $rows,
        public array $savedSearchIds,
        public int $matchCount,
        public ?EntryListRow $continuationRow = null,
    ) {
    }
}
```

- [ ] **Step 5: Create `IndexedSavedSearchEntries`**

`backend/src/Service/Search/IndexedSavedSearchEntries.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\FeedRepository;
use App\Repository\SavedSearchEntryQuery;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;

/**
 * The combined saved-search list through the engine: one engine query per saved
 * search in a single /multi-search, unioned and hydrated once. Matching moves
 * to the engine (typo tolerance, full content, per-mode correctness); order and
 * access stay the database's — rowsByIdsForUser re-sorts newest-first and
 * applies the subscription gate, so the engine's own order and filter are never
 * the last word on what a caller sees (mirrors IndexedEntrySearch).
 *
 * Does not catch SearchEngineUnavailableException itself: a caller wanting the
 * LIKE fallback on that failure decorates this class instead.
 */
final readonly class IndexedSavedSearchEntries implements SavedSearchEntriesInterface
{
    public function __construct(
        private SearchIndexReader $index,
        private FeedRepository $feeds,
        private EntryListRepository $entries,
    ) {
    }

    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult
    {
        if ($query->savedSearches === []) {
            return new SavedSearchEntriesResult([], [], 0);
        }

        $feedIds = $this->feeds->idsSubscribedByUser($query->userId);
        if ($feedIds === []) {
            return new SavedSearchEntriesResult([], [], 0);
        }

        $firstMatch = $this->firstMatchBySearch(
            $query->savedSearches,
            $this->index->findMany($this->indexSearches($query, $feedIds)),
        );

        $candidates = $this->entries->rowsByIdsForUser(array_keys($firstMatch), $query->userId);
        $page = \array_slice($candidates, 0, $query->limit);
        $rows = $query->onlyUnread ? $this->unreadOnly($page) : $page;

        return new SavedSearchEntriesResult(
            rows: $rows,
            savedSearchIds: $this->badgesFor($rows, $firstMatch),
            matchCount: \count($firstMatch),
            continuationRow: $page[array_key_last($page)] ?? null,
        );
    }

    /**
     * @param list<int> $feedIds
     *
     * @return list<IndexSearch>
     */
    private function indexSearches(SavedSearchEntryQuery $query, array $feedIds): array
    {
        return array_map(
            static fn (SavedSearchTerm $savedSearch): IndexSearch => new IndexSearch(
                terms: $savedSearch->terms,
                feedIds: $feedIds,
                cursor: $query->cursor,
                limit: $query->limit,
            ),
            $query->savedSearches,
        );
    }

    /**
     * entryId => the id of the first saved search (in sidebar order) whose
     * result held it. For a shown entry every matching search returned it, so
     * the first to return it is the first that matches — the same rule the DB
     * firstMatchExpression computes, kept consistent with the engine's matching.
     *
     * @param list<SavedSearchTerm> $savedSearches
     * @param list<IndexMatches>    $matches
     *
     * @return array<int, int>
     */
    private function firstMatchBySearch(array $savedSearches, array $matches): array
    {
        $firstMatch = [];
        foreach ($matches as $position => $result) {
            foreach ($result->entryIds as $entryId) {
                $firstMatch[$entryId] ??= $savedSearches[$position]->id;
            }
        }

        return $firstMatch;
    }

    /**
     * @param list<EntryListRow> $rows
     * @param array<int, int>    $firstMatch
     *
     * @return array<int, int>
     */
    private function badgesFor(array $rows, array $firstMatch): array
    {
        $badges = [];
        foreach ($rows as $row) {
            $entryId = (int) $row->entry->getId();
            $badges[$entryId] = $firstMatch[$entryId];
        }

        return $badges;
    }

    /**
     * @param list<EntryListRow> $candidates
     *
     * @return list<EntryListRow>
     */
    private function unreadOnly(array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (EntryListRow $row): bool => !$row->isHidden,
        ));
    }
}
```

- [ ] **Step 6: Run to verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Search/IndexedSavedSearchEntriesTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Search backend/tests/Service/Search/IndexedSavedSearchEntriesTest.php
git commit -m "feat(#973): rank the combined saved-search list through the engine"
```

---

### Task 4: `DatabaseSavedSearchEntries` — the fallback list

**Files:**
- Create: `backend/src/Service/Search/DatabaseSavedSearchEntries.php`
- Test: `backend/tests/Service/Search/DatabaseSavedSearchEntriesTest.php`

**Interfaces:**
- Consumes: `SavedSearchEntryRepository::listForSavedSearches(SavedSearchEntryQuery): list<EntryListRow>`, `SavedSearchEntryRepository::matchedSavedSearchIds(list<int>, list<SavedSearchTerm>): array<int,int>`.
- Produces: `DatabaseSavedSearchEntries implements SavedSearchEntriesInterface`, returning a `SavedSearchEntriesResult` with `matchCount = count(rows)` and `continuationRow = null` (reproduces today's `EntryPage::of` output exactly).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Search/DatabaseSavedSearchEntriesTest.php` (`DbTestCase`, real `SavedSearchEntryRepository` from the container). Seed one feed with two matching entries, two saved searches, then:

```php
public function testReturnsTheRepositoryRowsWithBadgesAndACountEqualToTheRows(): void
{
    // ... seed a subscribed feed with entries 'Angular one' and 'Angular two',
    //     and a saved search term 'angular' ...
    $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
    self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

    $query = new SavedSearchEntryQuery(
        userId: $this->user->getId() ?? 0,
        savedSearches: [new SavedSearchTerm($this->savedSearchId, SearchTerms::fromInput('angular'))],
    );

    $result = (new DatabaseSavedSearchEntries($repository))->list($query);

    self::assertCount(2, $result->rows);
    self::assertSame(\count($result->rows), $result->matchCount);
    self::assertNull($result->continuationRow);
    foreach ($result->rows as $row) {
        self::assertSame($this->savedSearchId, $result->savedSearchIds[(int) $row->entry->getId()]);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Service/Search/DatabaseSavedSearchEntriesTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement**

`backend/src/Service/Search/DatabaseSavedSearchEntries.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryQuery;
use App\Repository\SavedSearchEntryRepository;

/**
 * The combined saved-search list through the database — the AND-of-LIKE query
 * the reader ships today, now behind the same interface as the engine path so
 * SavedSearchEntriesWithFallback can choose between them. It returns exactly
 * what the controller assembled before #973: the repository rows, the DB badge
 * attribution, and a match count equal to the row count (the LIKE list returns
 * the page it shows, so the last row is the resume point and no continuation
 * row is needed).
 */
final readonly class DatabaseSavedSearchEntries implements SavedSearchEntriesInterface
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult
    {
        $rows = $this->entries->listForSavedSearches($query);
        $entryIds = array_map(static fn (EntryListRow $row): int => (int) $row->entry->getId(), $rows);

        return new SavedSearchEntriesResult(
            rows: $rows,
            savedSearchIds: $this->entries->matchedSavedSearchIds($entryIds, $query->savedSearches),
            matchCount: \count($rows),
        );
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Search/DatabaseSavedSearchEntriesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Search/DatabaseSavedSearchEntries.php backend/tests/Service/Search/DatabaseSavedSearchEntriesTest.php
git commit -m "feat(#973): wrap the LIKE saved-search list behind the shared interface"
```

---

### Task 5: `SavedSearchEntriesWithFallback`

**Files:**
- Create: `backend/src/Service/Search/SavedSearchEntriesWithFallback.php`
- Test: `backend/tests/Service/Search/SavedSearchEntriesWithFallbackTest.php`

**Interfaces:**
- Consumes: `IndexedSavedSearchEntries`, `DatabaseSavedSearchEntries`, `LoggerInterface`, `SearchEngineCapability`, `SearchEngineUnavailableException`.
- Produces: `SavedSearchEntriesWithFallback implements SavedSearchEntriesInterface`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Search/SavedSearchEntriesWithFallbackTest.php` mirroring `EntrySearchWithFallbackTest`. Use a `FakeMultiSearchReader` for the engine side and the real repositories for the database side. A `fallback()` helper builds the subject:

```php
private function fallback(SearchIndexReader $reader, LoggerInterface $logger, string $engineUrl): SavedSearchEntriesWithFallback
{
    $entryListRepository = self::getContainer()->get(EntryListRepository::class);
    self::assertInstanceOf(EntryListRepository::class, $entryListRepository);
    /** @var FeedRepository $feedRepository */
    $feedRepository = self::getContainer()->get(FeedRepository::class);
    /** @var SavedSearchEntryRepository $savedSearchRepository */
    $savedSearchRepository = self::getContainer()->get(SavedSearchEntryRepository::class);

    return new SavedSearchEntriesWithFallback(
        new IndexedSavedSearchEntries($reader, $feedRepository, $entryListRepository),
        new DatabaseSavedSearchEntries($savedSearchRepository),
        $logger,
        new SearchEngineCapability($engineUrl, ''),
    );
}
```

Tests (seed a subscribed feed + one saved search matching one entry so both paths can return something):

```php
public function testAnUnconfiguredEngineIsNeverCalledAndTheDatabaseAnswers(): void
{
    $reader = new FakeMultiSearchReader();
    $result = $this->fallback($reader, new NullLogger(), '')->list($this->query());

    self::assertSame([], $reader->receivedRounds);
    self::assertNotEmpty($result->rows); // the DB path found the seeded match
}

public function testTheUnconfiguredPathLogsNothing(): void
{
    $logSpy = new TestHandler();
    $this->fallback(new FakeMultiSearchReader(), new Logger('test', [$logSpy]), '')->list($this->query());
    self::assertSame([], $logSpy->getRecords());
}

public function testAConfiguredEngineAnswers(): void
{
    // The engine returns the seeded entry's id; a configured engine must be used.
    $reader = new FakeMultiSearchReader([[new IndexMatches([$this->matchId], [])]]);
    $logSpy = new TestHandler();

    $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')->list($this->query());

    self::assertNotSame([], $reader->receivedRounds);
    self::assertSame([], $logSpy->getRecords());
}

public function testAnUnavailableEngineFallsBackToTheDatabaseAndLogsExactlyOneWarning(): void
{
    $reader = new FakeMultiSearchReader(failure: new SearchEngineUnavailableException('no answer'));
    $logSpy = new TestHandler();

    $result = $this->fallback($reader, new Logger('test', [$logSpy]), 'http://meilisearch.test')->list($this->query());

    self::assertNotEmpty($result->rows); // the DB path answered
    self::assertTrue($logSpy->hasWarningRecords());
    self::assertCount(1, $logSpy->getRecords());
}

public function testAnUnexpectedExceptionIsNotSwallowed(): void
{
    $reader = new FakeMultiSearchReader(failure: new \RuntimeException('boom'));
    $this->expectException(\RuntimeException::class);
    $this->fallback($reader, new NullLogger(), 'http://meilisearch.test')->list($this->query());
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Service/Search/SavedSearchEntriesWithFallbackTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement**

`backend/src/Service/Search/SavedSearchEntriesWithFallback.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryQuery;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * What services.yaml hands every caller of SavedSearchEntriesInterface: prefer
 * the engine, fall back to the database. Same rule as EntrySearchWithFallback —
 * an unconfigured engine is the silent Strato case, an unavailable engine is one
 * warning, any other exception propagates rather than hide behind a worse result.
 */
final readonly class SavedSearchEntriesWithFallback implements SavedSearchEntriesInterface
{
    public function __construct(
        private IndexedSavedSearchEntries $engine,
        private DatabaseSavedSearchEntries $database,
        private LoggerInterface $logger,
        private SearchEngineCapability $capability,
    ) {
    }

    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult
    {
        if (!$this->capability->isConfigured()) {
            return $this->database->list($query);
        }

        try {
            return $this->engine->list($query);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; falling back to database saved-search list.', [
                'exception' => $e,
            ]);

            return $this->database->list($query);
        }
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Search/SavedSearchEntriesWithFallbackTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Search/SavedSearchEntriesWithFallback.php backend/tests/Service/Search/SavedSearchEntriesWithFallbackTest.php
git commit -m "feat(#973): make the saved-search list engine optional with a fallback"
```

---

### Task 6: Wire the list endpoint to the interface

**Files:**
- Modify: `backend/src/Http/SavedSearchPage.php`
- Modify: `backend/src/Controller/Api/SavedSearchEntriesController.php`
- Modify: `backend/config/services.yaml`
- Test: `backend/tests/Http/SavedSearchPageTest.php`
- Test (must stay green): `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php`

**Interfaces:**
- Consumes: `SavedSearchEntriesResult`, `SavedSearchEntriesInterface`, `EntryPage::withMatchCount`, `EntryListSort::PublishedDate`.
- Produces: `SavedSearchPage::of(SavedSearchEntriesResult $result, int $limit): array{entries,nextCursor,savedSearchIds}`.

- [ ] **Step 1: Update the failing `SavedSearchPageTest`**

Rewrite the test to build the page from a `SavedSearchEntriesResult`. Add the continuation-row and `{}`-encoding cases:

```php
public function testTheContinuationRowDecidesTheCursor(): void
{
    $result = new SavedSearchEntriesResult([], [], matchCount: 1, continuationRow: $this->row(4));

    $page = SavedSearchPage::of($result, 1);

    self::assertSame([], $page['entries']);
    self::assertNotNull($page['nextCursor'], 'A fully-read page must still advance the cursor.');
    $cursor = EntryCursor::decode($page['nextCursor']);
    self::assertNotNull($cursor);
    self::assertSame(4, $cursor->id);
}

public function testAnEmptyBadgeMapEncodesAsAnObject(): void
{
    $result = new SavedSearchEntriesResult([], [], matchCount: 0);

    $page = SavedSearchPage::of($result, 50);

    self::assertEquals(new \stdClass(), $page['savedSearchIds']);
}

public function testTheBadgeMapIsCarriedThrough(): void
{
    $row = $this->row(7);
    $result = new SavedSearchEntriesResult([$row], [7 => 3], matchCount: 1);

    $page = SavedSearchPage::of($result, 50);

    self::assertEquals((object) [7 => 3], $page['savedSearchIds']);
}
```

Keep or adapt the file's existing `row(int $id): EntryListRow` helper.

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Http/SavedSearchPageTest.php`
Expected: FAIL — `SavedSearchPage::of` still has the old signature.

- [ ] **Step 3: Update `SavedSearchPage`**

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListSort;
use App\Service\Search\SavedSearchEntriesResult;

/**
 * The `{entries, nextCursor, savedSearchIds}` shape the combined saved-search
 * list returns. The cursor rule belongs to EntryPage and must exist exactly
 * once; this adds only the badge map the combined list has beyond a plain list.
 */
final readonly class SavedSearchPage
{
    private function __construct()
    {
    }

    /**
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null, savedSearchIds: \stdClass}
     */
    public static function of(SavedSearchEntriesResult $result, int $limit): array
    {
        return [
            ...EntryPage::withMatchCount(
                $result->rows,
                $limit,
                $result->matchCount,
                EntryListSort::PublishedDate,
                $result->continuationRow,
            ),
            // Cast, not a bare array: an empty map must encode as `{}`, not `[]`.
            'savedSearchIds' => (object) $result->savedSearchIds,
        ];
    }
}
```

- [ ] **Step 4: Update the controller to delegate**

In `backend/src/Controller/Api/SavedSearchEntriesController.php`, replace the `SavedSearchEntryRepository` dependency for the list with `SavedSearchEntriesInterface`, and simplify `list`:

```php
public function __construct(
    private SavedSearchTerms $terms,
    private SavedSearchEntriesInterface $entries,
    private SavedSearchMarkReadService $markRead,
) {
}

#[Route('', name: 'api_entries_saved_searches', methods: ['GET'])]
public function list(
    #[CurrentUser] User $user,
    #[MapQueryParameter] ?string $cursor = null,
    #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
    #[MapQueryParameter] bool $unread = false,
): JsonResponse {
    $userId = (int) $user->getId();
    $query = new SavedSearchEntryQuery(
        userId: $userId,
        savedSearches: $this->terms->forUser($userId),
        onlyUnread: $unread,
        cursor: EntryCursor::fromRequestValue($cursor),
        limit: $limit,
    );

    return new JsonResponse(SavedSearchPage::of($this->entries->list($query), $query->limit));
}
```

Update the imports: drop `App\Repository\EntryListRow` and `App\Repository\SavedSearchEntryRepository`; add `App\Service\Search\SavedSearchEntriesInterface`. Keep `SavedSearchTerms`, `SavedSearchMarkReadService`, `SavedSearchEntryQuery`, `EntryCursor`, `EntryQuery`, `SavedSearchPage`.

- [ ] **Step 5: Alias the interface in `services.yaml`**

Under the existing search aliases (after line 118), add:

```yaml
    App\Service\Search\SavedSearchEntriesInterface: '@App\Service\Search\SavedSearchEntriesWithFallback'
```

- [ ] **Step 6: Run tests to verify pass**

Run: `cd backend && php bin/phpunit tests/Http/SavedSearchPageTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php`
Expected: PASS. The controller test runs the DB fallback (no engine in test env), so its assertions are unchanged.

- [ ] **Step 7: Warm the cache and run PHPStan (thin-controller rule)**

Run: `cd backend && bin/console cache:warmup && composer stan`
Expected: PASS — the action still only reads, delegates, returns.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Http/SavedSearchPage.php backend/src/Controller/Api/SavedSearchEntriesController.php backend/config/services.yaml backend/tests/Http/SavedSearchPageTest.php
git commit -m "feat(#973): serve the saved-search list through the fallback interface"
```

---

### Task 7: Mark-read match source (interface, cursor factory, engine path)

**Files:**
- Modify: `backend/src/Http/EntryCursor.php`
- Create: `backend/src/Service/Search/SavedSearchUnreadMatchSource.php`
- Create: `backend/src/Service/Search/IndexedSavedSearchUnreadMatches.php`
- Test: `backend/tests/Http/EntryCursorTest.php` (add a case; create if absent)
- Test: `backend/tests/Service/Search/IndexedSavedSearchUnreadMatchesTest.php`

**Interfaces:**
- Consumes: `IndexedSavedSearchEntries::list`, `SavedSearchEntriesResult`, `EntryCursor`, `SavedSearchEntryQuery`, `EntryQuery::MAX_LIMIT`, `SavedSearchTerm`, `EntryListRow`.
- Produces:
  - `EntryCursor::inclusiveUpperBound(\DateTimeImmutable $until): self`
  - `SavedSearchUnreadMatchSource::unreadMatchIdsUpTo(int $userId, list<SavedSearchTerm> $savedSearches, \DateTimeImmutable $until): list<int>`
  - `IndexedSavedSearchUnreadMatches implements SavedSearchUnreadMatchSource`

- [ ] **Step 1: Write the failing cursor test**

Add to `backend/tests/Http/EntryCursorTest.php` (create the file with the standard header + `use App\Http\EntryCursor;` if it does not exist):

```php
public function testInclusiveUpperBoundAdmitsEveryRealIdAtThatInstant(): void
{
    $until = new \DateTimeImmutable('2026-07-12T00:00:00Z');

    $cursor = EntryCursor::inclusiveUpperBound($until);

    self::assertSame($until, $cursor->sortInstant);
    self::assertSame(PHP_INT_MAX, $cursor->id);
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Http/EntryCursorTest.php`
Expected: FAIL — method not defined.

- [ ] **Step 3: Add the factory**

In `backend/src/Http/EntryCursor.php`, add:

```php
/**
 * The upper bound of a keyset walk that must include every row AT $until, not
 * only those strictly before it. The keyset predicate is strict (id < c.id),
 * so the max int id admits every real (auto-increment) id at $until. Internal
 * to the engine mark-read enumeration; never encoded for a client.
 */
public static function inclusiveUpperBound(\DateTimeImmutable $until): self
{
    return new self($until, PHP_INT_MAX);
}
```

- [ ] **Step 4: Write the failing engine mark-read tests**

Create `backend/tests/Service/Search/IndexedSavedSearchUnreadMatchesTest.php` (`DbTestCase`, same seed helpers). Build the subject on a real `IndexedSavedSearchEntries` + `FakeMultiSearchReader`:

```php
private function source(FakeMultiSearchReader $reader): IndexedSavedSearchUnreadMatches
{
    $entryListRepository = self::getContainer()->get(EntryListRepository::class);
    self::assertInstanceOf(EntryListRepository::class, $entryListRepository);
    /** @var FeedRepository $feedRepository */
    $feedRepository = self::getContainer()->get(FeedRepository::class);

    return new IndexedSavedSearchUnreadMatches(
        new IndexedSavedSearchEntries($reader, $feedRepository, $entryListRepository),
    );
}

/** @param list<SavedSearchTerm> $savedSearches @return list<int> */
private function markSet(FakeMultiSearchReader $reader, array $savedSearches, \DateTimeImmutable $until): array
{
    return $this->source($reader)->unreadMatchIdsUpTo($this->user->getId() ?? 0, $savedSearches, $until);
}
```

Tests:

```php
public function testCollectsUnreadMatchesUpToTheWatermarkAndDropsReadOnes(): void
{
    $unread = $this->entry('unread', '2026-07-12T00:00:00Z');
    $read = $this->entry('read', '2026-07-11T00:00:00Z');
    $this->markRead($read);

    $reader = new FakeMultiSearchReader([[new IndexMatches([$unread->getId() ?? 0, $read->getId() ?? 0], [])]]);

    $ids = $this->markSet($reader, [new SavedSearchTerm(1, SearchTerms::fromInput('post'))], new \DateTimeImmutable('2026-07-13T00:00:00Z'));

    self::assertSame([$unread->getId()], $ids);
}

public function testEmptySavedSearchesReturnEmptyWithoutAskingTheEngine(): void
{
    $reader = new FakeMultiSearchReader();

    self::assertSame([], $this->markSet($reader, [], new \DateTimeImmutable('2026-07-13T00:00:00Z')));
    self::assertSame([], $reader->receivedRounds);
}

public function testPagesUntilAPartialPageThenStops(): void
{
    // A full first page (matchCount == MAX_LIMIT) forces a second round; the
    // second is partial and ends it. Only a few ids are real entries — the rest
    // pad the engine frontier so matchCount reaches MAX_LIMIT without seeding 100 rows.
    $firstReal = $this->entry('first', '2026-07-12T00:00:00Z');
    $secondReal = $this->entry('second', '2026-07-10T00:00:00Z');
    $ghosts = range(900000, 900000 + EntryQuery::MAX_LIMIT - 2); // MAX_LIMIT-1 ghost ids
    $firstPage = new IndexMatches([$firstReal->getId() ?? 0, ...$ghosts], []); // MAX_LIMIT ids
    $secondPage = new IndexMatches([$secondReal->getId() ?? 0], []);            // partial → stop

    $reader = new FakeMultiSearchReader([[$firstPage], [$secondPage]]);

    $ids = $this->markSet($reader, [new SavedSearchTerm(1, SearchTerms::fromInput('post'))], new \DateTimeImmutable('2026-07-13T00:00:00Z'));

    self::assertSame([$firstReal->getId(), $secondReal->getId()], $ids);
    self::assertCount(2, $reader->receivedRounds, 'A full page must trigger exactly one more round.');
}
```

(If `MAX_LIMIT` entries are undesirable even as ghosts, this still seeds only 2 real rows; the ghosts are integers, not persisted.)

- [ ] **Step 5: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Service/Search/IndexedSavedSearchUnreadMatchesTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 6: Create the interface**

`backend/src/Service/Search/SavedSearchUnreadMatchSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * The set of unread entry ids the combined saved-search mark-read flips: every
 * unread entry any saved search matches, no newer than $until. Behind an
 * interface so the engine path and the LIKE path answer the same question and
 * services.yaml can swap them (mirrors SavedSearchEntriesInterface, #973).
 */
interface SavedSearchUnreadMatchSource
{
    /**
     * @param list<SavedSearchTerm> $savedSearches
     *
     * @return list<int>
     */
    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array;
}
```

- [ ] **Step 7: Create the engine path**

`backend/src/Service/Search/IndexedSavedSearchUnreadMatches.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Http\EntryCursor;
use App\Repository\EntryListRow;
use App\Repository\EntryQuery;
use App\Repository\SavedSearchEntryQuery;

/**
 * The engine-consistent mark-read set. Rather than reimplement engine paging,
 * it drives the indexed list itself: seed the cursor at the inclusive upper
 * bound of $until, then page the unread list to exhaustion, collecting the ids
 * it shows. That reuses the list's union, hydration, access gate and cursor, so
 * the marked set is exactly what the engine-ranked list shows — including the
 * content/typo matches the LIKE predicate never sees.
 *
 * Depends on the raw IndexedSavedSearchEntries, not the fallback: a mid-walk
 * engine failure must surface as SearchEngineUnavailableException so
 * SavedSearchUnreadMatchesWithFallback recomputes the whole set from the
 * database, rather than silently mixing an engine prefix with a LIKE tail.
 */
final readonly class IndexedSavedSearchUnreadMatches implements SavedSearchUnreadMatchSource
{
    /**
     * The largest page SavedSearchEntryQuery accepts: a bigger value would be
     * clamped at construction and the "partial page ends the walk" test below
     * would compare matchCount against a page size the query never used.
     */
    private const int ENUMERATION_PAGE = EntryQuery::MAX_LIMIT;

    public function __construct(private IndexedSavedSearchEntries $list)
    {
    }

    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array
    {
        if ($savedSearches === []) {
            return [];
        }

        $ids = [];
        $cursor = EntryCursor::inclusiveUpperBound($until);
        do {
            $result = $this->list->list(new SavedSearchEntryQuery(
                userId: $userId,
                savedSearches: $savedSearches,
                onlyUnread: true,
                cursor: $cursor,
                limit: self::ENUMERATION_PAGE,
            ));
            foreach ($result->rows as $row) {
                $ids[] = (int) $row->entry->getId();
            }
            $cursor = $this->nextCursor($result);
        } while ($cursor !== null);

        return $ids;
    }

    private function nextCursor(SavedSearchEntriesResult $result): ?EntryCursor
    {
        if ($result->matchCount < self::ENUMERATION_PAGE || $result->continuationRow === null) {
            return null;
        }

        $entry = $result->continuationRow->entry;

        return new EntryCursor($entry->getEffectiveDate(), (int) $entry->getId());
    }
}
```

- [ ] **Step 8: Run tests to verify pass**

Run: `cd backend && php bin/phpunit tests/Http/EntryCursorTest.php tests/Service/Search/IndexedSavedSearchUnreadMatchesTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Http/EntryCursor.php backend/src/Service/Search/SavedSearchUnreadMatchSource.php backend/src/Service/Search/IndexedSavedSearchUnreadMatches.php backend/tests/Http/EntryCursorTest.php backend/tests/Service/Search/IndexedSavedSearchUnreadMatchesTest.php
git commit -m "feat(#973): enumerate the engine mark-read set through the list"
```

---

### Task 8: `DatabaseSavedSearchUnreadMatches` — the fallback source

**Files:**
- Create: `backend/src/Service/Search/DatabaseSavedSearchUnreadMatches.php`
- Test: `backend/tests/Service/Search/DatabaseSavedSearchUnreadMatchesTest.php`

**Interfaces:**
- Consumes: `SavedSearchEntryRepository::unreadMatchIdsForSavedSearches(int, list<SavedSearchTerm>, \DateTimeImmutable): list<int>`.
- Produces: `DatabaseSavedSearchUnreadMatches implements SavedSearchUnreadMatchSource`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Search/DatabaseSavedSearchUnreadMatchesTest.php` (`DbTestCase`). Seed a subscribed feed with an unread match at/below `until` and a read one, then:

```php
public function testReturnsTheRepositoryUnreadMatchSet(): void
{
    // ... seed 'angular' unread entry and a read one, both <= until ...
    $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
    self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

    $ids = (new DatabaseSavedSearchUnreadMatches($repository))->unreadMatchIdsUpTo(
        $this->user->getId() ?? 0,
        [new SavedSearchTerm(1, SearchTerms::fromInput('angular'))],
        new \DateTimeImmutable('2026-07-20T00:00:00Z'),
    );

    self::assertSame([$this->unreadId], $ids);
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Service/Search/DatabaseSavedSearchUnreadMatchesTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement**

`backend/src/Service/Search/DatabaseSavedSearchUnreadMatches.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryRepository;

/**
 * The LIKE mark-read set the reader ships today, behind the shared interface so
 * SavedSearchUnreadMatchesWithFallback can choose it when no engine answers.
 * A thin adapter: the repository already computes exactly this set.
 */
final readonly class DatabaseSavedSearchUnreadMatches implements SavedSearchUnreadMatchSource
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array
    {
        return $this->entries->unreadMatchIdsForSavedSearches($userId, $savedSearches, $until);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Search/DatabaseSavedSearchUnreadMatchesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Search/DatabaseSavedSearchUnreadMatches.php backend/tests/Service/Search/DatabaseSavedSearchUnreadMatchesTest.php
git commit -m "feat(#973): wrap the LIKE mark-read set behind the shared interface"
```

---

### Task 9: `SavedSearchUnreadMatchesWithFallback`

**Files:**
- Create: `backend/src/Service/Search/SavedSearchUnreadMatchesWithFallback.php`
- Test: `backend/tests/Service/Search/SavedSearchUnreadMatchesWithFallbackTest.php`

**Interfaces:**
- Consumes: `IndexedSavedSearchUnreadMatches`, `DatabaseSavedSearchUnreadMatches`, `LoggerInterface`, `SearchEngineCapability`, `SearchEngineUnavailableException`.
- Produces: `SavedSearchUnreadMatchesWithFallback implements SavedSearchUnreadMatchSource`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Service/Search/SavedSearchUnreadMatchesWithFallbackTest.php`, same shape as Task 5's fallback test. `fallback()` builds:

```php
return new SavedSearchUnreadMatchesWithFallback(
    new IndexedSavedSearchUnreadMatches(new IndexedSavedSearchEntries($reader, $feedRepository, $entryListRepository)),
    new DatabaseSavedSearchUnreadMatches($savedSearchRepository),
    $logger,
    new SearchEngineCapability($engineUrl, ''),
);
```

Cover: unconfigured → database, reader never called, nothing logged; unavailable engine → database, exactly one warning; unexpected exception propagates; a configured engine is used (`receivedRounds` not empty). Assert the returned id set matches the path that answered.

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Service/Search/SavedSearchUnreadMatchesWithFallbackTest.php`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement**

`backend/src/Service/Search/SavedSearchUnreadMatchesWithFallback.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * What services.yaml hands every caller of SavedSearchUnreadMatchSource: prefer
 * the engine, fall back to the database. Same rule as EntrySearchWithFallback.
 * On an unavailable engine the partial engine walk is discarded and the whole
 * set is recomputed from the database, so mark-read never marks a mixed set.
 */
final readonly class SavedSearchUnreadMatchesWithFallback implements SavedSearchUnreadMatchSource
{
    public function __construct(
        private IndexedSavedSearchUnreadMatches $engine,
        private DatabaseSavedSearchUnreadMatches $database,
        private LoggerInterface $logger,
        private SearchEngineCapability $capability,
    ) {
    }

    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array
    {
        if (!$this->capability->isConfigured()) {
            return $this->database->unreadMatchIdsUpTo($userId, $savedSearches, $until);
        }

        try {
            return $this->engine->unreadMatchIdsUpTo($userId, $savedSearches, $until);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; falling back to database saved-search mark-read.', [
                'exception' => $e,
            ]);

            return $this->database->unreadMatchIdsUpTo($userId, $savedSearches, $until);
        }
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `cd backend && php bin/phpunit tests/Service/Search/SavedSearchUnreadMatchesWithFallbackTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Search/SavedSearchUnreadMatchesWithFallback.php backend/tests/Service/Search/SavedSearchUnreadMatchesWithFallbackTest.php
git commit -m "feat(#973): make the saved-search mark-read engine optional with a fallback"
```

---

### Task 10: Route mark-read through the match source

**Files:**
- Modify: `backend/src/Service/Reader/SavedSearchMarkReadService.php`
- Modify: `backend/config/services.yaml`
- Test (must stay green): `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php`
- Test: `backend/tests/Service/Reader/SavedSearchMarkReadServiceTest.php` (adapt if it constructs the service directly)

**Interfaces:**
- Consumes: `SavedSearchTerms::forUser`, `SavedSearchUnreadMatchSource::unreadMatchIdsUpTo`, `BulkEntryReadMarker::markRead`.
- Produces: `SavedSearchMarkReadService` unchanged externally (`mark(User, \DateTimeImmutable): void`).

- [ ] **Step 1: Check how the service is tested**

Run: `grep -rn "new SavedSearchMarkReadService\|SavedSearchMarkReadService" backend/tests`
If a test constructs it directly with a `SavedSearchEntryRepository`, that constructor argument becomes a `SavedSearchUnreadMatchSource` — update the test to pass `new DatabaseSavedSearchUnreadMatches($repository)` so it still exercises the same set through the new seam.

- [ ] **Step 2: Rewire the service**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\User;
use App\Service\Search\SavedSearchTerms;
use App\Service\Search\SavedSearchUnreadMatchSource;

/**
 * Marks read every unread entry that matches any of the caller's saved
 * searches. The match set now comes through SavedSearchUnreadMatchSource, so it
 * is engine-consistent when Meilisearch is configured and the LIKE set when it
 * is not — exactly the set the combined list shows on the same path (#973).
 */
final readonly class SavedSearchMarkReadService
{
    public function __construct(
        private SavedSearchTerms $terms,
        private SavedSearchUnreadMatchSource $matches,
        private BulkEntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();

        $this->readMarker->markRead($userId, $this->matches->unreadMatchIdsUpTo(
            $userId,
            $this->terms->forUser($userId),
            $until,
        ));
    }
}
```

- [ ] **Step 3: Alias the interface in `services.yaml`**

Under the Task 6 alias, add:

```yaml
    App\Service\Search\SavedSearchUnreadMatchSource: '@App\Service\Search\SavedSearchUnreadMatchesWithFallback'
```

- [ ] **Step 4: Run the affected tests**

Run: `cd backend && php bin/phpunit tests/Service/Reader/SavedSearchMarkReadServiceTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php`
Expected: PASS — mark-read runs the DB fallback in the test env, so `markRead` flips the same set as before.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/SavedSearchMarkReadService.php backend/config/services.yaml backend/tests/Service/Reader/SavedSearchMarkReadServiceTest.php
git commit -m "feat(#973): flip mark-read through the engine-consistent match source"
```

---

### Task 11: Whole-branch verification and PR

**Files:** none (verification only).

- [ ] **Step 1: Warm the cache (PHPStan needs it)**

Run: `cd backend && bin/console cache:warmup`

- [ ] **Step 2: Run the backend gate**

Run: `cd backend && composer check && composer md`
Expected: PASS. Fix any PHPMD finding in a touched file by improving the design, not the threshold. Note: CI runs the tip of phptramp's `develop`; if `composer tramp` reports a chain with no obvious cause in this branch, check `composer show larspohlmann/phptramp` before hunting in application code.

- [ ] **Step 3: Run the suite on both engines**

Run: `cd backend && php bin/phpunit`
Then: `docker compose exec php vendor/bin/phpunit` (from the repo root, the MySQL leg).
Expected: PASS on both.

- [ ] **Step 4: Run mutation testing over the changed lines**

Run: `cd backend && composer infection:diff`
Expected: at or above `minMsi`. Escaped mutants arrive as annotations on the offending line; kill them with a test that asserts the behavior, never by weakening a test.

- [ ] **Step 5: Scan today's dev log for swallowed errors**

Run: `ls -t backend/var/log/dev-*.log | head -1` then read the newest file for deprecations or warnings from the new code.

- [ ] **Step 6: Measure the cost note for the PR**

With the Docker stack up (engine configured), time one combined-list page and one mark-read against the LIKE baseline (e.g. via the profiler or a one-off timing in a scratch test). Record the comparison in the PR body, as the issue asks.

- [ ] **Step 7: Push and open the PR**

```bash
git push -u origin feature/973-saved-search-engine-ranking
```

Open a PR into `develop` whose body says `Closes #973`, summarizes the engine list + engine-consistent mark-read + fallback, links the spec, and carries the cost note. After merge, verify #973 closed.

---

## Self-Review

**Spec coverage:**
- §1 goal & scope (engine list + engine mark-read, DB fallback, backend-only) → Tasks 3–10.
- §2 gateway multi-search + §2.1 probe → Tasks 1–2.
- §3 indexed list, §3.1 union correctness, §3.2 attribution, §3.3 result object + `SavedSearchPage` → Tasks 3, 6.
- §4 engine-consistent mark-read + §4.1 inclusive upper bound → Task 7.
- §5 structure & fallback (both interface families) → Tasks 3–5, 7–10.
- §6 controller & service wiring → Tasks 6, 10.
- §7 testing → every task's tests; DB fallback coverage preserved (Tasks 6, 10 keep existing tests green).
- §8 cost, §9 file inventory → Task 11 + covered across tasks. §10 open decisions resolved in the spec.

**Placeholder scan:** No `TBD`/`TODO`/"add error handling"; every code and test step carries real content. The two DB-seed comments in Tasks 4/8 (`// ... seed ...`) are deliberate — they defer to the file's existing seed helpers rather than invent field names; the executor copies the setUp pattern from `IndexedEntrySearchTest`/`SavedSearchEntryListTest`.

**Type consistency:** `SavedSearchEntriesResult(rows, savedSearchIds, matchCount, continuationRow)` used identically in Tasks 3–7. `list(SavedSearchEntryQuery): SavedSearchEntriesResult`, `unreadMatchIdsUpTo(int, list<SavedSearchTerm>, \DateTimeImmutable): list<int>`, `findMany(list<IndexSearch>): list<IndexMatches>`, `EntryCursor::inclusiveUpperBound(\DateTimeImmutable): self`, `SavedSearchPage::of(SavedSearchEntriesResult, int)` are consistent across producer and consumer tasks.
