# Saved Searches Combined View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clicking "Saved searches" in the sidebar opens one combined, deduplicated list of every saved search's matches, styled like All items, with "Mark all read" and the "All posts / only unread" switch.

**Architecture:** A new selection kind `saved-searches` (URL `?view=saved-searches`, plus `unread=1`) reaches two new backend endpoints. The read is ONE query whose WHERE ORs each saved search's own term predicate, so the existing keyset cursor and sort work unchanged. Mark-read collects the matching unread ids in one query and hands them to the existing `BulkEntryReadMarker`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM (backend, `backend/`), Angular 20 standalone components + signals (frontend, `frontend/`), PHPUnit, Jest, Playwright.

**Spec:** `docs/superpowers/plans/2026-09-01-saved-searches-combined-view-spec.md` (the body of issue #769)

## Global Constraints

- Branch: `feature/769-saved-searches-combined-view` (already created off `develop`). Commits use `type(#769): summary`.
- PHP: `declare(strict_types=1)` in every file, PSR-12, PHPStan level max, `final readonly class` with constructor promotion as the house style, no boolean flag parameters, guard clauses over nesting.
- Every `src` file you touch must be PHPMD-clean (`composer md`), not merely free of NEW findings.
- Controllers carry no private method that does work (`ThinControllerRule`). Building a value object inline in the action is allowed — `EntryController::list` already does it.
- Comments: one line, three at the absolute most, and only for the *why*. Delete any comment that restates the next line.
- Frontend: no hex colours and no ad-hoc `px` outside `src/app/theme/`; component styles live in the sibling `.scss`.
- The combined view does **not** hide feeds excluded from All items (`includeInAllItems` is a list-read filter only) and does **not** mark search terms in the rows.
- Every new user-visible string needs both `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json`.
- Backend tests run natively on SQLite: use ASCII-only search terms in assertions (MySQL folds accents, SQLite does not).

---

## File Structure

**Backend — create**

| File | Responsibility |
|---|---|
| `backend/src/Repository/SavedSearchEntryQuery.php` | Parameter object for the combined read: user, the terms of each saved search, the unread flag, cursor, limit. |
| `backend/src/Service/Search/SavedSearchTerms.php` | Turns a `SavedSearch` entity (term + two mode columns) into `SearchTerms`; one mapping, used by the combined read and by the badge scan. |
| `backend/src/Service/Reader/SavedSearchMarkReadService.php` | Marks read every unread entry matching any saved search up to the watermark. |
| `backend/src/Controller/Api/SavedSearchEntriesController.php` | `GET /api/entries/saved-searches` and `POST /api/entries/saved-searches/mark-read`. |
| `backend/src/Dto/Entry/MarkSavedSearchesReadRequest.php` | The `{ until }` body. |

**Backend — modify**

| File | Change |
|---|---|
| `backend/src/Repository/EntryListRepository.php` | `applyTerms` split into a predicate builder with a parameter prefix; two new reads: `listForSavedSearches`, `unreadMatchIdsForSavedSearches`. |
| `backend/src/Service/Search/SavedSearchMatchIds.php` | Uses `SavedSearchTerms::of()` instead of its own mapping. |

**Frontend — modify**

| File | Change |
|---|---|
| `frontend/src/app/reader/models.ts` | `EntryView` gains `'saved-searches'`. |
| `frontend/src/app/reader/query.ts` | New `Selection` kind, URL round trip, `hasUnreadFilter`, `queryFromSelection`, `MarkReadTarget`. |
| `frontend/src/app/reader/reader-api.ts` | The new list call and `markSavedSearchesRead`. |
| `frontend/src/app/reader/reader-shell.component.ts/.html` | Title, title count, mark-read branch, the new entry-list input. |
| `frontend/src/app/reader/entry-list/entry-list.component.ts/.html` | Heading icon, the "no saved searches yet" empty state. |
| `frontend/src/app/reader/sidebar/sidebar.component.html/.scss/.ts` | The label becomes a nav link; the chevron keeps the toggle. |
| `frontend/public/i18n/{en,de}.json` | One new key. |

---

### Task 1: The combined repository read

**Files:**
- Create: `backend/src/Repository/SavedSearchEntryQuery.php`
- Modify: `backend/src/Repository/EntryListRepository.php`
- Test: `backend/tests/Repository/SavedSearchEntryListTest.php`

**Interfaces:**
- Consumes: `App\Service\Search\SearchTerms::fromTermAndMode()`, `App\Http\EntryCursor`, `App\Repository\EntryQuery::DEFAULT_LIMIT` / `clampLimit()`, `App\Repository\EntryListRow`.
- Produces:
  - `new SavedSearchEntryQuery(int $userId, list<SearchTerms> $termsPerSearch, bool $onlyUnread = false, ?EntryCursor $cursor = null, int $limit = EntryQuery::DEFAULT_LIMIT)` with a public readonly `int $limit` (clamped).
  - `EntryListRepository::listForSavedSearches(SavedSearchEntryQuery $query): list<EntryListRow>`
  - `EntryListRepository::unreadMatchIdsForSavedSearches(SavedSearchEntryQuery $query, \DateTimeImmutable $until): list<int>`

- [ ] **Step 1: Refactor `applyTerms` into a predicate builder (no behaviour change)**

In `backend/src/Repository/EntryListRepository.php`, replace `applyTerms`, `applySubstringTerm` and `applyWholeWordTerm` with these. Keep `wholeWordColumnPredicate` exactly as it is.

```php
    /**
     * The mode is decided once for the whole query (SearchTerms::$isWholeWord),
     * not per term — every term takes the same path.
     */
    private function applyTerms(QueryBuilder $qb, SearchTerms $terms): void
    {
        $qb->andWhere($this->termsPredicate($qb, $terms, 'term'));
    }

    /**
     * One search's terms as a single ANDed expression the caller places itself.
     * The combined saved-search read ORs several of these, which andWhere()
     * cannot express. $prefix keys the bound parameters, so two searches that
     * share a word cannot overwrite each other's value.
     */
    private function termsPredicate(QueryBuilder $qb, SearchTerms $terms, string $prefix): string
    {
        $predicates = [];
        foreach ($terms->terms as $position => $term) {
            $parameter = $prefix . $position;
            $predicates[] = $terms->isWholeWord
                ? $this->wholeWordPredicate($qb, $parameter, $term)
                : $this->substringPredicate($qb, $parameter, $term);
        }

        return '(' . implode(' AND ', $predicates) . ')';
    }

    /**
     * A summary is nullable, and NULL LIKE … is never true, so the OR alone
     * handles an entry that carries no summary.
     */
    private function substringPredicate(QueryBuilder $qb, string $parameter, string $term): string
    {
        $qb->setParameter($parameter, LikePattern::containing($term));

        return \sprintf(
            "(e.title LIKE :%s ESCAPE '%s' OR e.summary LIKE :%s ESCAPE '%s')",
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
        );
    }

    /**
     * The plain "LIKE %term%" is ANDed in front of the normalized whole-word
     * check on purpose: it rejects almost every row with a cheap scan before
     * the expensive REPLACE chain runs, and costs nothing extra on the rows
     * where it does match.
     *
     * It is sound only while the raw term is a substring of every row the
     * normalized check would accept — true for a term of letters and digits,
     * FALSE as soon as the term carries boundary punctuation, because the two
     * sides then differ in exactly that punctuation. "E-Mail" and "E–Mail"
     * (en dash) normalize alike and must both match, yet neither is a raw
     * substring of the other. Such a term skips the prefilter and pays for the
     * chain; it is the rare shape, and a wrong answer is not worth the scan.
     */
    private function wholeWordPredicate(QueryBuilder $qb, string $parameter, string $term): string
    {
        $word = $parameter . 'Word';
        $cheap = WordBoundaries::areIn($term) ? null : $parameter . 'Cheap';

        $qb->setParameter($word, LikePattern::wholeWord($term));
        if ($cheap !== null) {
            $qb->setParameter($cheap, LikePattern::containing($term));
        }

        return \sprintf(
            '(%s OR %s)',
            $this->wholeWordColumnPredicate('title', $cheap, $word),
            $this->wholeWordColumnPredicate('summary', $cheap, $word),
        );
    }
```

- [ ] **Step 2: Prove the refactor changed nothing**

Run: `cd backend && php bin/phpunit tests/Repository/EntrySearchTest.php tests/Repository/EntryUnreadMatchIdsTest.php tests/Repository/UnreadMatchingEntryIdsForUserTest.php`
Expected: PASS, same counts as before the edit.

- [ ] **Step 3: Commit the refactor on its own**

```bash
git add backend/src/Repository/EntryListRepository.php
git commit -m "refactor(#769): build a search's term predicate instead of applying it"
```

- [ ] **Step 4: Write the failing test**

Create `backend/tests/Repository/SavedSearchEntryListTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\EntryListRepository;
use App\Repository\SavedSearchEntryQuery;
use App\Service\Search\SearchMode;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * The combined saved-search list: every saved search's matches in one stream.
 * ASCII terms only — the suite runs on SQLite, whose LIKE folds ASCII case
 * alone.
 */
final class SavedSearchEntryListTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->em->flush();
    }

    public function testListsMatchesOfEverySavedSearch(): void
    {
        $climate = $this->entry('a', 'Climate report', effectiveDate: '2026-07-10T00:00:00Z');
        $rocket = $this->entry('b', 'Rocket launch', effectiveDate: '2026-07-09T00:00:00Z');
        $this->entry('c', 'Nothing to see', effectiveDate: '2026-07-08T00:00:00Z');

        $rows = $this->repo()->listForSavedSearches($this->query(['climate', 'rocket']));

        self::assertSame(
            [$climate->getId(), $rocket->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $rows),
        );
    }

    public function testAnEntryMatchingTwoSavedSearchesIsListedOnce(): void
    {
        $both = $this->entry('a', 'Climate rocket', effectiveDate: '2026-07-10T00:00:00Z');

        $rows = $this->repo()->listForSavedSearches($this->query(['climate', 'rocket']));

        self::assertCount(1, $rows);
        self::assertSame($both->getId(), $rows[0]->entry->getId());
    }

    public function testNoSavedSearchesListsNothingRatherThanEverything(): void
    {
        $this->entry('a', 'Climate report');

        self::assertSame([], $this->repo()->listForSavedSearches($this->query([])));
    }

    public function testTheCursorWalksTheWholeStreamAcrossAPageBoundary(): void
    {
        $first = $this->entry('a', 'Climate one', effectiveDate: '2026-07-10T00:00:00Z');
        $second = $this->entry('b', 'Rocket two', effectiveDate: '2026-07-09T00:00:00Z');
        $third = $this->entry('c', 'Climate three', effectiveDate: '2026-07-08T00:00:00Z');

        $page = $this->repo()->listForSavedSearches($this->query(['climate', 'rocket'], limit: 2));
        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $page),
        );

        $cursor = new EntryCursor($second->getEffectiveDate(), (int) $second->getId());
        $next = $this->repo()->listForSavedSearches(
            $this->query(['climate', 'rocket'], limit: 2, cursor: $cursor),
        );

        self::assertSame(
            [$third->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $next),
        );
    }

    public function testOnlyUnreadDropsAReadEntry(): void
    {
        $read = $this->entry('a', 'Climate read', effectiveDate: '2026-07-10T00:00:00Z');
        $unread = $this->entry('b', 'Climate unread', effectiveDate: '2026-07-09T00:00:00Z');
        $this->hide($read);

        $rows = $this->repo()->listForSavedSearches($this->query(['climate'], onlyUnread: true));

        self::assertSame(
            [$unread->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $rows),
        );
    }

    public function testUnreadMatchIdsStopAtTheWatermark(): void
    {
        $old = $this->entry('a', 'Climate old', effectiveDate: '2026-07-08T00:00:00Z');
        $newer = $this->entry('b', 'Climate new', effectiveDate: '2026-07-11T00:00:00Z');

        $ids = $this->repo()->unreadMatchIdsForSavedSearches(
            $this->query(['climate']),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        self::assertSame([$old->getId()], $ids);
        self::assertNotContains($newer->getId(), $ids);
    }

    /** @param list<string> $terms */
    private function query(
        array $terms,
        bool $onlyUnread = false,
        int $limit = 50,
        ?EntryCursor $cursor = null,
    ): SavedSearchEntryQuery {
        return new SavedSearchEntryQuery(
            (int) $this->user->getId(),
            array_map(
                static fn (string $term): SearchTerms => SearchTerms::fromTermAndMode(
                    $term,
                    SearchMode::Substring,
                ),
                $terms,
            ),
            $onlyUnread,
            $cursor,
            $limit,
        );
    }

    private function hide(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();
    }

    private function entry(
        string $guid,
        string $title,
        ?string $summary = null,
        string $effectiveDate = '2026-07-10T00:00:00Z',
    ): Entry {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $entry->setSummary($summary);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function repo(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }
}
```

Before running it, open `backend/tests/Repository/EntrySearchTest.php` and `backend/src/Entity/EntryState.php` and align the helper calls above with the real constructors and setters if they differ (e.g. `EntryState`'s constructor arguments, `setIsHidden` versus `setHidden`). Do not change the assertions.

- [ ] **Step 5: Run the test to watch it fail**

Run: `cd backend && php bin/phpunit tests/Repository/SavedSearchEntryListTest.php`
Expected: FAIL — `Class "App\Repository\SavedSearchEntryQuery" not found`.

- [ ] **Step 6: Write the query object**

Create `backend/src/Repository/SavedSearchEntryQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;
use App\Service\Search\SearchTerms;

/**
 * The parameter object for the combined saved-search read: every saved search
 * the caller keeps, already parsed into terms. Sits beside EntrySearchQuery
 * because it is the same kind of thing — everything one repository read needs,
 * in one value — and differs in exactly one way: many searches, ORed, rather
 * than one.
 */
final readonly class SavedSearchEntryQuery
{
    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    /**
     * @param list<SearchTerms> $termsPerSearch
     * @param int               $limit          the size the client asked for
     */
    public function __construct(
        public int $userId,
        public array $termsPerSearch,
        public bool $onlyUnread = false,
        public ?EntryCursor $cursor = null,
        int $limit = EntryQuery::DEFAULT_LIMIT,
    ) {
        $this->limit = EntryQuery::clampLimit($limit);
    }
}
```

- [ ] **Step 7: Write the two reads**

In `backend/src/Repository/EntryListRepository.php`, add these public methods after `searchForUser`:

```php
    /**
     * Every entry matching ANY of the caller's saved searches, newest first and
     * keyset-paginated exactly like the entry list. One query, so the cursor
     * and the sort are the list's own; collecting ids per search and merging
     * them could not page. No join here multiplies a row, so an entry that
     * matches several searches is returned once without a DISTINCT.
     *
     * Deliberately no `includeInAllItems` filter: a search ignores that flag,
     * and this view is built from searches (#769).
     *
     * @return list<EntryListRow>
     */
    public function listForSavedSearches(SavedSearchEntryQuery $query): array
    {
        // An empty predicate list would OR to nothing and match every entry.
        if ($query->termsPerSearch === []) {
            return [];
        }

        $qb = $this->newestFirst($this->rowQueryBuilder($query->userId))
            ->setMaxResults($query->limit);
        $qb->andWhere($this->anySearchMatches($qb, $query->termsPerSearch));

        if ($query->onlyUnread) {
            $qb->andWhere(UnreadDql::predicate())->setParameter('notHidden', false, Types::BOOLEAN);
        }

        $this->applyCursor($qb, $query->cursor, EntryListSort::PublishedDate);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
    }

    /**
     * The ids of every unread entry no newer than $until that matches any saved
     * search — the set the combined mark-read flips. Matched through the same
     * predicate the list uses, so it marks exactly what it shows.
     *
     * @return list<int>
     */
    public function unreadMatchIdsForSavedSearches(
        SavedSearchEntryQuery $query,
        \DateTimeImmutable $until,
    ): array {
        if ($query->termsPerSearch === []) {
            return [];
        }

        $qb = $this->unreadEntriesQueryBuilder($query->userId);

        return $this->scalarIds(
            $qb->select('e.id')
                ->distinct()
                ->andWhere($this->anySearchMatches($qb, $query->termsPerSearch))
                ->andWhere('e.effectiveDate <= :until')
                ->setParameter('until', $until),
        );
    }
```

Add the private predicate helper next to `termsPredicate`:

```php
    /**
     * One predicate for "matches any of these searches" — each search's own
     * terms still ANDed inside it, the searches ORed between them.
     *
     * @param list<SearchTerms> $termsPerSearch
     */
    private function anySearchMatches(QueryBuilder $qb, array $termsPerSearch): string
    {
        $predicates = [];
        foreach ($termsPerSearch as $position => $terms) {
            $predicates[] = $this->termsPredicate($qb, $terms, 'saved' . $position . 'term');
        }

        return '(' . implode(' OR ', $predicates) . ')';
    }
```

Split the unread builder so both readers share the joins — replace `unreadMatchQueryBuilder` with:

```php
    private function unreadMatchQueryBuilder(EntrySearchQuery $query): QueryBuilder
    {
        $qb = $this->unreadEntriesQueryBuilder($query->userId);
        $this->applyTerms($qb, $query->terms);

        return $qb;
    }

    /**
     * The caller's unread entries, left for the reader to narrow and project.
     * Deliberately not rowQueryBuilder: every caller reduces to a scalar, and
     * that builder joins `feed` to select a title and a url nobody reads here.
     */
    private function unreadEntriesQueryBuilder(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->setParameter('user', $userId)
            ->andWhere(UnreadDql::predicate())
            ->setParameter('notHidden', false, Types::BOOLEAN);
    }
```

Move the "not rowQueryBuilder" rationale from the old docblock into `unreadEntriesQueryBuilder` as shown, and leave `unreadMatchQueryBuilder` without one.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Repository/SavedSearchEntryListTest.php tests/Repository/EntrySearchTest.php tests/Repository/EntryUnreadMatchIdsTest.php tests/Repository/UnreadMatchingEntryIdsForUserTest.php`
Expected: PASS.

- [ ] **Step 9: Gates on the touched files**

Run: `cd backend && composer cs && composer stan && composer md && composer tramp`
Expected: no findings. If PHPMD reports `EntryListRepository` over a codesize threshold, extract the saved-search reads into their own repository class rather than raising the threshold, and say so in the commit.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Repository backend/tests/Repository/SavedSearchEntryListTest.php
git commit -m "feat(#769): read every saved search's matches as one paged list"
```

---

### Task 2: The endpoints

**Files:**
- Create: `backend/src/Service/Search/SavedSearchTerms.php`
- Create: `backend/src/Service/Reader/SavedSearchMarkReadService.php`
- Create: `backend/src/Dto/Entry/MarkSavedSearchesReadRequest.php`
- Create: `backend/src/Controller/Api/SavedSearchEntriesController.php`
- Modify: `backend/src/Service/Search/SavedSearchMatchIds.php`
- Test: `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php`

**Interfaces:**
- Consumes: `SavedSearchEntryQuery`, `EntryListRepository::listForSavedSearches()` / `unreadMatchIdsForSavedSearches()`, `App\Repository\SavedSearchRepository::findForUser()`, `App\Service\Reader\BulkEntryReadMarker::markRead()`, `App\Http\EntryPage::of()`, `App\Repository\EntryListSort::PublishedDate`, `App\Http\EntryCursor::fromRequestValue()`.
- Produces:
  - `SavedSearchTerms::of(SavedSearch $savedSearch): SearchTerms` (static) and `SavedSearchTerms::forUser(int $userId): list<SearchTerms>`
  - `SavedSearchMarkReadService::mark(User $user, \DateTimeImmutable $until): void`
  - `GET /api/entries/saved-searches?cursor&limit&unread` → `{entries, nextCursor}`
  - `POST /api/entries/saved-searches/mark-read` body `{until}` → `204`

- [ ] **Step 1: Write the failing endpoint test**

Create `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php`. First read `backend/tests/Controller/Api/EntrySearchControllerTest.php` and copy its bootstrapping exactly (how it registers a user, authenticates, seeds a feed with entries, and creates a saved search) — the helpers below are named for what they must do, not for methods that exist:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

// use statements as in EntrySearchControllerTest

/**
 * The combined saved-search list and its mark-read.
 */
final class SavedSearchEntriesControllerTest extends /* the base class EntrySearchControllerTest uses */
{
    public function testListsMatchesOfEverySavedSearch(): void
    {
        // seed: entries "Climate report", "Rocket launch", "Nothing to see"
        // seed: saved searches "climate" and "rocket"

        $this->client->request('GET', '/api/entries/saved-searches', server: $this->authHeaders());

        self::assertResponseIsSuccessful();
        $titles = array_column($this->json()['entries'], 'title');
        self::assertSame(['Climate report', 'Rocket launch'], $titles);
    }

    public function testUnreadFlagNarrowsTheList(): void
    {
        // seed two matching entries, mark one read through the state endpoint
        $this->client->request('GET', '/api/entries/saved-searches?unread=1', server: $this->authHeaders());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['entries']);
    }

    public function testWithoutSavedSearchesTheListIsEmpty(): void
    {
        // seed matching entries, no saved searches
        $this->client->request('GET', '/api/entries/saved-searches', server: $this->authHeaders());

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['entries']);
    }

    public function testMarkReadFlipsMatchesUpToTheWatermarkOnly(): void
    {
        // seed a matching entry dated before, and one dated after, the watermark
        $this->client->request(
            'POST',
            '/api/entries/saved-searches/mark-read',
            server: $this->authHeaders(),
            content: json_encode(['until' => '2026-07-10T00:00:00+00:00']),
        );

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/entries/saved-searches?unread=1', server: $this->authHeaders());
        $titles = array_column($this->json()['entries'], 'title');
        self::assertSame(['Climate new'], $titles);
    }

    public function testAnotherUsersSavedSearchesAreNotUsed(): void
    {
        // seed a second user with a saved search matching this user's entries
        $this->client->request('GET', '/api/entries/saved-searches', server: $this->authHeaders());

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['entries']);
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchEntriesControllerTest.php`
Expected: FAIL with a 404 — the route does not exist.

- [ ] **Step 3: Write the terms mapping**

Create `backend/src/Service/Search/SavedSearchTerms.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchRepository;

/**
 * A saved search's stored shape — a bare term plus two mode columns — read as
 * the SearchTerms the search domain runs on. One mapping, so the badge scan and
 * the combined list can never disagree about what a saved search matches.
 */
final readonly class SavedSearchTerms
{
    public function __construct(private SavedSearchRepository $savedSearches)
    {
    }

    public static function of(SavedSearch $savedSearch): SearchTerms
    {
        return SearchTerms::fromTermAndMode(
            $savedSearch->getTerm(),
            SearchMode::fromFlags($savedSearch->isWholeWord(), $savedSearch->isPhrase()),
        );
    }

    /** @return list<SearchTerms> */
    public function forUser(int $userId): array
    {
        return array_map(self::of(...), $this->savedSearches->findForUser($userId));
    }
}
```

- [ ] **Step 4: Point the badge scan at the shared mapping**

In `backend/src/Service/Search/SavedSearchMatchIds.php`, replace the body of `forOne` with:

```php
    public function forOne(SavedSearch $savedSearch, int $userId): array
    {
        return $this->entries->unreadMatchIdsForUser(
            new EntrySearchQuery($userId, SavedSearchTerms::of($savedSearch)),
        );
    }
```

Delete the now-unused `SearchMode` and `SearchTerms` imports if nothing else in the file uses them.

- [ ] **Step 5: Write the mark-read service and its request body**

Create `backend/src/Dto/Entry/MarkSavedSearchesReadRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Entry;

/**
 * "Mark the combined saved-search list read." It names no scope: the list is
 * every saved search the caller keeps, so `until` — the moment the reader last
 * had it on screen — is the whole request.
 */
final readonly class MarkSavedSearchesReadRequest
{
    public function __construct(
        public \DateTimeImmutable $until,
    ) {
    }
}
```

Create `backend/src/Service/Reader/SavedSearchMarkReadService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\SavedSearchEntryQuery;
use App\Service\Search\SavedSearchTerms;

/**
 * Marks read every unread entry that matches any of the caller's saved
 * searches. Like the single-search mark-read, and unlike feed/tag mark-read,
 * there is no watermark to bump: a search spans every feed, so each matching
 * entry needs its own EntryState row.
 */
final readonly class SavedSearchMarkReadService
{
    public function __construct(
        private SavedSearchTerms $terms,
        private EntryListRepository $entries,
        private BulkEntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();

        $this->readMarker->markRead($userId, $this->entries->unreadMatchIdsForSavedSearches(
            new SavedSearchEntryQuery($userId, $this->terms->forUser($userId)),
            $until,
        ));
    }
}
```

- [ ] **Step 6: Write the controller**

Create `backend/src/Controller/Api/SavedSearchEntriesController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\MarkSavedSearchesReadRequest;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Http\EntryPage;
use App\Repository\EntryListRepository;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
use App\Repository\SavedSearchEntryQuery;
use App\Service\Reader\SavedSearchMarkReadService;
use App\Service\Search\SavedSearchTerms;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The combined saved-search list: one stream of everything the caller's saved
 * searches match. Its own endpoint rather than a mode on `/entries/search`,
 * which answers one term, and rather than a `view` on `/entries`, which filters
 * feeds rather than content (#769).
 */
#[Route('/api/entries/saved-searches')]
final readonly class SavedSearchEntriesController
{
    public function __construct(
        private SavedSearchTerms $terms,
        private EntryListRepository $entryList,
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
            termsPerSearch: $this->terms->forUser($userId),
            onlyUnread: $unread,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
        );

        return new JsonResponse(EntryPage::of(
            $this->entryList->listForSavedSearches($query),
            $query->limit,
            EntryListSort::PublishedDate,
        ));
    }

    #[Route('/mark-read', name: 'api_entries_saved_searches_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkSavedSearchesReadRequest $request,
    ): JsonResponse {
        $this->markRead->mark($user, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchEntriesControllerTest.php tests/Service/Search`
Expected: PASS. If the route 404s, run `php bin/console cache:clear` and check `php bin/console debug:router | grep saved-searches`.

- [ ] **Step 8: Run the whole backend suite and the gates**

Run: `cd backend && php bin/phpunit && composer check && composer md`
Expected: PASS, no findings.

- [ ] **Step 9: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#769): serve and mark read the combined saved-search list"
```

---

### Task 3: The frontend selection

**Files:**
- Modify: `frontend/src/app/reader/models.ts:255`
- Modify: `frontend/src/app/reader/query.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`
- Test: `frontend/src/app/reader/query.spec.ts`, `frontend/src/app/reader/reader-api.spec.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `Selection['kind']` gains `'saved-searches'`
  - `EntryView` gains `'saved-searches'`
  - `queryFromSelection({kind:'saved-searches', id:null, unread})` → `{view:'saved-searches'}` or `{view:'saved-searches', unread:true}`
  - `MarkReadTarget` gains `{ scope: 'saved-searches' }`
  - `ReaderApi.markSavedSearchesRead(until: string): Observable<void>`

All frontend commands run in the Docker frontend container: `docker compose exec -T frontend npm test -- <path>`.

- [ ] **Step 1: Write the failing tests**

Append to `frontend/src/app/reader/query.spec.ts`:

```ts
describe('the combined saved-searches view', () => {
  it('reads view=saved-searches as its own selection', () => {
    const { selection } = selectionFromParams(convertToParamMap({ view: 'saved-searches' }));

    expect(selection).toEqual({ kind: 'saved-searches', id: null, unread: false });
  });

  it('takes the unread refinement like every browsable list', () => {
    const { selection } = selectionFromParams(
      convertToParamMap({ view: 'saved-searches', unread: '1' }),
    );

    expect(selection.unread).toBe(true);
  });

  it('offers the unread switch', () => {
    expect(hasUnreadFilter({ kind: 'saved-searches', id: null, unread: false })).toBe(true);
  });

  it('asks for its own list, with the unread filter beside the view', () => {
    expect(queryFromSelection({ kind: 'saved-searches', id: null, unread: false })).toEqual({
      view: 'saved-searches',
    });
    expect(queryFromSelection({ kind: 'saved-searches', id: null, unread: true })).toEqual({
      view: 'saved-searches',
      unread: true,
    });
  });

  it('marks all read through its own scope', () => {
    expect(markReadTarget({ kind: 'saved-searches', id: null, unread: false })).toEqual({
      scope: 'saved-searches',
    });
  });

  it('is not a scoped-refresh list', () => {
    expect(canScopedRefresh({ kind: 'saved-searches', id: null, unread: false })).toBe(false);
  });

  it('loses to a searchable q in the URL, like every other list', () => {
    const { selection } = selectionFromParams(
      convertToParamMap({ view: 'saved-searches', q: 'climate' }),
    );

    expect(selection.kind).toBe('search');
  });
});
```

Add any missing imports (`hasUnreadFilter`, `canScopedRefresh`, `markReadTarget`, `queryFromSelection`) to the file's import list.

Append to `frontend/src/app/reader/reader-api.spec.ts`, following the file's existing `HttpTestingController` pattern:

```ts
it('reads the combined saved-search list from its own endpoint', () => {
  api.entries({ view: 'saved-searches' }).subscribe();

  const req = httpMock.expectOne((r) => r.url.endsWith('/api/entries/saved-searches'));
  expect(req.request.params.get('unread')).toBeNull();
  expect(req.request.params.get('view')).toBeNull();
  req.flush({ entries: [], nextCursor: null });
});

it('sends unread=1 when the list is filtered', () => {
  api.entries({ view: 'saved-searches', unread: true }).subscribe();

  const req = httpMock.expectOne((r) => r.url.endsWith('/api/entries/saved-searches'));
  expect(req.request.params.get('unread')).toBe('1');
  req.flush({ entries: [], nextCursor: null });
});

it('marks the combined saved-search list read with only a watermark', () => {
  api.markSavedSearchesRead('2026-09-01T10:00:00.000Z').subscribe();

  const req = httpMock.expectOne((r) => r.url.endsWith('/api/entries/saved-searches/mark-read'));
  expect(req.request.method).toBe('POST');
  expect(req.request.body).toEqual({ until: '2026-09-01T10:00:00.000Z' });
  req.flush(null);
});
```

- [ ] **Step 2: Run them to watch them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/query.spec.ts src/app/reader/reader-api.spec.ts`
Expected: FAIL — TypeScript rejects the unknown kind, and `markSavedSearchesRead` does not exist.

- [ ] **Step 3: Widen the vocabulary**

`frontend/src/app/reader/models.ts:255`:

```ts
export type EntryView =
  | 'all'
  | 'unread'
  | 'favorites'
  | 'kept'
  | 'viewed'
  | 'for-you'
  | 'saved-searches';
```

`frontend/src/app/reader/query.ts` — `Selection`:

```ts
export interface Selection {
  kind:
    | 'all'
    | 'tag'
    | 'subscription'
    | 'favorites'
    | 'kept'
    | 'viewed'
    | 'for-you'
    | 'saved-searches'
    | 'search';
  id: number | null;
  unread: boolean;
  /** Only a search carries one. Part of the list's identity, so it belongs to
   *  the selection rather than to a service beside it. */
  term?: string;
}
```

- [ ] **Step 4: Teach the four selection functions about the kind**

In `hasUnreadFilter`, replace the function and extend its docblock's last paragraph:

```ts
/** Whether the list offers the "All posts / only unread" switch. The saved
 *  views are already a filter on state, and a single search is a filter on
 *  content, so narrowing them by read state would be a second, conflicting
 *  answer to what the list is for. For you is not: it is a ranked view of the
 *  same posts every other browsable list shows (#710). Nor is the combined
 *  saved-search view: it is not a query the reader just typed but a standing
 *  list they keep, so read state is its natural second axis (#769). */
export function hasUnreadFilter(s: Selection): boolean {
  return canScopedRefresh(s) || s.kind === 'for-you' || s.kind === 'saved-searches';
}
```

In `selectionFromParams`, add the branch directly after the `for-you` one:

```ts
  } else if (view === 'saved-searches') {
    // Every saved search's matches in one list — a content filter, but a
    // standing one, so it takes the unread refinement (#769).
    selection = { kind: 'saved-searches', id: null, unread };
```

In `queryFromSelection`, add before `case 'search'`:

```ts
    case 'saved-searches':
      // Its own endpoint, so the filter rides beside the view exactly as for
      // you's does rather than becoming a view of its own.
      return s.unread ? { view: 'saved-searches', unread: true } : { view: 'saved-searches' };
```

In the `MarkReadTarget` union add `| { scope: 'saved-searches' }`, and in `markReadTarget` add before `default`:

```ts
    case 'saved-searches':
      // No id and no term: the endpoint needs nothing beyond who is asking.
      return { scope: 'saved-searches' };
```

- [ ] **Step 5: Add the two API calls**

In `frontend/src/app/reader/reader-api.ts`, inside `entries()`, add the branch right after the `q` branch:

```ts
    if (query.view === 'saved-searches') return this.savedSearchEntries(query.unread, cursor);
```

Add beside `searchEntries`:

```ts
  /** The combined saved-search list carries none of the entry list's filters —
   *  it is its own view over every subscription — so it forwards only the page
   *  and the unread refinement. */
  private savedSearchEntries(
    unread: boolean | undefined,
    cursor?: string | null,
  ): Observable<EntriesPage> {
    let params = new HttpParams().set('limit', PAGE_SIZE);
    if (unread) params = params.set('unread', '1');
    if (cursor) params = params.set('cursor', cursor);
    return this.http.get<EntriesPage>(`${this.base}/api/entries/saved-searches`, { params });
  }
```

Add beside `markForYouRead`:

```ts
  /** The combined saved-search list names no scope: the backend marks the
   *  matches by entry state, as the single-search mark-read does (#769). */
  markSavedSearchesRead(until: string): Observable<void> {
    return this.http.post<void>(`${this.base}/api/entries/saved-searches/mark-read`, { until });
  }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/query.spec.ts src/app/reader/reader-api.spec.ts`
Expected: PASS. If any other spec now fails to compile on an exhaustive `switch` over `Selection['kind']`, fix that switch — an exhaustive switch is exactly the guard that is meant to catch you here.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/query.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/query.spec.ts frontend/src/app/reader/reader-api.spec.ts
git commit -m "feat(#769): add the saved-searches selection and its API calls"
```

---

### Task 4: The list itself

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (title, titleCount, markReadNow, one new computed)
- Modify: `frontend/src/app/reader/reader-shell.component.html` (both `<app-entry-list>` instances)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` (icon map, new input)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html` (empty state)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`, `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `markReadTarget`, `queryFromSelection`, `ReaderApi.markSavedSearchesRead`, `SavedSearchesStore.savedSearches()`.
- Produces: `EntryListComponent`'s new input `savedSearchCount = input(0)`.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/app/reader/reader-shell.component.spec.ts`, follow the file's existing navigation helper and add:

```ts
it('titles the combined saved-search list with the sidebar label', async () => {
  await navigateTo({ view: 'saved-searches' });

  expect(shell.title()).toBe('Saved searches');
});

it('counts the same unread total the sidebar row shows', async () => {
  // stub the saved-searches API with two searches, 2 and 3 unread
  await navigateTo({ view: 'saved-searches' });

  expect(shell.titleCount()).toEqual({ value: 5, counts: 'unread' });
});

it('marks the whole combined list read through its own endpoint', async () => {
  await navigateTo({ view: 'saved-searches' });

  shell.onMarkAllRead();
  confirmDialog();

  const req = httpMock.expectOne((r) => r.url.endsWith('/api/entries/saved-searches/mark-read'));
  expect(req.request.method).toBe('POST');
  req.flush(null);
});
```

In `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`:

```ts
it('says there are no saved searches yet when the account keeps none', () => {
  fixture.componentRef.setInput('selection', { kind: 'saved-searches', id: null, unread: false });
  fixture.componentRef.setInput('entries', []);
  fixture.componentRef.setInput('savedSearchCount', 0);
  fixture.detectChanges();

  expect(host.querySelector('.empty')!.textContent).toContain('No saved searches yet');
});

it('says the list is empty when saved searches exist but match nothing', () => {
  fixture.componentRef.setInput('selection', { kind: 'saved-searches', id: null, unread: false });
  fixture.componentRef.setInput('entries', []);
  fixture.componentRef.setInput('savedSearchCount', 2);
  fixture.detectChanges();

  expect(host.querySelector('.empty')!.textContent).not.toContain('No saved searches yet');
});
```

Match the surrounding specs' setup style (`loading` false, whatever inputs the empty branch needs) rather than inventing new helpers.

- [ ] **Step 2: Run them to watch them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/reader-shell.component.spec.ts src/app/reader/entry-list/entry-list.component.spec.ts`
Expected: FAIL — no title, no such input.

- [ ] **Step 3: Add the strings**

In `frontend/public/i18n/en.json`, beside `"savedSearches"` in the `reader` section:

```json
    "savedSearchesEmpty": "No saved searches yet. Save a search to see it here.",
```

In `frontend/public/i18n/de.json`, at the same key position:

```json
    "savedSearchesEmpty": "Noch keine gespeicherten Suchen. Speichern Sie eine Suche, um sie hier zu sehen.",
```

- [ ] **Step 4: Teach the shell**

In `frontend/src/app/reader/reader-shell.component.ts`, add the computed next to the other saved-search members:

```ts
  /** The badge the sidebar's Saved searches row shows: the sum of the per-search
   *  counts. A post matching two searches counts twice here and once in the
   *  list — accepted, so the row and the heading show one number (#769). */
  readonly savedSearchesUnread = computed(() =>
    this.savedSearchesStore.savedSearches().reduce((sum, saved) => sum + saved.unreadCount, 0),
  );
```

In `title()`, add beside the other fixed views:

```ts
    if (s.kind === 'saved-searches') return this.i18n.translate('reader.savedSearches');
```

In `titleCount()`, add a case:

```ts
      case 'saved-searches':
        return unread(this.savedSearchesUnread());
```

In `markReadNow()`, add before the `for-you` branch:

```ts
    if (target.scope === 'saved-searches') {
      this.api.markSavedSearchesRead(until).subscribe({
        next: () => {
          this.entries.load(queryFromSelection(this.selection()));
          this.subs.load();
          this.savedSearchesStore.load();
        },
      });
      return;
    }
```

- [ ] **Step 5: Teach the entry list**

In `frontend/src/app/reader/entry-list/entry-list.component.ts`, add to `FIXED_VIEW_ICON`:

```ts
  'saved-searches': 'saved_search',
```

and add the input beside the other title inputs:

```ts
  /** How many saved searches the account keeps. The combined view's empty state
   *  distinguishes "you have none" from "yours match nothing", and only the
   *  shell holds that number. */
  readonly savedSearchCount = input(0);
```

In `frontend/src/app/reader/entry-list/entry-list.component.html`, replace the empty-state condition block (around line 261) with:

```html
      @if (selection().kind === 'search') {
        {{ 'reader.searchNoResults' | transloco: { term: displayedSearchTerm() } }}
      } @else if (selection().kind === 'saved-searches' && savedSearchCount() === 0) {
        {{ 'reader.savedSearchesEmpty' | transloco }}
      } @else {
        {{ (selection().unread ? 'reader.allCaughtUp' : 'reader.nothingHere') | transloco }}
        @if (!catalogEmpty()) {
          <a routerLink="/discover">{{ 'discover.browseCatalog' | transloco }}</a>
        }
      }
```

In `frontend/src/app/reader/reader-shell.component.html`, add the binding to **both** `<app-entry-list>` instances (near line 108 and near line 160), beside `[titleCount]`:

```html
          [savedSearchCount]="savedSearchesStore.savedSearches().length"
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/reader-shell.component.spec.ts src/app/reader/entry-list/entry-list.component.spec.ts`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader frontend/public/i18n
git commit -m "feat(#769): render the combined saved-search list and its actions"
```

---

### Task 5: The sidebar row

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html:94-119`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss:190-204`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `selectionQueryParams` (already exposed on the component as `protected readonly selectionQueryParams`), `selection()` input.
- Produces: no new API.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`, following the file's existing query helpers:

```ts
it('navigates to the combined view instead of expanding', () => {
  fixture.componentRef.setInput('savedSearches', [savedSearch({ id: 1, term: 'climate' })]);
  fixture.detectChanges();

  const label = host.querySelector<HTMLAnchorElement>('.savedsearch-toggle')!;
  expect(label.tagName).toBe('A');

  label.click();
  fixture.detectChanges();

  expect(host.querySelectorAll('.savedsearch-item').length).toBe(0);
});

it('expands and collapses from the chevron', () => {
  fixture.componentRef.setInput('savedSearches', [savedSearch({ id: 1, term: 'climate' })]);
  fixture.detectChanges();

  host.querySelector<HTMLButtonElement>('.savedsearch-head .chevzone')!.click();
  fixture.detectChanges();

  expect(host.querySelectorAll('.savedsearch-item').length).toBe(1);
});

it('marks the row active while the combined view is on screen', () => {
  fixture.componentRef.setInput('savedSearches', [savedSearch({ id: 1, term: 'climate' })]);
  fixture.componentRef.setInput('selection', { kind: 'saved-searches', id: null, unread: false });
  fixture.detectChanges();

  expect(host.querySelector('.savedsearch-toggle')!.classList).toContain('active');
});
```

Reuse the spec's existing saved-search fixture builder if it has one instead of the `savedSearch({…})` helper sketched here, and check whether an existing test asserts that clicking the label expands the list — that test now describes the chevron and must be updated, not deleted.

- [ ] **Step 2: Run them to watch them fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/sidebar/sidebar.component.spec.ts`
Expected: FAIL — the label is a `BUTTON` and clicking it expands.

- [ ] **Step 3: Turn the label into a nav link**

In `frontend/src/app/reader/sidebar/sidebar.component.html`, replace the label button (lines 97–110) with:

```html
        <a
          class="nav grow savedsearch-toggle"
          [class.active]="selection().kind === 'saved-searches'"
          [routerLink]="[]"
          [queryParams]="selectionQueryParams({ view: 'saved-searches' })"
          queryParamsHandling="merge"
        >
          <span class="lead"><app-icon name="saved_search" size="sm" /></span>
          <span>{{ 'reader.savedSearches' | transloco }}</span>
          @if (savedSearchesUnread() > 0) {
            <span class="count">{{ savedSearchesUnread() }}</span>
          }
        </a>
```

Leave the chevron button exactly as it is — it keeps `toggleSavedSearches()`.

- [ ] **Step 4: Drop the button reset from the styles**

In `frontend/src/app/reader/sidebar/sidebar.component.scss`, the `.savedsearch-toggle` rule exists only because the label was a `<button>` standing in for a `.nav` anchor. Replace the rule and the paragraph of its comment that explains the reset with:

```scss
.savedsearch-toggle {
  width: 100%;
}
```

Then delete even that if the row still lines up without it — check in the browser rather than by reading.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/sidebar/sidebar.component.spec.ts`
Expected: PASS.

- [ ] **Step 6: Look at the real render**

Bring the stack up (`docker compose up -d`), open the reader, and confirm on a real screen: the row aligns with the other nav rows and with the tag chevrons, the active state matches All items, the chevron still expands, and the whole thing survives a narrow viewport. A screenshot of the sidebar with the row active is the evidence — a passing spec is not.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/sidebar
git commit -m "feat(#769): open the combined view from the Saved searches row"
```

---

### Task 6: The end-to-end smoke

**Files:**
- Create: `frontend/e2e/saved-searches-combined.spec.ts`

**Interfaces:**
- Consumes: the routes `**/api/saved-searches*`, `**/api/entries/saved-searches*`, and whatever boot requests the reader makes.
- Produces: nothing other code reads.

- [ ] **Step 1: Write the spec**

Read `frontend/e2e/magazine-kicker-one-line.spec.ts` first and copy its stubbing and login helpers exactly. The spec must own every byte it asserts on — never read the seeded account's data.

```ts
import { expect, test } from '@playwright/test';

test('the Saved searches row opens one combined list', async ({ page }) => {
  // stub: two saved searches, and /api/entries/saved-searches answering two entries
  // stub every other reader boot route the shared helper stubs

  await page.goto('/reader');
  await page.getByRole('link', { name: 'Saved searches' }).click();

  await expect(page).toHaveURL(/view=saved-searches/);
  await expect(page.getByRole('heading', { name: /Saved searches/ })).toBeVisible();
  await expect(page.getByRole('article')).toHaveCount(2);
});

test('the unread switch narrows the combined list', async ({ page }) => {
  // same stubs; the unread=1 route answers one entry

  await page.goto('/reader?view=saved-searches');
  await page.getByRole('switch', { name: /unread/i }).click();

  await expect(page).toHaveURL(/unread=1/);
  await expect(page.getByRole('article')).toHaveCount(1);
});
```

Note for the locators: `getByRole` matches a name on substring, so "Saved searches" also matches a saved-search child row's label if one shares the prefix — assert on the row you mean, scoping by `.savedsearch-toggle` if needed.

- [ ] **Step 2: Run it**

Run: `cd frontend && npm run e2e -- saved-searches-combined.spec.ts`
Expected: PASS (needs the Docker stack up).

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/saved-searches-combined.spec.ts
git commit -m "test(#769): smoke the combined saved-search view"
```

---

### Task 7: The gates

**Files:** none — this task only runs and fixes.

- [ ] **Step 1: Backend, both database legs**

Run: `cd backend && php bin/phpunit`
Then: `docker compose exec php vendor/bin/phpunit`
Expected: PASS on SQLite and on MySQL.

- [ ] **Step 2: Backend quality gates**

Run: `cd backend && composer check && composer md`
Expected: no findings. If `composer tramp` fails, first run `composer show larspohlmann/phptramp` — CI runs the tip of its `develop`, so a red gate here can come from that tool rather than from this branch.

- [ ] **Step 3: Mutation testing over what this branch changed**

Run: `cd backend && composer infection:diff`
Expected: at or above `minMsi` in `infection.json5`. Escaped mutants name the line — add the missing assertion; never lower the threshold.

- [ ] **Step 4: Frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: PASS.

- [ ] **Step 5: The production build**

Run: `cd frontend && npm run build`
Expected: PASS. `npm run check` does NOT enforce the CSS budget — a style change can pass check and fail CI's production build on `anyComponentStyle` (#758). Run this on the host before pushing.

- [ ] **Step 6: Read today's dev log**

Run: `tail -n 200 "$(ls -t backend/var/log/dev-*.log | head -1)"`
Expected: no deprecations and no swallowed errors from the new endpoints.

- [ ] **Step 7: Push and open the PR**

```bash
git push -u origin feature/769-saved-searches-combined-view
gh pr create --base develop --title "feat(#769): a combined saved-searches view" --body "Closes #769"
```

The PR body must record the two accepted exceptions: the read-state switch against the `hasUnreadFilter` rule from #710, and the sidebar badge staying a sum that can exceed the view's own count.

---

## Self-Review

**Spec coverage.** Sidebar link + chevron (Task 5); row hidden with no saved searches (unchanged `@if (savedSearches().length)`, Task 5); badge stays a sum (Task 4, `savedSearchesUnread`); selection and URL (Task 3); flat deduplicated stream (Task 1, `listForSavedSearches` — no join multiplies a row); all saved searches, no flag (Task 2, `findForUser`); excluded feeds not hidden (Task 1, no `includeInAllItems` clause); no term marking (free — the endpoint returns no `matchedWords`, and `EntriesStore` reads `page.matchedWords ?? []`); no pills (free — the pill computeds test `kind === 'search'`); heading count and tab title (Task 4, `titleCount`); no scoped refresh and no "Last refreshed" (free — `canScopedRefresh` and `isSingleStreamView` both stay false); empty states (Task 4); the #710 exception (Task 3, `hasUnreadFilter`); both endpoints (Task 2); i18n pair (Task 4); every test from the spec's list (Tasks 1, 2, 3, 4, 5, 6).

**Type consistency.** `SavedSearchEntryQuery` is constructed in Task 1's test, in the controller and in the mark-read service with the same argument order. `listForSavedSearches` / `unreadMatchIdsForSavedSearches` are named identically everywhere. `savedSearchCount` is the input name in the component, the template, the spec and both shell bindings. `markSavedSearchesRead` is the API method name in the spec, the API and the shell.
