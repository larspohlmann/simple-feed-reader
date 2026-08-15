# Entry Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user find any entry in a feed they subscribe to by typing words that appear in its title or its summary.

**Architecture:** A new `GET /api/entries/search` endpoint answers the same `{entries, nextCursor}` document the entry list already returns, so the client needs no new model. Matching is an AND of escaped `LIKE` predicates behind an `EntrySearchInterface`, which is the one seam Elasticsearch or Solr replaces later. On the client, `?q=` becomes a new `Selection` kind that the existing `EntriesStore` loads through a second path in `ReaderApi`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM, Angular 20 with signals, Transloco, PHPUnit, Jest.

**Issue:** [#408](https://github.com/larspohlmann/simple-feed-reader/issues/408). **Branch:** `feature/408-entry-search`.

## Global Constraints

- `declare(strict_types=1)` in every PHP file. PSR-12. `final readonly class` with constructor promotion is the house style.
- PHPStan level max over `src` and `tests`. No new baseline, no `@phpstan-ignore` without a reason comment.
- Every `src` file touched must be PHPMD-clean (codesize ruleset) before commit, not merely free of new findings.
- Controllers carry no private method that does real work — `ThinControllerRule` enforces it.
- Comments explain *why*, never *what*.
- Frontend: standalone components and signals, no NgModules. Component styles in a sibling `.scss` via `styleUrl`, never inline. No hex colours and no raw `px` spacing outside `src/app/theme/`. Prettier at 100 columns.
- Every user-visible string gets a key in **both** `frontend/public/i18n/en.json` and `de.json`.
- The search matches on `entry.title` and `entry.summary` only. Never `content_html`.
- Search terms reach SQL only as bound parameters, and every `LIKE` that uses a pattern from `LikePattern` must declare `ESCAPE '!'`.
- Test assertions must not depend on non-ASCII case folding: the native suite is SQLite, production is MySQL, and only MySQL folds accents.
- Commit after every task. Never merge and never tag a deploy.

## Deviations from the issue body, already decided

1. **`validation_error` is HTTP 422, not 400.** `App\Exception\ValidationException` is fixed at 422 and the client already handles it. The issue body says 400; 422 is what ships.
2. **`EntrySearchQuery` lives in `App\Repository`, not `App\Service\Search`.** It is the parameter object for a repository read, exactly like `EntryQuery`, and the interface already returns `App\Repository\EntryListRow`. Keeping both in one namespace avoids a Service→Repository→Service loop.

---

### Task 1: Search term parsing and `LIKE` escaping

**Status: already complete on the branch, test-first and green (12 tests).** Reproduced here so the plan is self-contained. If starting fresh, follow the steps; if the files exist, verify with the test command in Step 6 and move to Task 2.

**Files:**
- Create: `backend/src/Service/Search/SearchTerms.php`
- Create: `backend/src/Service/Search/LikePattern.php`
- Test: `backend/tests/Service/Search/SearchTermsTest.php`
- Test: `backend/tests/Service/Search/LikePatternTest.php`

**Interfaces:**
- Consumes: `App\Exception\ValidationException` (constructor takes `array<string, list<string>>`).
- Produces:
  - `SearchTerms::fromInput(string $input): SearchTerms` with public `list<string> $terms`; constants `MIN_INPUT_LENGTH = 3`, `MAX_INPUT_LENGTH = 100`, `MAX_TERMS = 6`. Throws `ValidationException` keyed `q`.
  - `LikePattern::containing(string $term): string`; constant `ESCAPE_CHARACTER = '!'`.

- [x] **Step 1: Write the failing tests for parsing**

`backend/tests/Service/Search/SearchTermsTest.php` covers: splits on whitespace; drops leading and trailing whitespace; collapses repeated whitespace; rejects input shorter than 3 characters; accepts exactly 3; rejects longer than 100; keeps only the first six terms.

- [x] **Step 2: Run and watch them fail**

Run: `php bin/phpunit tests/Service/Search/SearchTermsTest.php`
Expected: FAIL — `Class "App\Service\Search\SearchTerms" not found`, then assertion failures for each rule.

- [x] **Step 3: Implement `SearchTerms`**

Trim, guard the length in a private `assertLengthIsUsable`, `preg_split('/\s+/', $trimmed)`, `array_slice(..., 0, MAX_TERMS)`.

- [x] **Step 4: Write the failing tests for escaping**

`backend/tests/Service/Search/LikePatternTest.php` covers: wraps a plain term in `%…%`; escapes `%`; escapes `_`; escapes `!` itself; escapes `!` **before** the wildcards, so `!%` becomes `!!!%`.

- [x] **Step 5: Implement `LikePattern`**

One `str_replace` with parallel arrays, `!` first so the second pass cannot re-escape the first pass's output.

- [ ] **Step 6: Verify green and commit**

```bash
php bin/phpunit tests/Service/Search/
```
Expected: `OK (12 tests, 12 assertions)`

```bash
git add backend/src/Service/Search backend/tests/Service/Search
git commit -m "feat(#408): parse search terms and escape LIKE patterns"
```

---

### Task 2: The repository query

**Files:**
- Create: `backend/src/Repository/EntrySearchQuery.php`
- Modify: `backend/src/Repository/EntryRepository.php` (add `searchForUser`, reuse the existing private `rowQueryBuilder` and `applyCursor`)
- Test: `backend/tests/Repository/EntrySearchTest.php` (already written on the branch; re-run it, do not rewrite it)

**Interfaces:**
- Consumes: `SearchTerms`, `LikePattern`, `App\Http\EntryCursor`, `App\Repository\EntryListRow`.
- Produces: `EntryRepository::searchForUser(EntrySearchQuery $query): list<EntryListRow>`, and `EntrySearchQuery` with promoted public properties `int $userId`, `SearchTerms $terms`, `?EntryCursor $cursor = null`, `int $limit = EntryQuery::DEFAULT_LIMIT`.

- [ ] **Step 1: Run the existing failing test**

Run: `php bin/phpunit tests/Repository/EntrySearchTest.php`
Expected: FAIL — `Class "App\Repository\EntrySearchQuery" not found`.

The test asserts: matches the title; matches the summary; ignores ASCII case; requires every term to match; matches terms split across title and summary; skips feeds the user does not subscribe to; returns newest first; pages with the keyset cursor; honours the limit; treats `%` literally; treats `_` literally.

- [ ] **Step 2: Create the query DTO**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;
use App\Service\Search\SearchTerms;

/**
 * The parameter object for a search read. Sits beside EntryQuery because it is
 * the same kind of thing: everything one repository read needs, in one value.
 */
final readonly class EntrySearchQuery
{
    public function __construct(
        public int $userId,
        public SearchTerms $terms,
        public ?EntryCursor $cursor = null,
        public int $limit = EntryQuery::DEFAULT_LIMIT,
    ) {
    }
}
```

- [ ] **Step 3: Add `searchForUser` to `EntryRepository`**

Insert directly after `listForUser`. It reuses `rowQueryBuilder` and `applyCursor`, so the subscription join and the `markedReadUntil` read-state fold exist once.

```php
    /**
     * Entries whose title or summary contains EVERY search term, newest first,
     * keyset-paginated exactly like the entry list. The predicate is an AND of
     * unindexable LIKEs, so the database reads every entry the caller
     * subscribes to; that cost is accepted for now and measured in #408.
     *
     * @return list<EntryListRow>
     */
    public function searchForUser(EntrySearchQuery $query): array
    {
        $limit = max(1, min($query->limit, EntryQuery::MAX_LIMIT));

        $qb = $this->rowQueryBuilder($query->userId)
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit);

        $this->applyTerms($qb, $query->terms);
        $this->applyCursor($qb, $query->cursor);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
    }

    /**
     * A summary is nullable, and NULL LIKE … is never true, so the OR alone
     * handles an entry that carries no summary.
     */
    private function applyTerms(QueryBuilder $qb, SearchTerms $terms): void
    {
        foreach ($terms->terms as $position => $term) {
            $parameter = 'term' . $position;
            $qb->andWhere(\sprintf(
                "(e.title LIKE :%s ESCAPE '%s' OR e.summary LIKE :%s ESCAPE '%s')",
                $parameter,
                LikePattern::ESCAPE_CHARACTER,
                $parameter,
                LikePattern::ESCAPE_CHARACTER,
            ))->setParameter($parameter, LikePattern::containing($term));
        }
    }
```

Add to the file's `use` block: `use App\Service\Search\LikePattern;` and `use App\Service\Search\SearchTerms;`.

- [ ] **Step 4: Run the test and watch it pass**

Run: `php bin/phpunit tests/Repository/EntrySearchTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 5: Run the whole repository suite for regressions**

Run: `php bin/phpunit tests/Repository/`
Expected: PASS, no failures.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Repository backend/tests/Repository/EntrySearchTest.php
git commit -m "feat(#408): search entries by title and summary in the repository"
```

---

### Task 3: The service seam

**Files:**
- Create: `backend/src/Service/Search/EntrySearchInterface.php`
- Create: `backend/src/Service/Search/LikeEntrySearch.php`
- Modify: `backend/config/services.yaml` (add the alias beside the others at line ~82)
- Test: `backend/tests/Service/Search/LikeEntrySearchTest.php`

**Interfaces:**
- Consumes: `EntryRepository::searchForUser`, `EntrySearchQuery`.
- Produces: `EntrySearchInterface::search(EntrySearchQuery $query): list<EntryListRow>`, implemented by `LikeEntrySearch`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\EntrySearchInterface;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * The seam an Elasticsearch implementation would replace. This test covers the
 * BEHAVIOUR only, so it builds the implementation directly. Proving the DI
 * alias belongs to Task 6: a container fetch here would need the alias made
 * public in the test environment, and that override replaces the production
 * entry — so the test would pass with the production alias deleted, which is
 * the one failure it would appear to be guarding.
 */
final class LikeEntrySearchTest extends DbTestCase
{
    public function testFindsASubscribedEntryThroughTheInterface(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'guid',
            'https://example.com/guid',
            'Angular 20 ships',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        $repository = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repository);
        $search = new LikeEntrySearch($repository);

        $rows = $search->search(new EntrySearchQuery(
            userId: $user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
        ));

        self::assertCount(1, $rows);
        self::assertSame('Angular 20 ships', $rows[0]->entry->getTitle());
    }
}
```

Add **nothing** to `backend/config/services_test.yaml`. A test-environment alias would replace the production entry rather than amend it, so the whole suite would keep passing with the production alias deleted — the alias is proven in Task 6 instead, where the controller autowires the interface for real. Autowiring a constructor argument does not need a public service; only `->get()` does, and this task no longer calls it.

- [ ] **Step 2: Run and watch it fail**

Run: `php bin/phpunit tests/Service/Search/LikeEntrySearchTest.php`
Expected: FAIL — `Class "App\Service\Search\EntrySearchInterface" not found`.

- [ ] **Step 3: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;
use App\Repository\EntrySearchQuery;

/**
 * Finds entries for one caller. The single seam behind which the matching lives:
 * swapping LIKE for Elasticsearch or Solr means adding an implementation here
 * and changing one alias, and nothing above this line moves.
 */
interface EntrySearchInterface
{
    /** @return list<EntryListRow> newest first, at most $query->limit rows */
    public function search(EntrySearchQuery $query): array;
}
```

- [ ] **Step 4: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;
use App\Repository\EntryRepository;
use App\Repository\EntrySearchQuery;

/**
 * Matching by an AND of escaped LIKE predicates — the one implementation the
 * reader ships today. It behaves identically on SQLite and MySQL, which is why
 * the native test suite exercises the query that production runs.
 */
final readonly class LikeEntrySearch implements EntrySearchInterface
{
    public function __construct(private EntryRepository $entries)
    {
    }

    /** @return list<EntryListRow> */
    public function search(EntrySearchQuery $query): array
    {
        return $this->entries->searchForUser($query);
    }
}
```

- [ ] **Step 5: Alias the interface**

In `backend/config/services.yaml`, beside the existing aliases (the `ArticleExtractorInterface` line is the model):

```yaml
    App\Service\Search\EntrySearchInterface: '@App\Service\Search\LikeEntrySearch'
```

- [ ] **Step 6: Run and watch it pass**

Run: `php bin/phpunit tests/Service/Search/`
Expected: PASS, 13 tests.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Search backend/tests/Service/Search backend/config/services.yaml
git commit -m "feat(#408): add the entry search seam and wire the LIKE implementation"
```

---

### Task 4: Page assembly, extracted

`EntryController::list()` builds `{entries, nextCursor}` inline. The search endpoint needs the identical rule, and two copies of a keyset-cursor decision is how the two lists drift apart. Extract first, then reuse.

**Files:**
- Create: `backend/src/Http/EntryPage.php`
- Modify: `backend/src/Controller/Api/EntryController.php:98-113`
- Test: `backend/tests/Http/EntryPageTest.php`

**Interfaces:**
- Consumes: `EntryListRow`, `EntryCursor`, `EntryQuery::MAX_LIMIT`.
- Produces: `EntryPage::of(list<EntryListRow> $rows, int $limit): array{entries: list<array<string, mixed>>, nextCursor: string|null}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\EntryPage;
use PHPUnit\Framework\TestCase;

final class EntryPageTest extends TestCase
{
    public function testAShortPageOffersNoNextCursor(): void
    {
        $page = EntryPage::of([], 50);

        self::assertSame([], $page['entries']);
        self::assertNull($page['nextCursor']);
    }
}
```

Add a second test that a full page offers a cursor, building rows with the same `EntryListRow` construction `tests/Repository/EntrySearchTest.php` uses. Keep both tests in this one file.

- [ ] **Step 2: Run and watch it fail**

Run: `php bin/phpunit tests/Http/EntryPageTest.php`
Expected: FAIL — `Class "App\Http\EntryPage" not found`.

- [ ] **Step 3: Write `EntryPage`**

Move the body of the `$last`/`$nextCursor` block out of `EntryController::list()` verbatim, including its comment about a full page implying more, and return `['entries' => array_map(static fn ($r) => EntryJson::one($r), $rows), 'nextCursor' => $nextCursor]`.

- [ ] **Step 4: Rewrite `EntryController::list()`'s tail to call it**

Replace lines 98–113 with `return new JsonResponse(EntryPage::of($rows, $limit));`.

- [ ] **Step 5: Run the new test and the existing controller suite**

```bash
php bin/phpunit tests/Http/EntryPageTest.php tests/Controller/Api/EntryControllerTest.php
```
Expected: PASS. The controller suite proves the extraction changed no behaviour.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Http/EntryPage.php backend/src/Controller/Api/EntryController.php backend/tests/Http/EntryPageTest.php
git commit -m "refactor(#408): extract entry page assembly out of the list action"
```

---

### Task 5: The request factory

The controller must stay thin, so nothing here happens in a controller method: rejecting unknown parameters, parsing `q`, decoding the cursor and clamping the limit all live in one injectable collaborator with its own unit test.

**Files:**
- Create: `backend/src/Service/Search/EntrySearchRequestFactory.php`
- Test: `backend/tests/Service/Search/EntrySearchRequestFactoryTest.php`

**Interfaces:**
- Consumes: `SearchTerms`, `EntryCursor`, `EntryQuery::DEFAULT_LIMIT`, `ValidationException`, `Symfony\Component\HttpFoundation\Request`, `App\Entity\User`.
- Produces: `EntrySearchRequestFactory::fromRequest(Request $request, User $user): EntrySearchQuery`; constant `ALLOWED_PARAMETERS = ['q', 'cursor', 'limit']`.

- [ ] **Step 1: Write the failing tests**

Cover, one behaviour per test: builds a query from `q` alone; rejects an unknown query parameter with `ValidationException`; rejects a malformed cursor; accepts a valid cursor; clamps a limit above `EntryQuery::MAX_LIMIT`; a missing `q` is rejected. Build requests with `Request::create('/api/entries/search?q=angular')`. Build the user with `new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'))` and set its id through the entity's constructor only — if `User::getId()` returns null in a unit test, assert on `terms` and `cursor` instead and leave the id to the functional test in Task 6.

- [ ] **Step 2: Run and watch them fail**

Run: `php bin/phpunit tests/Service/Search/EntrySearchRequestFactoryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the factory**

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Http\EntryCursor;
use App\Repository\EntryQuery;
use App\Repository\EntrySearchQuery;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns one HTTP request into a search query, and refuses anything it does not
 * understand. An unknown parameter is rejected rather than ignored: silently
 * dropping `tag=3` would answer a search the caller did not ask for, and a
 * caller who believes the filter applied has no way to tell.
 */
final readonly class EntrySearchRequestFactory
{
    public const array ALLOWED_PARAMETERS = ['q', 'cursor', 'limit'];

    public function fromRequest(Request $request, User $user): EntrySearchQuery
    {
        $this->assertNoUnknownParameters($request);

        return new EntrySearchQuery(
            userId: (int) $user->getId(),
            terms: SearchTerms::fromInput($request->query->getString('q')),
            cursor: $this->cursor($request->query->getString('cursor')),
            limit: $request->query->getInt('limit', EntryQuery::DEFAULT_LIMIT),
        );
    }

    private function assertNoUnknownParameters(Request $request): void
    {
        $unknown = array_diff(array_keys($request->query->all()), self::ALLOWED_PARAMETERS);
        if ($unknown === []) {
            return;
        }

        throw new ValidationException([
            'query' => array_map(
                static fn (string $name): string => \sprintf('Unknown parameter "%s".', $name),
                array_values($unknown),
            ),
        ]);
    }

    private function cursor(string $raw): ?EntryCursor
    {
        if ($raw === '') {
            return null;
        }

        return EntryCursor::decode($raw)
            ?? throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
    }
}
```

- [ ] **Step 4: Run and watch them pass**

Run: `php bin/phpunit tests/Service/Search/EntrySearchRequestFactoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Search/EntrySearchRequestFactory.php backend/tests/Service/Search/EntrySearchRequestFactoryTest.php
git commit -m "feat(#408): build a search query from the request and reject unknown parameters"
```

---

### Task 6: The endpoint

**Files:**
- Create: `backend/src/Controller/Api/EntrySearchController.php`
- Test: `backend/tests/Controller/Api/EntrySearchControllerTest.php`

**Interfaces:**
- Consumes: `EntrySearchInterface`, `EntrySearchRequestFactory`, `EntryPage`.
- Produces: `GET /api/entries/search` named `api_entries_search`.

Route order note: `EntryController` declares `/api/entries/{id}` with `requirements: ['id' => '\d+']`, so `search` cannot be captured by it. No route reordering is needed.

**This task carries the DI guarantee for Task 3's seam.** The controller autowires `EntrySearchInterface`, and the only thing that resolves it is the alias in `config/services.yaml`. Deleting that line must make the functional tests below fail with a container error. Verify that once by hand: comment the alias out, run one test, see it fail, restore the line. Say so in the report.

- [ ] **Step 1: Write the failing functional test**

Model it on `tests/Controller/Api/EntryControllerTest.php` — copy that file's authentication helper and fixture style rather than inventing one. Cover, one behaviour per test:

1. returns the matching entry with a `200` and an `entries` array;
2. omits an entry in a feed the caller does not subscribe to;
3. answers `422` with problem type `validation_error` for `q=ab`;
4. answers `422` for `?q=angular&tag=3`;
5. answers `422` for a malformed `cursor`;
6. answers `401` without a bearer token;
7. a full page carries a non-null `nextCursor` and the follow-up request with it returns the next rows.

- [ ] **Step 2: Run and watch it fail**

Run: `php bin/phpunit tests/Controller/Api/EntrySearchControllerTest.php`
Expected: FAIL — `404` on the route, because the controller does not exist.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\EntryPage;
use App\Service\Search\EntrySearchInterface;
use App\Service\Search\EntrySearchRequestFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries/search')]
final readonly class EntrySearchController
{
    public function __construct(
        private EntrySearchInterface $search,
        private EntrySearchRequestFactory $requests,
    ) {
    }

    #[Route('', name: 'api_entries_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $query = $this->requests->fromRequest($request, $user);

        return new JsonResponse(EntryPage::of($this->search->search($query), $query->limit));
    }
}
```

- [ ] **Step 4: Run and watch it pass**

Run: `php bin/phpunit tests/Controller/Api/EntrySearchControllerTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Run every backend gate**

```bash
php bin/console cache:warmup && composer check && composer md && php bin/phpunit
```
Expected: all green. Fix any PHPMD finding by changing the design, never the threshold.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Api/EntrySearchController.php backend/tests/Controller/Api/EntrySearchControllerTest.php
git commit -m "feat(#408): add the entry search endpoint"
```

---

### Task 7: The client's second path

**Files:**
- Modify: `frontend/src/app/reader/models.ts:165-169` (`EntryQuery`)
- Modify: `frontend/src/app/reader/reader-api.ts` (`entries`)
- Test: `frontend/src/app/reader/reader-api.spec.ts`

**Interfaces:**
- Produces: `EntryQuery` gains optional `q?: string`. `ReaderApi.entries()` keeps its signature `(query: EntryQuery, cursor?: string | null): Observable<EntriesPage>` and routes internally.

- [ ] **Step 1: Write the failing tests**

In `reader-api.spec.ts`, following the existing `HttpTestingController` pattern in that file: a query with `q` hits `/api/entries/search` and sends `q` and `limit` but neither `view` nor `tag`; a query without `q` still hits `/api/entries`; a cursor is forwarded on the search path.

- [ ] **Step 2: Run and watch them fail**

Run (from `frontend/`): `npx jest src/app/reader/reader-api.spec.ts`
Expected: FAIL — the request goes to `/api/entries`.

- [ ] **Step 3: Implement the routing**

```ts
  entries(query: EntryQuery, cursor?: string | null): Observable<EntriesPage> {
    if (query.q) return this.searchEntries(query.q, cursor);
    let params = new HttpParams().set('view', query.view).set('limit', PAGE_SIZE);
    if (query.subscription != null) params = params.set('subscription', query.subscription);
    if (query.tag != null) params = params.set('tag', query.tag);
    if (cursor) params = params.set('cursor', cursor);
    return this.http.get<EntriesPage>(`${this.base}/api/entries`, { params });
  }

  /** Search carries none of the list's filters — it is its own view over every
   *  subscription — so it never forwards `view`, `tag` or `subscription`. */
  private searchEntries(term: string, cursor?: string | null): Observable<EntriesPage> {
    let params = new HttpParams().set('q', term).set('limit', PAGE_SIZE);
    if (cursor) params = params.set('cursor', cursor);
    return this.http.get<EntriesPage>(`${this.base}/api/entries/search`, { params });
  }
```

Add `q?: string;` to `EntryQuery` in `models.ts` with a one-line comment saying that its presence alone selects the search endpoint.

- [ ] **Step 4: Run and watch them pass**

Run: `npx jest src/app/reader/reader-api.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts
git commit -m "feat(#408): route a query carrying a term to the search endpoint"
```

---

### Task 8: The search selection

**Files:**
- Modify: `frontend/src/app/reader/query.ts` (`Selection`, `sameSelection`, `selectionFromParams`, `queryFromSelection`)
- Modify: `frontend/src/app/reader/list-scroll-memory.ts:8-11` (`scrollKey`)
- Test: `frontend/src/app/reader/query.spec.ts`
- Test: `frontend/src/app/reader/list-scroll-memory.spec.ts`

**Interfaces:**
- Produces: `Selection` gains `'search'` to its `kind` union and an optional `term?: string`. `queryFromSelection({kind:'search', term})` returns `{ view: 'all', q: term }`.

- [ ] **Step 1: Write the failing tests**

In `query.spec.ts`: a `q` parameter of 3+ characters produces `{kind:'search', id:null, unread:false, term:'angular'}`; `q` wins over a `tag` parameter present in the same URL; a `q` of 1–2 characters is ignored and the selection falls back to `all`; an empty `q` is ignored; `queryFromSelection` on a search selection returns `{view:'all', q:'angular'}`; `sameSelection` returns false for two search selections with different terms.

In `list-scroll-memory.spec.ts`: two search selections with different terms produce different `scrollKey` values.

- [ ] **Step 2: Run and watch them fail**

Run: `npx jest src/app/reader/query.spec.ts src/app/reader/list-scroll-memory.spec.ts`
Expected: FAIL — the search kind does not exist.

- [ ] **Step 3: Implement**

In `query.ts`, extend the interface and add the branch **first** in `selectionFromParams`, before the view/subscription/tag branches:

```ts
export interface Selection {
  kind: 'all' | 'tag' | 'subscription' | 'favorites' | 'kept' | 'for-you' | 'search';
  id: number | null;
  unread: boolean;
  /** Only a search carries one. Part of the list's identity, so it belongs to
   *  the selection rather than to a service beside it. */
  term?: string;
}

/** The shortest term the backend will accept. A shorter one is not an error
 *  here — it is a half-typed word, so the URL simply carries no search yet. */
export const MIN_SEARCH_LENGTH = 3;
```

In `selectionFromParams`, before the existing `if`:

```ts
  const term = (p.get('q') ?? '').trim();
  if (term.length >= MIN_SEARCH_LENGTH) {
    // A search is its own view over every subscription, so a tag or feed
    // parameter left in the URL by hand is ignored rather than combined.
    return { selection: { kind: 'search', id: null, unread: false, term }, entryId };
  }
```

Extend `sameSelection` with `&& a.term === b.term`. Add the `case 'search':` arm to `queryFromSelection` returning `{ view: 'all', q: s.term }`. `markReadTarget` and `canScopedRefresh` need no change — their `default` and their explicit list already exclude a new kind; add a search case to `isSingleStreamView` only if a test proves it needed.

In `list-scroll-memory.ts`, append the term to the key:

```ts
export function scrollKey(s: Selection): string {
  return `feed-reader:list-scroll:${s.kind}:${s.id ?? ''}:${s.unread ? 'u' : 'a'}:${s.term ?? ''}`;
}
```

- [ ] **Step 4: Run and watch them pass, then the whole reader suite**

```bash
npx jest src/app/reader
```
Expected: PASS. If `sameSelection` or `scrollKey` broke another spec, fix the production code, not the spec.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/query.ts frontend/src/app/reader/query.spec.ts frontend/src/app/reader/list-scroll-memory.ts frontend/src/app/reader/list-scroll-memory.spec.ts
git commit -m "feat(#408): add the search selection and give each term its own scroll memory"
```

---

### Task 9: Marked terms

**Files:**
- Create: `frontend/src/app/reader/search-marks.ts`
- Create: `frontend/src/app/shared/marked-text/marked-text.component.ts`
- Create: `frontend/src/app/shared/marked-text/marked-text.component.html`
- Create: `frontend/src/app/shared/marked-text/marked-text.component.scss`
- Modify: `frontend/src/app/reader/entry-row/entry-row.component.html:13` and `:17`
- Modify: `frontend/src/app/reader/entry-row/entry-row.component.ts` (add a `terms` input)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html` (pass `selection().term` down to each `app-entry-row`)
- Test: `frontend/src/app/reader/search-marks.spec.ts`
- Test: `frontend/src/app/shared/marked-text/marked-text.component.spec.ts`

**Interfaces:**
- Produces: `markTerms(text: string, terms: string[]): TextSegment[]` where `TextSegment = { text: string; marked: boolean }`; `<app-marked-text [text]="…" [terms]="…" />`.

- [ ] **Step 1: Write the failing tests for the segment function**

Cover: no terms returns one unmarked segment holding the whole text; a matching term splits into three segments with the middle one marked; matching is case-insensitive and the **original** casing is preserved in the output; two different terms both mark; a term appearing twice marks both; a regular-expression metacharacter in a term (`c++`) matches literally; an empty term list and an empty text are both safe.

- [ ] **Step 2: Run and watch them fail**

Run: `npx jest src/app/reader/search-marks.spec.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement `search-marks.ts`**

```ts
// src/app/reader/search-marks.ts

export interface TextSegment {
  text: string;
  marked: boolean;
}

/** Escapes the characters that would otherwise make a search term act as a
 *  pattern — a user searching "c++" means those plus signs literally. */
function escapeForRegExp(term: string): string {
  return term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Cuts `text` into marked and unmarked pieces. The caller renders the pieces as
 * elements, so nothing here is ever interpolated as HTML — a title containing
 * markup stays text.
 */
export function markTerms(text: string, terms: string[]): TextSegment[] {
  const usable = terms.filter((t) => t.length > 0);
  if (!text || usable.length === 0) return [{ text, marked: false }];

  // split() on a capturing group returns the matches interleaved with the gaps,
  // and every match is one of the terms — so a set lookup decides a piece
  // without a second regular expression, and without the lastIndex state a
  // reused /g pattern would carry between calls.
  const pattern = new RegExp(`(${usable.map(escapeForRegExp).join('|')})`, 'gi');
  const lowered = new Set(usable.map((term) => term.toLowerCase()));

  return text
    .split(pattern)
    .filter((piece) => piece.length > 0)
    .map((piece) => ({ text: piece, marked: lowered.has(piece.toLowerCase()) }));
}
```

- [ ] **Step 4: Write the failing component test, then the component**

`marked-text.component.spec.ts` asserts that the rendered host contains a `<mark>` element whose text is the matched term, and that a term of `<script>` renders as text with no `script` element in the DOM.

The component:

```ts
@Component({
  selector: 'app-marked-text',
  imports: [],
  templateUrl: './marked-text.component.html',
  styleUrl: './marked-text.component.scss',
})
export class MarkedTextComponent {
  readonly text = input('');
  readonly terms = input<string[]>([]);
  readonly segments = computed(() => markTerms(this.text(), this.terms()));
}
```

The template — no `innerHTML`, which is the whole point:

```html
@for (segment of segments(); track $index) {
  @if (segment.marked) {
    <mark>{{ segment.text }}</mark>
  } @else {
    {{ segment.text }}
  }
}
```

The stylesheet uses design tokens only, no hex:

```scss
mark {
  background: var(--accent-soft);
  color: inherit;
  border-radius: var(--radius-xs);
  padding: 0 var(--space-3xs);
}
```

Before writing this file, open `docs/design-language.md` and `src/app/theme/` and use the token names that actually exist. Invented token names fail `npm run check` at Stylelint or render nothing.

- [ ] **Step 5: Wire it into the row**

In `entry-row.component.ts` add `readonly terms = input<string[]>([]);`. In the template replace `{{ entry().title }}` with `<app-marked-text [text]="entry().title" [terms]="terms()" />` and `{{ snippet() }}` with the same over `snippet()`. In `entry-list.component.html`, bind `[terms]="searchTerms()"` on every `app-entry-row`, with a computed in the list component: `readonly searchTerms = computed(() => this.selection().term?.split(/\s+/) ?? []);`

- [ ] **Step 6: Force the list layout for a search**

A search result set never renders as a magazine. The magazine layout groups and
dramatizes across eight block components; a result set wants one uniform,
scannable row, and marking terms in eight more templates buys nothing. This is
also what keeps the marks in one place.

Write the failing test first, in `entry-list.component.spec.ts`: with
`layout` set to `'magazine'` **and** a search selection, the rendered rows are
`app-entry-row` elements and no magazine block is present; with the same layout
and a non-search selection, the magazine blocks render as before.

Then, in `entry-list.component.ts`, stop reading the `layout` input directly in
the template and read a computed instead:

```ts
  /** A search never renders as a magazine — its rows carry marked terms, and a
   *  spread would scatter them across eight block templates. */
  readonly effectiveLayout = computed(() =>
    this.selection().kind === 'search' ? 'list' : this.layout(),
  );
```

Replace every `layout()` reference in `entry-list.component.html` with
`effectiveLayout()`.

- [ ] **Step 7: Run the reader and shared suites**

```bash
npx jest src/app/reader src/app/shared
```
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/search-marks.ts frontend/src/app/reader/search-marks.spec.ts frontend/src/app/shared/marked-text frontend/src/app/reader/entry-row frontend/src/app/reader/entry-list
git commit -m "feat(#408): mark the matched terms in a search result row"
```

---

### Task 10: Strings, list header and empty state

**Files:**
- Modify: `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (the `title` computed at :247)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html:112-118` (the empty state)
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Produces: the `reader.search*` key family, used by Tasks 11 and 12 as well.

Add to the `reader` object of **both** locale files:

| Key | en | de |
|---|---|---|
| `search` | `Search` | `Suchen` |
| `searchPlaceholder` | `Search articles` | `Artikel suchen` |
| `searchResults` | `Results for "{{term}}"` | `Ergebnisse für „{{term}}“` |
| `searchTooShort` | `Type at least 3 characters.` | `Geben Sie mindestens 3 Zeichen ein.` |
| `searchNoResults` | `Nothing matches "{{term}}".` | `Nichts passt zu „{{term}}“.` |
| `searchClear` | `Clear search` | `Suche löschen` |
| `searchClose` | `Close search` | `Suche schließen` |
| `searchCount` | `{{count}} results` | `{{count}} Ergebnisse` |

- [ ] **Step 1: Write the failing tests**

Shell spec: with a search selection whose term is `angular`, `title()` resolves through Transloco to the `reader.searchResults` string carrying the term. List spec: with a search selection, no entries and `loading` false, the empty paragraph shows the `reader.searchNoResults` text and **not** `reader.nothingHere`; and the "browse the catalog" link is absent, because a search that found nothing is not an empty account.

- [ ] **Step 2: Run and watch them fail**

Run: `npx jest src/app/reader/reader-shell.component.spec.ts src/app/reader/entry-list/entry-list.component.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Add the keys to both locale files**

- [ ] **Step 4: Add the title branch and the empty-state branch**

In the shell's `title` computed, add a `search` arm returning the translated `reader.searchResults` with `{ term }`. In the list template, wrap the existing empty paragraph so a search selection renders `reader.searchNoResults` with the term and skips the catalog link.

- [ ] **Step 5: Run and watch them pass**

Run: `npx jest src/app/reader`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/public/i18n frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.spec.ts frontend/src/app/reader/entry-list
git commit -m "feat(#408): title and empty state for the search view"
```

---

### Task 11: The desktop field

**Files:**
- Create: `frontend/src/app/reader/search-field/search-field.component.ts`
- Create: `frontend/src/app/reader/search-field/search-field.component.html`
- Create: `frontend/src/app/reader/search-field/search-field.component.scss`
- Create: `frontend/src/app/reader/search-field/search-field.component.spec.ts`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html:1-2` (above the `.actions` block, inside the same `@if (!organising())`)
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts` (import the component)

**Interfaces:**
- Produces: `<app-search-field [term]="…" (search)="…" />`, emitting the debounced, trimmed term (or the empty string when cleared). The component owns the debounce; no parent repeats it.

The component holds the input, the clear button, the 300 ms debounce, the 3-character floor, `role="search"` with `[attr.aria-label]="'reader.search' | transloco"`, and the `Escape` behaviour. It emits; navigation is the caller's job.

- [ ] **Step 1: Write the failing component tests**

Cover: typing emits nothing before the debounce elapses and emits the trimmed term after it (drive time with `fakeAsync` and `tick(300)`); typing two characters emits nothing at all; typing two characters renders the `reader.searchTooShort` hint, and the hint disappears at three; clearing the field emits the empty string immediately, with no debounce, because leaving a search should not lag; the clear button carries the `reader.searchClear` label; `Escape` on a non-empty field clears it; the wrapper carries `role="search"`.

- [ ] **Step 2: Run and watch them fail**

Run: `npx jest src/app/reader/search-field`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the component**

Use a `signal` for the raw text and an RxJS `Subject` piped through `debounceTime(300)` and `distinctUntilChanged()`, subscribed with `takeUntilDestroyed()` — the pattern `sidebar.component.ts` already uses for its own subscriptions. Emit `''` on clear without passing it through the debounce.

- [ ] **Step 4: Mount it in the sidebar and handle the event in the shell**

In `sidebar.component.html`, inside the existing `@if (!organising())` and above `<div class="actions">`:

```html
    @if (!screen.isNarrow()) {
      <app-search-field [term]="selection().term ?? ''" (search)="search.emit($event)" />
    }
```

`screen` is already injected in the sidebar component as `LayoutService`. Add `readonly search = output<string>();` to the sidebar, and in `reader-shell.component.html` bind `(search)="onSearch($event)"` on `<app-sidebar>`. In the shell:

```ts
  /** A term navigates; an empty term returns to All items. Both go through the
   *  URL, so Back leaves a search the same way it leaves any other list. */
  onSearch(term: string): void {
    void this.router.navigate([], {
      queryParams: { q: term || null, view: null, tag: null, subscription: null, entry: null },
      queryParamsHandling: 'merge',
    });
  }
```

- [ ] **Step 5: Run the reader suite**

Run: `npx jest src/app/reader`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/search-field frontend/src/app/reader/sidebar frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.html
git commit -m "feat(#408): add the desktop sidebar search field"
```

---

### Task 12: The mobile bar

**Files:**
- Modify: `frontend/src/app/reader/header/reader-header.component.html` (icon in `.right`, and the covering bar)
- Modify: `frontend/src/app/reader/header/reader-header.component.ts` (open state, output)
- Modify: `frontend/src/app/reader/header/reader-header.component.scss`
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (force-show while open)
- Test: `frontend/src/app/reader/header/reader-header.component.spec.ts`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`

**Interfaces:**
- Consumes: `<app-search-field>` from Task 11, `DismissOnOutsideDirective` (already imported by the header), `headerHiddenAtRest` from `header-scroll.ts`.
- Produces: the header's `searchOpen` signal and its `search` output, forwarded to the same `onSearch` the sidebar uses.

- [ ] **Step 1: Write the failing tests**

Header spec: the search button is present and carries the `reader.search` label; clicking it hides the brand link and the account button and shows the field; the tag row stays rendered while search is open; the close control restores the brand; a click outside closes it (drive `DismissOnOutsideDirective` the way the existing account-menu test in this file does); `Escape` on an already-empty field closes the bar.

Shell spec: while the header reports search open, `headerHidden()` is false even after a scroll event that would otherwise hide it; after it closes, `headerHidden()` returns to `headerHiddenAtRest(top)` for the current offset.

- [ ] **Step 2: Run and watch them fail**

Run: `npx jest src/app/reader/header src/app/reader/reader-shell.component.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Implement the header**

Add `readonly searchOpen = signal(false);` and `readonly search = output<string>();`. Wrap the existing `.left` and `.right` blocks in `@if (!searchOpen())`, and add the covering bar as the sibling `@else` branch holding `<app-search-field>` plus a close button labelled `reader.searchClose`. Put the trigger button in `.right`, before the account block, rendered only when `screen.isNarrow()`. Leave the `<nav class="tagrow">` block outside the condition so it stays visible.

Move focus into the field when the bar opens and back to the trigger when it closes, with an `effect()` and a `viewChild` — the same shape the reader view already uses for its own focus handling.

- [ ] **Step 4: Force-show the header while the bar is open**

In `reader-shell.component.ts`, the header's hidden state must OR in the search state exactly as it already does for the open drawer. Find the existing `headerHidden` computed and the place `setSidebarOpen` force-shows the header, and add the search-open condition beside it. On close, call the same code path the drawer close uses, which already resolves to `headerHiddenAtRest`.

- [ ] **Step 5: Run and watch them pass**

Run: `npx jest src/app/reader`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/header frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.spec.ts
git commit -m "feat(#408): add the mobile header search bar"
```

---

### Task 13: The keyboard shortcut and the live region

**Files:**
- Modify: `frontend/src/app/reader/search-field/search-field.component.ts` (host listener for `/`)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html` (live region)
- Test: both components' specs

- [ ] **Step 1: Write the failing tests**

Field spec: pressing `/` while the focus is on the document body focuses the input; pressing `/` while the focus is already inside a text input does **not** steal it, and the character is typed normally.

List spec: with a search selection and three entries, a `[aria-live="polite"]` element renders the `reader.searchCount` string for 3; with no search selection, no live region is rendered.

- [ ] **Step 2: Run and watch them fail**

Run: `npx jest src/app/reader/search-field src/app/reader/entry-list`
Expected: FAIL.

- [ ] **Step 3: Implement**

The shortcut belongs to the field component as a `@HostListener('document:keydown', ['$event'])`, guarded by a check that the event target is not an `input`, `textarea` or `contenteditable` element, and by `event.key === '/'` with no modifier held.

The live region is a visually hidden `<p aria-live="polite" role="status">` inside the list, rendered only for a search selection, holding the translated count.

- [ ] **Step 4: Run and watch them pass, then the full gate**

```bash
npm run check
```
Expected: ESLint, Prettier, Stylelint and Jest all green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/search-field frontend/src/app/reader/entry-list
git commit -m "feat(#408): focus the search field with / and announce the result count"
```

---

### Task 14: Measurement, docs and the pull request

- [ ] **Step 1: Measure the query cost on the live database**

Per the promise in the issue, and per the standing rule that a one-off production probe is a throwaway script, not a shipped command. Over SSH to `strato-feedreader`, run a PHP script that counts the caller's entries and times one search, then delete the script. Record: total entries for the account, and the wall-clock time of a single-term and a three-term search. Put both numbers in the PR body.

- [ ] **Step 2: Run the architecture checklist**

Read `docs/architecture.md` §6 and answer each item for `GET /api/entries/search`. Paste the answers into the PR body. Expected result: it passes — bearer auth, stateless, JSON in and `application/problem+json` out, no browser-only input.

- [ ] **Step 3: Document the surface**

Add the search field and the mobile search bar to `docs/design-language.md`, in the shared component catalog section, next to the existing entries.

- [ ] **Step 4: Run every gate one last time, on both database legs**

```bash
cd backend && composer check && composer md && php bin/phpunit && composer infection:diff
```
```bash
docker compose up -d && docker compose exec php vendor/bin/phpunit
```
```bash
cd frontend && npm run check
```

- [ ] **Step 5: Scan the log**

Read `backend/var/log/dev.log` for deprecations and swallowed errors from the new code.

- [ ] **Step 6: Open the pull request**

```bash
git push -u origin feature/408-entry-search
```

PR into `develop`, body containing `Closes #408`, the two measurements, and the §6 checklist answers. Do not merge and do not tag a deploy.

---

## Decided, and why it is worth knowing

**A search never renders as a magazine** (Task 9, Step 6). The magazine layout
draws titles in eight separate block components — `entry-hero`, `entry-kicker`,
`entry-kicker-line`, `entry-thumb`, `entry-compact`, `entry-quote`,
`entry-split`, `entry-wide`. Forcing the list layout keeps the marked terms in
one component instead of nine, and a uniform row is the better way to scan a
result set. If a later change makes the magazine blocks share one title
component, this condition can go.
