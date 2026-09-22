# Saved-Search Slug Routing & Membership Pill — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make saved searches first-class (a stored slug, a `/searches/saved/<slug>` route, and a membership-table-backed single-search list) and show a pill for every saved search an entry is a member of, on every card where tag pills appear.

**Architecture:** Two phases on one branch/PR. Phase A gives each `SavedSearch` a stored `slug` (`<id>-<slugified-term>`), routes single and combined saved-search views by path via a custom `UrlMatcher` on the reader shell (no component teardown), and serves a single saved search from the `saved_search_entry` membership table by id. Phase B threads a per-entry `savedSearches: [{id, slug, term}]` list through the entry serialization using the exact batch-loader pattern `categories` already use, renders it as a new `SavedSearchPillsComponent`, and deletes the old single-value "provenance" pill mechanism (`savedSearchTerm` / `savedSearchIds`).

**Tech Stack:** Symfony 7.4 (PHP 8.4), Doctrine ORM, `symfony/string` `AsciiSlugger` (already installed, `SluggerInterface` autowirable); Angular 20 (standalone, signals), Jest, PHPUnit, Infection.

**Spec:** This plan implements the shared-understanding design agreed in the originating grilling session (saved-search slug routing + membership pill). Key decisions carried verbatim below as Global Constraints.

## Global Constraints

- **One PR, one branch** off `develop` (which already contains #1116's membership table, sweep, and `SavedSearchEntry`). Branch name embeds the issue number: `feature/1118-saved-search-slug-and-membership-pill`.
- **Slug format:** `<id>-<slugified-term>`, lowercased ASCII; when the slugified term is empty, the slug is just `<id>`. The id prefix guarantees uniqueness. Slug and term are **immutable** (there is no term-edit path; a rename is delete + create).
- **Routes** (Angular base href is `/reader/`, so these are the app-relative paths): single = `searches/saved/:slug`, combined = `searches/saved/all`. `all` can never collide with a real slug (real slugs start with `<digits>-` or are bare digits, never the literal `all`). Only saved searches move to paths; `tag`/`subscription`/`entry`/`q` stay query params.
- **Single-search fetch semantics:** reads the `saved_search_entry` membership table (not a live search). Accept the ~1-minute sweep lag on both the single-search list and the pills.
- **Pill:** neutral colour, `saved_search` Material Symbol, label = the search `term` (ellipsized), links to `searches/saved/<slug>`, ordered by sidebar order, one pill per member search, shown in list + magazine + detail, shown even when the current list *is* that saved search.
- **Entry payload carries `{id, slug, term}` per membership** (self-sufficient; safe because slug/term are immutable). No `SavedSearchesStore` lookup at render time.
- **Old bookmarks may break** (`?q=…&searchOrigin=saved`). Ad-hoc `?q=` typed search stays a feature.
- **Delete the provenance mechanism** end-to-end: frontend `EntryDto.savedSearchTerm`, `EntriesStore.savedSearchIdsByEntryId`/`termsBySavedSearchId`, `EntriesPage.savedSearchIds`; backend `SavedSearchPage.savedSearchIds`, `SavedSearchEntriesResult.savedSearchIds`, `SavedSearchEntryRepository::firstMatchingSavedSearchIds`.
- **Quality gates that MUST pass before PR:** `composer check` (PSR-12 + PHPStan level max + phptramp), `composer md` clean on every touched `src` file, `php bin/phpunit` (SQLite) and `docker compose exec php composer test` (MySQL), PhpStorm inspections on changed PHP (no ERROR/WARNING), `npm run check` in the frontend container, `composer infection:diff`. Migrations verified by the CI migrate-from-empty leg on both SQLite and MySQL + `doctrine:schema:validate`. Scan today's `backend/var/log/dev-*.log` after backend work.
- **Datetimes are naive UTC.** **Never nest `cdkDropList`.** **Component styles live in sibling `.scss`.** **No hex colours in `.scss` outside `theme/`; no ad-hoc `px`.** Comments only where a future reader would get it wrong; one line, three at most.
- **Native iOS viability:** the single-search endpoint is keyed by id, JSON in / `application/problem+json` out, no browser-only inputs.

---

## File Structure

**Phase A — backend (create):**
- `backend/src/Service/Search/SavedSearchSlug.php` — id + term → slug string (wraps `SluggerInterface`).
- `backend/migrations/Version20260922173000.php` — add `saved_search.slug`, backfill, unique index (MySQL + SQLite).
- `backend/tests/Service/Search/SavedSearchSlugTest.php`, `backend/tests/Controller/Api/SavedSearchSlugRoutingTest.php`.

**Phase A — backend (modify):**
- `backend/src/Entity/SavedSearch.php` — `slug` column + accessor + `(user_id, slug)` unique constraint.
- `backend/src/Http/SavedSearchJson.php` — emit `slug`.
- `backend/src/Controller/Api/SavedSearchController.php` — set slug on create (two-step persist).
- `backend/src/Controller/Api/SavedSearchEntriesController.php` — add `GET /api/entries/saved-searches/{id}`.

**Phase A — frontend (create):**
- `frontend/src/app/reader/reader-matcher.ts` — `UrlMatcher` for the reader shell (root + `searches/saved/:slug`).

**Phase A — frontend (modify):**
- `frontend/src/app/reader/models.ts`, `query.ts`, `reader-api.ts`, `reader-shell.component.ts`, `sidebar/sidebar.component.ts` + `.html`, `app.routes.ts`.

**Phase B — backend (create):**
- `backend/src/Repository/SavedSearchMembershipLoader.php` — batch-load `{id, slug, term}` per entry into rows.
- `backend/tests/Repository/SavedSearchMembershipLoaderTest.php`.

**Phase B — backend (modify):**
- `backend/src/Repository/EntryListRow.php`, `EntryCategoryLoader.php` (pattern reference only), `SavedSearchEntryRepository.php` (add `savedSearchesByEntry`, delete `firstMatchingSavedSearchIds`), `backend/src/Http/EntryJson.php`, `EntryPage.php` (no change) / `SavedSearchPage.php` (collapse), `backend/src/Service/Search/SavedSearchEntries.php` + `SavedSearchEntriesResult.php` (drop provenance), controllers that serialize entries (`EntryController`, `EntrySearchController`, `SavedSearchEntriesController`, for-you responder).

**Phase B — frontend (create):**
- `frontend/src/app/reader/saved-search-pills/saved-search-pills.component.ts` + `.html` + `.scss`.

**Phase B — frontend (modify):**
- `frontend/src/app/reader/models.ts`, `entries.store.ts`, `entry-row/entry-row.component.html` + `.scss`, `entry-meta/entry-meta.component.ts` + `.html`, `magazine/entry-kicker-line.component.html`, `reader-view/reader-view.component.html`, plus removal of the now-unused `.saved-search-pill` utility if nothing else uses it.

---

# PHASE A — Saved-search slug + first-class routing + single-search endpoint

### Task A1: `SavedSearchSlug` service

**Files:**
- Create: `backend/src/Service/Search/SavedSearchSlug.php`
- Test: `backend/tests/Service/Search/SavedSearchSlugTest.php`

**Interfaces:**
- Produces: `SavedSearchSlug::build(int $id, string $term): string` — `"<id>-<lower-ascii-slug>"`, or `"<id>"` when the term slugifies to empty.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\SavedSearchSlug;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class SavedSearchSlugTest extends TestCase
{
    private SavedSearchSlug $slug;

    protected function setUp(): void
    {
        $this->slug = new SavedSearchSlug(new AsciiSlugger());
    }

    public function testPrefixesTheIdAndLowercasesTheTerm(): void
    {
        self::assertSame('42-climate-news', $this->slug->build(42, 'Climate News'));
    }

    public function testTransliteratesNonAsciiAndDropsOperators(): void
    {
        self::assertSame('7-uber-cafe', $this->slug->build(7, 'Über  Café'));
    }

    public function testFallsBackToTheBareIdWhenTheTermSlugifiesEmpty(): void
    {
        self::assertSame('9', $this->slug->build(9, '!!! ***'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Search/SavedSearchSlugTest.php`
Expected: FAIL — class `App\Service\Search\SavedSearchSlug` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * A saved search's stable URL slug: its id, then a readable slug of its term.
 * The id prefix makes the slug unique; the term half is cosmetic, so an
 * operator-only term that slugifies to nothing leaves just the id.
 */
final readonly class SavedSearchSlug
{
    public function __construct(private SluggerInterface $slugger)
    {
    }

    public function build(int $id, string $term): string
    {
        $readable = $this->slugger->slug($term)->lower()->toString();

        return $readable === '' ? (string) $id : $id . '-' . $readable;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Search/SavedSearchSlugTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Search/SavedSearchSlug.php backend/tests/Service/Search/SavedSearchSlugTest.php
git commit -m "feat(#1118): add SavedSearchSlug id-prefixed slug builder"
```

---

### Task A2: `SavedSearch.slug` column + migration + backfill

**Files:**
- Modify: `backend/src/Entity/SavedSearch.php`
- Create: `backend/migrations/Version20260922173000.php`

**Interfaces:**
- Produces: `SavedSearch::getSlug(): ?string`, `SavedSearch::setSlug(string $slug): void`; DB column `saved_search.slug VARCHAR(130) NULL` with unique index `uniq_saved_search_user_slug (user_id, slug)`.

- [ ] **Step 1: Add the property, accessors, and unique constraint to the entity**

In `backend/src/Entity/SavedSearch.php`, add the constraint attribute beside the existing one (after line 15's `)]`):

```php
#[ORM\UniqueConstraint(
    name: 'uniq_saved_search_user_slug',
    columns: ['user_id', 'slug'],
)]
```

Add the property after `$term` (after line 28):

```php
/**
 * The stable URL slug, "<id>-<slug of term>". Null only in the instant
 * between persisting the row (which assigns the id) and setting the slug
 * from it; every stored row has one. Immutable once set — the term never
 * changes, so the slug never does.
 */
#[ORM\Column(length: 130, nullable: true)]
private ?string $slug = null;
```

Add accessors after `getTerm()` (after line 74):

```php
public function getSlug(): ?string
{
    return $this->slug;
}

public function setSlug(string $slug): void
{
    $this->slug = $slug;
}
```

- [ ] **Step 2: Write the migration (add column, backfill, unique index)**

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Give every saved search a stable URL slug ("<id>-<slug of term>"). Adds the
 * nullable column, backfills existing rows from their id and term, then adds
 * the per-user unique index. The backfill must match App\Service\Search\SavedSearchSlug.
 */
final class Version20260922173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add saved_search.slug and backfill it (#1118).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('saved_search')->hasColumn('slug'),
            'saved_search.slug already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE saved_search ADD slug VARCHAR(130) DEFAULT NULL');
        } elseif ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE saved_search ADD COLUMN slug VARCHAR(130) DEFAULT NULL');
        } else {
            throw new \RuntimeException('Unsupported database platform for the saved-search slug migration.');
        }
    }

    public function postUp(Schema $schema): void
    {
        $slugger = new AsciiSlugger();
        /** @var list<array{id: int, term: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, term FROM saved_search');
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $readable = $slugger->slug((string) $row['term'])->lower()->toString();
            $slug = $readable === '' ? (string) $id : $id . '-' . $readable;
            $this->connection->executeStatement(
                'UPDATE saved_search SET slug = :slug WHERE id = :id',
                ['slug' => $slug, 'id' => $id],
            );
        }

        $this->connection->executeStatement(
            'CREATE UNIQUE INDEX uniq_saved_search_user_slug ON saved_search (user_id, slug)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_saved_search_user_slug ON saved_search');
        $this->addSql('ALTER TABLE saved_search DROP COLUMN slug');
    }
}
```

> Note: the backfill and index run in `postUp()` (not `up()`) so the column exists before the `UPDATE`/`CREATE INDEX` execute, and the SQLite `down()` line is adjusted below.

- [ ] **Step 3: Fix the SQLite `down()` (drop index without table qualifier)**

Replace the `down()` body with a platform switch, because SQLite's `DROP INDEX` takes no `ON table`:

```php
public function down(Schema $schema): void
{
    $platform = $this->connection->getDatabasePlatform();
    if ($platform instanceof AbstractMySQLPlatform) {
        $this->addSql('DROP INDEX uniq_saved_search_user_slug ON saved_search');
    } else {
        $this->addSql('DROP INDEX uniq_saved_search_user_slug');
    }
    $this->addSql('ALTER TABLE saved_search DROP COLUMN slug');
}
```

- [ ] **Step 4: Verify the migration on SQLite from empty**

Run: `cd backend && rm -f var/data_test.db && php bin/console doctrine:migrations:migrate --no-interaction --env=test && php bin/console doctrine:schema:validate --env=test`
Expected: migrations apply cleanly; schema validate reports mapping and database in sync (the entity `slug` column matches).

- [ ] **Step 5: Verify on MySQL from empty (Docker)**

Run: `docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php php bin/console doctrine:schema:validate`
Expected: clean apply + validate.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Entity/SavedSearch.php backend/migrations/Version20260922173000.php
git commit -m "feat(#1118): add and backfill saved_search.slug"
```

---

### Task A3: emit `slug` in `SavedSearchJson`, set it on create

**Files:**
- Modify: `backend/src/Http/SavedSearchJson.php`, `backend/src/Controller/Api/SavedSearchController.php`
- Test: `backend/tests/Controller/Api/SavedSearchSlugRoutingTest.php` (create-path assertion; extended in A4)

**Interfaces:**
- Produces: `SavedSearchJson::one(...)` now includes `'slug' => string|null`; a created saved search has a non-null `slug`.
- Consumes: `SavedSearchSlug::build` (Task A1).

- [ ] **Step 1: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase; // use the project's existing authenticated API test base

final class SavedSearchSlugRoutingTest extends ApiTestCase
{
    public function testCreateReturnsAnIdPrefixedSlug(): void
    {
        $this->loginAsSeededUser(); // project helper; adapt to the existing auth harness

        $this->client->request('POST', '/api/saved-searches', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'term' => 'Climate News',
            'wholeWord' => false,
            'phrase' => false,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $saved = $body['savedSearch'];
        self::assertSame($saved['id'] . '-climate-news', $saved['slug']);
    }
}
```

> Adapt `ApiTestCase`/`loginAsSeededUser`/`$this->client` to the repo's actual functional-test base and auth helper (grep `tests/` for an existing `POST /api/saved-searches` test and copy its setup). The assertion is the point.

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchSlugRoutingTest.php`
Expected: FAIL — `slug` key absent / null.

- [ ] **Step 3: Emit `slug` in `SavedSearchJson`**

Update the docblock return shape (add `slug: string|null,` after `term`) and the array in `SavedSearchJson::one()`:

```php
return [
    'id' => $savedSearch->getId(),
    'slug' => $savedSearch->getSlug(),
    'term' => $savedSearch->getTerm(),
    'wholeWord' => $savedSearch->isWholeWord(),
    'phrase' => $savedSearch->isPhrase(),
    'position' => $savedSearch->getPosition(),
    'unreadEntryIds' => $unreadEntryIds,
    'includeInDigest' => $savedSearch->isIncludeInDigest(),
];
```

- [ ] **Step 4: Set the slug on create (two-step persist)**

In `SavedSearchController`, inject `SavedSearchSlug` (add `private SavedSearchSlug $slug,` to the constructor and `use App\Service\Search\SavedSearchSlug;`). Replace the create block (lines 68-73) with:

```php
if ($savedSearch === null) {
    $savedSearch = new SavedSearch($user, $request->term, $request->wholeWord, $request->phrase);
    $this->em->persist($savedSearch);
    $this->em->flush();
    $savedSearch->setSlug($this->slug->build((int) $savedSearch->getId(), $savedSearch->getTerm()));
    $this->em->flush();
    $this->sweep->sweepOne($savedSearch, SweepBudget::seconds(self::CREATE_SWEEP_BUDGET_SECONDS));
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchSlugRoutingTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Http/SavedSearchJson.php backend/src/Controller/Api/SavedSearchController.php backend/tests/Controller/Api/SavedSearchSlugRoutingTest.php
git commit -m "feat(#1118): set and expose saved-search slug on create"
```

---

### Task A4: single-search endpoint `GET /api/entries/saved-searches/{id}`

**Files:**
- Modify: `backend/src/Controller/Api/SavedSearchEntriesController.php`
- Test: extend `backend/tests/Controller/Api/SavedSearchSlugRoutingTest.php`

**Interfaces:**
- Produces: `GET /api/entries/saved-searches/{id}` (name `api_entries_saved_search_one`) → `SavedSearchPage`-shaped body of that one search's members, newest-first, honoring `?unread`/`?cursor`/`?limit`; 404 when the id is not owned by the caller.
- Consumes: `SavedSearchRepository::findOneOwnedBy` (exists), `SavedSearchEntries::list` (exists), `SavedSearchListQuery` with a single-element `savedSearchIds`.

- [ ] **Step 1: Write the failing functional test (append to A3's test class)**

```php
public function testSingleSavedSearchListsOnlyItsMembers(): void
{
    $this->loginAsSeededUser();
    $searchId = $this->createSavedSearch('climate'); // helper: POST then return id
    // Seed at least one entry the sweep records for this search (reuse the
    // membership fixtures the #1116 tests use), then:
    $this->client->request('GET', '/api/entries/saved-searches/' . $searchId);

    self::assertResponseIsSuccessful();
    $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    self::assertArrayHasKey('entries', $body);
    self::assertArrayHasKey('nextCursor', $body);
}

public function testSingleSavedSearchIsNotFoundForAnotherUsersSearch(): void
{
    $this->loginAsSeededUser();
    $otherId = $this->createSavedSearchForOtherUser('sports');
    $this->client->request('GET', '/api/entries/saved-searches/' . $otherId);
    self::assertResponseStatusCodeSame(404);
}
```

> Model membership seeding on `tests/Repository/SavedSearchMembershipSweepRepositoriesTest.php` and `tests/Service/Search/Membership/SavedSearchMembershipSweepTest.php`.

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchSlugRoutingTest.php`
Expected: FAIL — route not found (404 for the owned case too / method-level).

- [ ] **Step 3: Add the action**

In `SavedSearchEntriesController`, add `use App\Http\SavedSearchPage;` (already present) and `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`, then add:

```php
#[Route('/{id}', name: 'api_entries_saved_search_one', methods: ['GET'], requirements: ['id' => '\d+'])]
public function one(
    int $id,
    #[CurrentUser] User $user,
    #[MapQueryParameter] ?string $cursor = null,
    #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
    #[MapQueryParameter] bool $unread = false,
): JsonResponse {
    $userId = (int) $user->getId();
    $this->savedSearches->findOneOwnedBy($id, $userId)
        ?? throw new NotFoundHttpException('No such saved search.');

    $query = new SavedSearchListQuery(
        userId: $userId,
        savedSearchIds: [$id],
        onlyUnread: $unread,
        cursor: EntryCursor::fromRequestValue($cursor),
        limit: $limit,
    );
    $result = $this->entries->list($query);

    return new JsonResponse(SavedSearchPage::of(
        $result->withRows($this->categoryLoader->loadInto($result->rows)),
        $query->limit,
    ));
}
```

> This route sits beside `''` and `/mark-read`; `requirements id=\d+` keeps `/mark-read` distinct. In Phase B, Task B3 adds the membership loader to `$result->rows` here too (alongside `categoryLoader`).

- [ ] **Step 4: Run to verify it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchSlugRoutingTest.php`
Expected: PASS (owned → 200 with page shape; other user → 404).

- [ ] **Step 5: Backend gate for touched files**

Run: `cd backend && composer cs && composer stan && composer md && vendor/bin/mate` is not needed; instead run PhpStorm inspections on the changed controller if available.
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Api/SavedSearchEntriesController.php backend/tests/Controller/Api/SavedSearchSlugRoutingTest.php
git commit -m "feat(#1118): serve a single saved search from the membership table by id"
```

---

### Task A5: frontend model + query types for slug routing

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/query.ts`

**Interfaces:**
- Produces: `SavedSearchDto.slug: string`, `SavedSearchWire.slug: string`; `EntryQuery.savedSearchId?: number`; `Selection.kind` gains `'saved-search'`; `SavedSearchesStore` maps `slug`.

- [ ] **Step 1: Add `slug` to the DTO and wire (models.ts)**

In `SavedSearchDto` (after line 14 `id: number;`) add:

```ts
  /** Stable URL slug ("<id>-<term slug>"); the reader routes to this search by it. */
  slug: string;
```

In `SavedSearchWire` (after line 33 `id: number;`) add:

```ts
  slug: string;
```

- [ ] **Step 2: Map `slug` in the store**

In `frontend/src/app/reader/saved-searches.store.ts`, in the `savedSearches` computed mapping (after line 34 `id: wire.id,`) add:

```ts
      slug: wire.slug,
```

- [ ] **Step 3: Add the `EntryQuery.savedSearchId` field and `Selection` kind (query.ts / models.ts)**

Find `EntryQuery` (in `models.ts` — it is exported from there; grep confirms `EntryQuery` shape) and add an optional field:

```ts
  /** Set only for a single saved search: fetch its members from the membership table. */
  savedSearchId?: number;
```

In `query.ts`, extend the `Selection.kind` union (line 6-15) by adding `| 'saved-search'` (singular) after `'saved-searches'`.

- [ ] **Step 4: Write the failing unit test for `queryFromSelection`**

Add to the existing `query.spec.ts` (grep `queryFromSelection` in `frontend/src/app/reader` for the spec file):

```ts
it('routes a single saved-search selection to its id', () => {
  expect(queryFromSelection({ kind: 'saved-search', id: 42, unread: false })).toEqual({
    view: 'all',
    savedSearchId: 42,
  });
});

it('carries unread on a single saved-search selection', () => {
  expect(queryFromSelection({ kind: 'saved-search', id: 42, unread: true })).toEqual({
    view: 'all',
    savedSearchId: 42,
    unread: true,
  });
});
```

- [ ] **Step 5: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/query.spec.ts`
Expected: FAIL — `queryFromSelection` has no `'saved-search'` case (TS also flags the missing branch).

- [ ] **Step 6: Add the `queryFromSelection` case**

In `query.ts` `queryFromSelection` switch, before `case 'search':` add:

```ts
    case 'saved-search':
      // A single saved search reads the membership table by id, not a live
      // query, so it carries the id and takes the unread refinement like any list.
      return s.unread
        ? { view: 'all', savedSearchId: s.id ?? undefined, unread: true }
        : { view: 'all', savedSearchId: s.id ?? undefined };
```

- [ ] **Step 7: Run to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/query.spec.ts`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/query.ts frontend/src/app/reader/saved-searches.store.ts frontend/src/app/reader/query.spec.ts
git commit -m "feat(#1118): add slug + single saved-search selection types"
```

---

### Task A6: `ReaderApi` route for the single saved search

**Files:**
- Modify: `frontend/src/app/reader/reader-api.ts`

**Interfaces:**
- Produces: `ReaderApi.entries()` dispatches to `GET /api/entries/saved-searches/{id}` when `query.savedSearchId != null`.

- [ ] **Step 1: Write the failing test**

In `reader-api.spec.ts` (grep for it; if none, use `HttpTestingController` pattern from a sibling spec):

```ts
it('fetches a single saved search by id from its membership endpoint', () => {
  api.entries({ view: 'all', savedSearchId: 42 }).subscribe();
  const req = httpMock.expectOne((r) => r.url.endsWith('/api/entries/saved-searches/42'));
  expect(req.request.method).toBe('GET');
  req.flush({ entries: [], nextCursor: null });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-api.spec.ts`
Expected: FAIL — request goes to `/api/entries?...` instead.

- [ ] **Step 3: Add the dispatch and the method**

In `entries()` (line 73), add as the first guard:

```ts
    if (query.savedSearchId != null) {
      return this.singleSavedSearchEntries(query.savedSearchId, query.unread, cursor);
    }
```

Add the private method beside `savedSearchEntries` (after line 108):

```ts
  /** One saved search's members, from the membership table, by id. Carries only
   *  the page and the unread refinement, exactly like the combined list. */
  private singleSavedSearchEntries(
    id: number,
    unread: boolean | undefined,
    cursor?: string | null,
  ): Observable<EntriesPage> {
    let params = new HttpParams().set('limit', PAGE_SIZE);
    if (unread) params = params.set('unread', '1');
    if (cursor) params = params.set('cursor', cursor);
    return this.http.get<EntriesPage>(`${this.base}/api/entries/saved-searches/${id}`, { params });
  }
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-api.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts
git commit -m "feat(#1118): fetch a single saved search from its by-id endpoint"
```

---

### Task A7: `selectionFromRoute` — path param → selection

**Files:**
- Create: `frontend/src/app/reader/reader-matcher.ts` (matcher + `selectionFromRoute`)
- Modify: `frontend/src/app/reader/query.ts` (export `selectionFromRoute` OR keep in reader-matcher; keep the pure mapping in `query.ts` for testability)
- Test: `frontend/src/app/reader/query.spec.ts`

**Interfaces:**
- Produces: `readerMatcher: UrlMatcher` (matches `` and `searches/saved/:slug`, exposing `savedSearch` posParam); `selectionFromRoute(pathParams: ParamMap, queryParams: ParamMap): { selection: Selection; entryId: number | null }`.
- Consumes: `selectionFromParams` (existing), `idFromSlug` (new small helper).

- [ ] **Step 1: Write failing unit tests for `selectionFromRoute` (query.spec.ts)**

```ts
import { convertToParamMap } from '@angular/router';

const noQuery = convertToParamMap({});

it('maps the "all" path segment to the combined saved-searches selection', () => {
  const { selection } = selectionFromRoute(convertToParamMap({ savedSearch: 'all' }), noQuery);
  expect(selection).toEqual({ kind: 'saved-searches', id: null, unread: false });
});

it('maps a slug to a single saved-search selection by its leading id', () => {
  const { selection } = selectionFromRoute(convertToParamMap({ savedSearch: '42-climate' }), noQuery);
  expect(selection).toEqual({ kind: 'saved-search', id: 42, unread: false });
});

it('carries unread=1 from the query params onto a path selection', () => {
  const { selection } = selectionFromRoute(
    convertToParamMap({ savedSearch: '42-climate' }),
    convertToParamMap({ unread: '1' }),
  );
  expect(selection.unread).toBe(true);
});

it('falls back to query-param selection when no saved-search segment is present', () => {
  const { selection } = selectionFromRoute(noQuery, convertToParamMap({ tag: '7' }));
  expect(selection).toEqual({ kind: 'tag', id: 7, unread: false });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/query.spec.ts`
Expected: FAIL — `selectionFromRoute` undefined.

- [ ] **Step 3: Implement the matcher + mapping**

Create `frontend/src/app/reader/reader-matcher.ts`:

```ts
import { ParamMap, UrlMatchResult, UrlSegment } from '@angular/router';
import { Selection, selectionFromParams } from './query';

/**
 * The reader shell owns the root URL and the saved-search paths alike, through
 * one route config so the component is never torn down when the user moves
 * between a list and a saved search. `searches/saved/:slug` (slug may be the
 * literal "all") is the only path it consumes; everything else is query params.
 */
export function readerMatcher(segments: UrlSegment[]): UrlMatchResult | null {
  if (segments.length === 0) {
    return { consumed: [] };
  }
  if (segments.length === 3 && segments[0].path === 'searches' && segments[1].path === 'saved') {
    return { consumed: segments, posParams: { savedSearch: segments[2] } };
  }
  return null;
}

/** The leading integer of a slug ("42-climate" -> 42); null if absent. */
function idFromSlug(slug: string): number | null {
  const id = Number.parseInt(slug, 10);
  return Number.isNaN(id) ? null : id;
}

export function selectionFromRoute(
  pathParams: ParamMap,
  queryParams: ParamMap,
): { selection: Selection; entryId: number | null } {
  const savedSearch = pathParams.get('savedSearch');
  if (savedSearch === null) {
    return selectionFromParams(queryParams);
  }

  const unread = queryParams.get('unread') === '1';
  const entryId = null;
  if (savedSearch === 'all') {
    return { selection: { kind: 'saved-searches', id: null, unread }, entryId };
  }
  return { selection: { kind: 'saved-search', id: idFromSlug(savedSearch), unread }, entryId };
}
```

> Import `selectionFromRoute` in `query.spec.ts` from `./reader-matcher`. Keep `Selection`/`selectionFromParams` exported from `query.ts` (they already are).

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/query.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-matcher.ts frontend/src/app/reader/query.spec.ts
git commit -m "feat(#1118): map the saved-search path segment to a selection"
```

---

### Task A8: wire the matcher into `app.routes.ts`

**Files:**
- Modify: `frontend/src/app/app.routes.ts`

**Interfaces:**
- Consumes: `readerMatcher` (Task A7).

- [ ] **Step 1: Replace the empty-path reader route with the matcher**

In `app.routes.ts`, add the import:

```ts
import { readerMatcher } from './reader/reader-matcher';
```

Replace the `path: ''` route object (lines 70-79) with (keeping the same title/guard/loader):

```ts
  {
    // The reader owns the root URL and the saved-search paths (searches/saved/:slug,
    // and searches/saved/all) through one config, so moving between a list and a
    // saved search never tears the shell down. It names the tab after the open
    // article or the selected list across those navigations.
    matcher: readerMatcher,
    title: DYNAMIC_TITLE,
    canActivate: [authGuard],
    loadComponent: () =>
      import('./reader/reader-shell.component').then((m) => m.ReaderShellComponent),
  },
```

Leave `{ path: '**', redirectTo: '' }` as-is.

- [ ] **Step 2: Verify build + existing routing specs**

Run: `docker compose exec -T frontend npx jest src/app/app.routes` (if a spec exists) and `docker compose exec -T frontend npm run build`
Expected: build succeeds; no route regressions.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/app.routes.ts
git commit -m "feat(#1118): route the reader shell via a URL matcher for saved-search paths"
```

---

### Task A9: `ReaderShellComponent` reads the path param

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts`

**Interfaces:**
- Consumes: `selectionFromRoute` (A7). Produces: `selection`/`entryId`/`activeSavedSearchId` derived from path + query params.

- [ ] **Step 1: Read `paramMap` and switch `parsed` to `selectionFromRoute`**

Add the import:

```ts
import { selectionFromRoute } from './reader-matcher';
```

After the existing `params` signal (line 274-276), add a path-params signal and repoint `parsed`:

```ts
  private readonly pathParams = toSignal(this.route.paramMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly parsed = computed(() => selectionFromRoute(this.pathParams(), this.params()));
```

(Delete the old `private readonly parsed = computed(() => selectionFromParams(this.params()));` line 277.)

- [ ] **Step 2: Derive `activeSavedSearchId` from the selection**

Replace the `currentSavedSearch` term-matching computed (lines 1089-1103) usage: add a direct id computed and bind the sidebar to it.

```ts
  /** The single saved search the list is showing, by id, or null. Read straight
   *  off the selection now that a saved search is addressed by id, not re-matched
   *  by term. */
  readonly activeSavedSearchId = computed(() => {
    const s = this.selection();
    return s.kind === 'saved-search' ? s.id : null;
  });
```

In `reader-shell.component.html`, change the sidebar binding (line ~75) from `[activeSavedSearchId]="currentSavedSearch()?.id ?? null"` to `[activeSavedSearchId]="activeSavedSearchId()"`. Remove `currentSavedSearch` if nothing else consumes it (grep first; `searchedTermAndMode` may feed the search box — leave that).

- [ ] **Step 3: Ensure the single-search title renders**

The list title for a single saved search must show its term. Add a computed that resolves the active id against the store for the title, and feed the existing `[searchTitleTerm]`/`[title]` inputs:

```ts
  readonly activeSavedSearch = computed(() => {
    const id = this.activeSavedSearchId();
    return id === null ? null : this.savedSearchesStore.savedSearches().find((s) => s.id === id) ?? null;
  });
```

Wire it into whichever title input the list header uses for a saved search (mirror how `currentSavedSearch` was used for the header). Keep the change minimal: where the header previously derived the saved-search title, read `activeSavedSearch()`.

- [ ] **Step 4: Run the shell specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-shell`
Expected: PASS (update any spec that asserted the old `currentSavedSearch` term-match path to drive via `savedSearch` path param instead).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.html
git commit -m "feat(#1118): drive reader selection and title from the saved-search path"
```

---

### Task A10: sidebar links by slug path

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`, `.html`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `SavedSearchDto.slug`. Produces: single rows link to `['/searches/saved', slug]`; combined link to `['/searches/saved/all']`; active state from `activeSavedSearchId`.

- [ ] **Step 1: Write/adjust the failing spec**

```ts
it('links a saved search row to its slug path', () => {
  // render sidebar with a saved search { id: 42, slug: '42-climate', ... }
  const row = fixture.nativeElement.querySelector('.savedsearch-item');
  expect(row.getAttribute('href')).toContain('/reader/searches/saved/42-climate');
});

it('links the saved-searches header to the combined path', () => {
  const head = fixture.nativeElement.querySelector('.savedsearch-toggle');
  expect(head.getAttribute('href')).toContain('/reader/searches/saved/all');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/sidebar/sidebar.component.spec.ts`
Expected: FAIL — rows still use `?q=…&searchOrigin=saved`.

- [ ] **Step 3: Simplify `savedSearchLinks` and the template**

In `sidebar.component.ts`, drop the `params`/`savedSearchParams` mapping — `orderedSavedSearches` can iterate `SavedSearchDto` directly (each already has `slug`). Remove the now-unused `savedSearchParams` import. Replace `savedSearchLinks` (lines 209-214) with:

```ts
  protected readonly savedSearchLinks = computed(() => this.savedSearches());
```

(Or inline `savedSearches()` into `orderedSavedSearches`' `byId` map. Keep `orderedSavedSearches`/`visibleSavedSearches` logic intact; they now carry `slug`.)

In `sidebar.component.html`:
- Combined header link (lines 112-118): replace `[routerLink]="[]"` + `[queryParams]="selectionQueryParams({ view: 'saved-searches' })"` + `queryParamsHandling="merge"` with:

```html
        <a
          class="nav grow savedsearch-toggle"
          [class.active]="selection().kind === 'saved-searches'"
          [routerLink]="['/searches/saved/all']"
        >
```

- Single row link (lines 140-147): replace `[routerLink]="[]"` + `[queryParams]="saved.params"` + `queryParamsHandling="merge"` with:

```html
            <a
              class="nav savedsearch-item"
              [class.active]="saved.id === activeSavedSearchId()"
              [class.with-digest]="showDigestToggles()"
              [routerLink]="['/searches/saved', saved.slug]"
            >
```

> `selection` is already available to the sidebar (used at line 114). `activeSavedSearchId` is an existing input (line 97-102), still fed by the shell (now from `activeSavedSearchId()`).

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/sidebar/sidebar.component.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/sidebar/sidebar.component.ts frontend/src/app/reader/sidebar/sidebar.component.html frontend/src/app/reader/sidebar/sidebar.component.spec.ts
git commit -m "feat(#1118): link saved searches by slug path in the sidebar"
```

---

### Task A11: Phase A integration check

- [ ] **Step 1: Full frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all pass.

- [ ] **Step 2: Manual smoke in the built-in browser (optional but recommended)**

Bring up the dev stack; navigate to `/reader/searches/saved/all` (combined) and `/reader/searches/saved/<a real slug>` (single). Confirm the list loads, the sidebar highlights the row, and switching to a tag drops the path.

- [ ] **Step 3: Backend gate + dev log scan**

Run: `cd backend && composer check && composer md && php bin/phpunit` then `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`
Expected: green; no new deprecations/errors in the dev log.

---

# PHASE B — Membership pill

### Task B1: per-entry membership read

**Files:**
- Modify: `backend/src/Repository/SavedSearchEntryRepository.php`
- Test: `backend/tests/Repository/SavedSearchMembershipLoaderTest.php` (repo-level assertions here; loader added B2)

**Interfaces:**
- Produces: `SavedSearchEntryRepository::savedSearchesByEntry(array $entryIds, int $userId): array<int, list<array{id: int, slug: string, term: string}>>` — every owned search each entry is a member of, ordered by sidebar order (search id DESC), keyed by entry id; entries in none are absent.

- [ ] **Step 1: Write the failing repository test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\SavedSearchEntryRepository;
use App\Tests\Support\KernelTestCase; // the repo's integration test base with a booted kernel + fixtures

final class SavedSearchMembershipLoaderTest extends KernelTestCase
{
    public function testReturnsEveryOwnedSearchPerEntryNewestFirst(): void
    {
        // Fixture: user U owns searches S1 (id lower) and S2 (id higher), both
        // with entry E as a member (insert saved_search_entry rows directly).
        [$userId, $entryId, $s1, $s2] = $this->seedTwoSearchesBothMatching();

        $repo = self::getContainer()->get(SavedSearchEntryRepository::class);
        $byEntry = $repo->savedSearchesByEntry([$entryId], $userId);

        self::assertArrayHasKey($entryId, $byEntry);
        // Sidebar order = id DESC, so the higher id (S2) comes first.
        self::assertSame([$s2['id'], $s1['id']], array_column($byEntry[$entryId], 'id'));
        self::assertSame($s2['slug'], $byEntry[$entryId][0]['slug']);
        self::assertSame($s2['term'], $byEntry[$entryId][0]['term']);
    }

    public function testExcludesAnotherUsersSearch(): void
    {
        [$userId, $entryId] = $this->seedOtherUserMatching();
        $repo = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertSame([], $repo->savedSearchesByEntry([$entryId], $userId));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Repository/SavedSearchMembershipLoaderTest.php`
Expected: FAIL — method `savedSearchesByEntry` not defined.

- [ ] **Step 3: Add the read (models on `firstMatchingSavedSearchIds`)**

In `SavedSearchEntryRepository`, add:

```php
/**
 * Entry id => every owned saved search it is a member of, in sidebar order
 * (search id DESC), each as {id, slug, term} — the pills a card shows. One
 * query for a page; entries in no search are absent.
 *
 * @param list<int> $entryIds
 *
 * @return array<int, list<array{id: int, slug: string, term: string}>>
 */
public function savedSearchesByEntry(array $entryIds, int $userId): array
{
    if ($entryIds === []) {
        return [];
    }

    /** @var list<array{entryId: int, id: int, slug: string, term: string}> $rows */
    $rows = $this->getEntityManager()->createQueryBuilder()
        ->select('IDENTITY(sse.entry) AS entryId', 'ss.id AS id', 'ss.slug AS slug', 'ss.term AS term')
        ->from(SavedSearchEntry::class, 'sse')
        ->join('sse.savedSearch', 'ss')
        ->andWhere('sse.entry IN (:entryIds)')
        ->andWhere('ss.user = :user')
        ->setParameter('entryIds', $entryIds)
        ->setParameter('user', $userId)
        ->orderBy('ss.id', 'DESC')
        ->getQuery()
        ->getScalarResult();

    $byEntry = [];
    foreach ($rows as $row) {
        $byEntry[(int) $row['entryId']][] = [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'term' => (string) $row['term'],
        ];
    }

    return $byEntry;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd backend && php bin/phpunit tests/Repository/SavedSearchMembershipLoaderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/SavedSearchEntryRepository.php backend/tests/Repository/SavedSearchMembershipLoaderTest.php
git commit -m "feat(#1118): read every owned saved search per entry in sidebar order"
```

---

### Task B2: `EntryListRow.savedSearches` + loader + `EntryJson`

**Files:**
- Modify: `backend/src/Repository/EntryListRow.php`, `backend/src/Http/EntryJson.php`
- Create: `backend/src/Repository/SavedSearchMembershipLoader.php`

**Interfaces:**
- Produces: `EntryListRow::$savedSearches` (`list<array{id,slug,term}>`, default `[]`) + `withSavedSearches()`; `SavedSearchMembershipLoader::loadInto(array $rows, int $userId): array` (mirrors `EntryCategoryLoader`); `EntryJson` emits `'savedSearches'`.
- Consumes: `SavedSearchEntryRepository::savedSearchesByEntry` (B1).

- [ ] **Step 1: Add `savedSearches` to `EntryListRow`**

Add a constructor-promoted param after `$categories` (line 38) — default `[]`, and a `withSavedSearches` mirroring `withCategories`, threading it through `copyWith`:

```php
        /** @var list<string> the feed-declared category labels, in declared order */
        public array $categories = [],
        /** @var list<array{id: int, slug: string, term: string}> owned saved searches this entry belongs to, sidebar order */
        public array $savedSearches = [],
    ) {
```

Update `withDuplicates`/`withCategories` to preserve `savedSearches`, add `withSavedSearches`, and extend `copyWith`:

```php
    public function withDuplicates(array $duplicates): self
    {
        return $this->copyWith($duplicates, $this->categories, $this->savedSearches);
    }

    /** @param list<string> $categories */
    public function withCategories(array $categories): self
    {
        return $this->copyWith($this->duplicates, $categories, $this->savedSearches);
    }

    /** @param list<array{id: int, slug: string, term: string}> $savedSearches */
    public function withSavedSearches(array $savedSearches): self
    {
        return $this->copyWith($this->duplicates, $this->categories, $savedSearches);
    }

    /**
     * @param list<self>                                       $duplicates
     * @param list<string>                                     $categories
     * @param list<array{id: int, slug: string, term: string}> $savedSearches
     */
    private function copyWith(array $duplicates, array $categories, array $savedSearches): self
    {
        return new self(
            $this->entry,
            new EntryListRowSubscription($this->subscriptionId, $this->subscriptionTitle),
            $this->isHidden,
            $this->isFavorite,
            $this->isKept,
            new EntryListRowViewState($this->isViewed, $this->viewedAt),
            $this->markedReadUntil,
            $duplicates,
            $categories,
            $savedSearches,
        );
    }
```

- [ ] **Step 2: Write the failing loader test (extend B1's test file)**

```php
public function testLoaderAttachesSavedSearchesToRows(): void
{
    [$userId, $entryId, $s1, $s2] = $this->seedTwoSearchesBothMatching();
    $rows = [$this->rowForEntry($entryId)]; // build an EntryListRow via the repo/hydrator

    $loader = self::getContainer()->get(SavedSearchMembershipLoader::class);
    $enriched = $loader->loadInto($rows, $userId);

    self::assertSame([$s2['id'], $s1['id']], array_column($enriched[0]->savedSearches, 'id'));
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Repository/SavedSearchMembershipLoaderTest.php`
Expected: FAIL — `SavedSearchMembershipLoader` not found.

- [ ] **Step 4: Implement the loader (mirror `EntryCategoryLoader`)**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Batch-loads, for a page of entry rows, the owned saved searches each entry
 * belongs to — one query, no N+1, exactly as EntryCategoryLoader does for
 * category labels. User-scoped, because membership is per user.
 */
final readonly class SavedSearchMembershipLoader
{
    public function __construct(private SavedSearchEntryRepository $memberships)
    {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function loadInto(array $rows, int $userId): array
    {
        if ($rows === []) {
            return [];
        }

        $byEntryId = $this->memberships->savedSearchesByEntry($this->entryIdsOf($rows), $userId);

        return array_map(
            fn (EntryListRow $row): EntryListRow => $this->enrich($row, $byEntryId),
            $rows,
        );
    }

    /**
     * @param array<int, list<array{id: int, slug: string, term: string}>> $byEntryId
     */
    private function enrich(EntryListRow $row, array $byEntryId): EntryListRow
    {
        $enrichedDuplicates = array_map(
            fn (EntryListRow $duplicate): EntryListRow => $this->enrich($duplicate, $byEntryId),
            $row->duplicates,
        );

        return $row
            ->withDuplicates($enrichedDuplicates)
            ->withSavedSearches($byEntryId[$row->entry->getId()] ?? []);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private function entryIdsOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row->entry->getId();
            if ($id !== null) {
                $ids[$id] = $id;
            }
            foreach ($row->duplicates as $duplicate) {
                $duplicateId = $duplicate->entry->getId();
                if ($duplicateId !== null) {
                    $ids[$duplicateId] = $duplicateId;
                }
            }
        }

        return array_values($ids);
    }
}
```

- [ ] **Step 5: Emit `savedSearches` in `EntryJson::commonFields`**

Add to the returned array in `commonFields` (after `'isViewed' => $row->isViewed,`):

```php
            'savedSearches' => $row->savedSearches,
```

Update the three `@return` docblocks in `EntryJson` (listRow, detail, commonFields) to add `savedSearches: list<array{id: int, slug: string, term: string}>,` — PHPStan level max requires the shapes to match.

- [ ] **Step 6: Run to verify it passes**

Run: `cd backend && php bin/phpunit tests/Repository/SavedSearchMembershipLoaderTest.php && composer stan`
Expected: PASS + PHPStan clean.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Repository/EntryListRow.php backend/src/Repository/SavedSearchMembershipLoader.php backend/src/Http/EntryJson.php backend/tests/Repository/SavedSearchMembershipLoaderTest.php
git commit -m "feat(#1118): carry saved-search membership on each entry row"
```

---

### Task B3: wire the loader into every entry-serving endpoint

**Files:**
- Modify: `backend/src/Controller/Api/EntryController.php`, `backend/src/Controller/Api/SavedSearchEntriesController.php`, `backend/src/Controller/Api/EntrySearchController.php`, and the for-you responder (`backend/src/Service/Recommendation/ForYouFeedResponder.php`).

**Interfaces:**
- Consumes: `SavedSearchMembershipLoader::loadInto(rows, userId)`.

- [ ] **Step 1: Write a failing functional assertion (one endpoint)**

Add to a list-endpoint functional test (grep an existing `GET /api/entries` test):

```php
public function testEntryListCarriesSavedSearchMembership(): void
{
    $this->loginAsSeededUser();
    // Seed an entry that is a member of one of the user's saved searches.
    $this->client->request('GET', '/api/entries');
    $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $entry = $body['entries'][0];
    self::assertArrayHasKey('savedSearches', $entry);
    self::assertSame('slug', array_key_first($entry['savedSearches'][0] ?? ['slug' => null]) ?: 'slug');
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit --filter testEntryListCarriesSavedSearchMembership`
Expected: FAIL — key absent (loader not wired).

- [ ] **Step 3: Inject + call the loader at each site**

`EntryController`: add `private SavedSearchMembershipLoader $savedSearchLoader,` to the constructor. In `list()` (line 94-98) wrap the rows:

```php
        $rows = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto($this->entryList->listForUser($query)),
            (int) $user->getId(),
        );

        return new JsonResponse(EntryPage::of($rows, $query->limit, EntryListSort::forView($view)));
```

In `get()` (line 108) wrap the single row:

```php
        $row = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto([$row]),
            (int) $user->getId(),
        )[0];
```

`SavedSearchEntriesController`: inject the loader; in both `list()` and the new `one()` (A4), wrap `$result->withRows($this->categoryLoader->loadInto($result->rows))` with a second `->withRows($this->savedSearchLoader->loadInto(..., $userId))`, e.g.:

```php
        $rows = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto($result->rows),
            $userId,
        );

        return new JsonResponse(SavedSearchPage::of($result->withRows($rows), $query->limit));
```

`EntrySearchController` and `ForYouFeedResponder`: same pattern — wherever `categoryLoader->loadInto(...)` runs before serialization, add `savedSearchLoader->loadInto(..., $userId)`. (Grep both files for `categoryLoader` / `loadInto`; add the membership loader at each.)

- [ ] **Step 4: Run to verify it passes (all touched endpoints)**

Run: `cd backend && php bin/phpunit --group entries --group search` (or the relevant suites) then `composer stan`
Expected: PASS + clean. Add a `savedSearches` assertion to the search and saved-search functional tests too.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controller/Api/EntryController.php backend/src/Controller/Api/SavedSearchEntriesController.php backend/src/Controller/Api/EntrySearchController.php backend/src/Service/Recommendation/ForYouFeedResponder.php backend/tests
git commit -m "feat(#1118): attach saved-search membership on every entry list"
```

---

### Task B4: delete the backend provenance mechanism

**Files:**
- Modify: `backend/src/Http/SavedSearchPage.php`, `backend/src/Service/Search/SavedSearchEntries.php`, `backend/src/Service/Search/SavedSearchEntriesResult.php`, `backend/src/Repository/SavedSearchEntryRepository.php`.

**Interfaces:**
- Removes: `SavedSearchPage`'s `savedSearchIds` field (collapse to `EntryPage::of`), `SavedSearchEntriesResult::$savedSearchIds`, `SavedSearchEntries` building it, `SavedSearchEntryRepository::firstMatchingSavedSearchIds`.

- [ ] **Step 1: Collapse `SavedSearchPage`**

Since membership now rides on each entry, the combined list no longer needs a top-level map. Replace `SavedSearchPage::of` body with a straight delegation and update the return docblock:

```php
    /**
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function of(SavedSearchEntriesResult $result, int $limit): array
    {
        return EntryPage::of($result->rows, $limit, EntryListSort::PublishedDate);
    }
```

(Add `use App\Http\EntryPage;` if needed; drop the `(object)` cast import/comment.)

- [ ] **Step 2: Drop provenance from the service + result**

In `SavedSearchEntries::list`, stop computing `firstMatchingSavedSearchIds`:

```php
    public function list(SavedSearchListQuery $query): SavedSearchEntriesResult
    {
        return new SavedSearchEntriesResult(rows: $this->entries->listMembers($query));
    }
```

In `SavedSearchEntriesResult`, remove the `savedSearchIds` constructor property and any `withRows` handling of it (keep `rows` + `withRows`).

- [ ] **Step 3: Delete `firstMatchingSavedSearchIds`**

Remove the method (lines 131-168) from `SavedSearchEntryRepository`. Grep the codebase for any other caller first: `grep -rn firstMatchingSavedSearchIds backend/src backend/tests`. Remove/adjust the corresponding tests.

- [ ] **Step 4: Run the suite**

Run: `cd backend && php bin/phpunit && composer stan && composer md`
Expected: PASS + clean (fix any test that asserted `savedSearchIds` in the combined-list response — it now asserts `savedSearches` on the entries).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Http/SavedSearchPage.php backend/src/Service/Search/SavedSearchEntries.php backend/src/Service/Search/SavedSearchEntriesResult.php backend/src/Repository/SavedSearchEntryRepository.php backend/tests
git commit -m "refactor(#1118): drop the single-value saved-search provenance map"
```

---

### Task B5: frontend model — `savedSearches` on the entry, remove provenance types

**Files:**
- Modify: `frontend/src/app/reader/models.ts`

**Interfaces:**
- Produces: `EntryDto.savedSearches?: SavedSearchMembershipDto[]` where `SavedSearchMembershipDto = { id: number; slug: string; term: string }`. Removes `EntryDto.savedSearchTerm` and `EntriesPage.savedSearchIds`.

- [ ] **Step 1: Add the membership type and field; remove provenance**

Add near `EntryDto`:

```ts
export interface SavedSearchMembershipDto {
  id: number;
  /** The saved search's slug, for the pill's link. */
  slug: string;
  /** The saved search's term, shown on the pill. */
  term: string;
}
```

In `EntryDto`, remove lines 202-204 (`savedSearchTerm?`) and add:

```ts
  /** Owned saved searches this entry is a member of, in sidebar order. Always
   *  sent by the API; empty when the entry matches none. Drives the pills. */
  savedSearches?: SavedSearchMembershipDto[];
```

In `EntriesPage`, remove lines 224-226 (`savedSearchIds?`).

- [ ] **Step 2: Typecheck**

Run: `docker compose exec -T frontend npx tsc --noEmit -p frontend/tsconfig.app.json` (or the project's typecheck script)
Expected: FAIL at the provenance consumers (entries.store, entry-row template, kicker) — fixed in B6/B8. This confirms the removals are load-bearing.

- [ ] **Step 3: Commit (after B6/B8 compile clean — or stage together)**

> This task compiles green only once B6 and B8 land. Execute B5→B6→B8 as a unit, then commit together, or keep `savedSearchTerm` until B8 and delete in one commit. Recommended: do B5+B6+B8 then:

```bash
git add frontend/src/app/reader/models.ts
git commit -m "feat(#1118): type saved-search membership on the entry DTO"
```

---

### Task B6: delete provenance from `EntriesStore`

**Files:**
- Modify: `frontend/src/app/reader/entries.store.ts`
- Test: `frontend/src/app/reader/entries.store.spec.ts`

**Interfaces:**
- Removes: `savedSearchIdsByEntryId`, `termsBySavedSearchId`, the `entries` remapping, and the `SavedSearchesStore`/`visibleSearchTerm` imports if now unused. `entries` becomes the raw list (membership already lives on each DTO from the API).

- [ ] **Step 1: Replace the `entries` computed and remove the maps**

Replace the block (lines 44-71) so `entries` is the raw signal enriched only by in-flight patches already applied in `rawEntries`:

```ts
  private readonly api = inject(ReaderApi);

  private readonly rawEntries = signal<EntryDto[]>([]);
  private readonly inFlightPatches = new Set<InFlightPatch>();
  readonly entries = this.rawEntries.asReadonly();
```

Delete `sameTermsById` (lines 27-33) if unused elsewhere. Remove the `SavedSearchesStore` inject, `savedSearchIdsByEntryId`, `termsBySavedSearchId`, and the `visibleSearchTerm`/`SavedSearchesStore` imports (lines 7-8) if now unused.

- [ ] **Step 2: Remove the `savedSearchIds` reads in `load`/`loadMore`**

Delete line 110 (`this.savedSearchIdsByEntryId.set(page.savedSearchIds ?? {});`), line 120 (the error-path reset), and line 147 (`this.savedSearchIdsByEntryId.update(...)`).

- [ ] **Step 3: Update the store spec**

Remove/adjust `entries.store.spec.ts` cases that asserted `savedSearchTerm` was injected from `savedSearchIds`. The store no longer derives it; membership is on the wire DTO.

- [ ] **Step 4: Run the spec**

Run: `docker compose exec -T frontend npx jest src/app/reader/entries.store.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit (with B5/B8 — see B5 note)**

```bash
git add frontend/src/app/reader/entries.store.ts frontend/src/app/reader/entries.store.spec.ts
git commit -m "refactor(#1118): drop store-side saved-search provenance"
```

---

### Task B7: `SavedSearchPillsComponent`

**Files:**
- Create: `frontend/src/app/reader/saved-search-pills/saved-search-pills.component.ts` + `.html` + `.scss`
- Test: `frontend/src/app/reader/saved-search-pills/saved-search-pills.component.spec.ts`

**Interfaces:**
- Produces: `<app-saved-search-pills [memberships]="entry().savedSearches ?? []" />`. Each pill: `saved_search` glyph, neutral colour, `term` label (ellipsized), links to `['/searches/saved', slug]`, `stopPropagation` on click, wraps like tag pills. Renders nothing when empty.

- [ ] **Step 1: Write the failing spec**

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SavedSearchPillsComponent } from './saved-search-pills.component';

describe('SavedSearchPillsComponent', () => {
  let fixture: ComponentFixture<SavedSearchPillsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [SavedSearchPillsComponent],
      providers: [provideRouter([])],
    }).compileComponents();
    fixture = TestBed.createComponent(SavedSearchPillsComponent);
  });

  it('renders one pill per membership, linked to the slug path', () => {
    fixture.componentRef.setInput('memberships', [
      { id: 2, slug: '2-climate', term: 'climate' },
      { id: 1, slug: '1-sport', term: 'sport' },
    ]);
    fixture.detectChanges();
    const links = fixture.nativeElement.querySelectorAll('a.pill');
    expect(links.length).toBe(2);
    expect(links[0].getAttribute('href')).toContain('/reader/searches/saved/2-climate');
    expect(links[0].textContent).toContain('climate');
  });

  it('renders nothing when there are no memberships', () => {
    fixture.componentRef.setInput('memberships', []);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('a.pill')).toBeNull();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/saved-search-pills`
Expected: FAIL — component missing.

- [ ] **Step 3: Implement the component**

`saved-search-pills.component.ts`:

```ts
import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { SavedSearchMembershipDto } from '../models';

/**
 * The pills a card shows for the saved searches an entry belongs to — a
 * neutral, search-glyphed sibling of the feed tag pills, each linking to that
 * saved search. Clicks stop propagating so a pill inside a clickable card opens
 * the search, not the entry. Renders nothing when the entry matches none.
 */
@Component({
  selector: 'app-saved-search-pills',
  imports: [RouterLink, IconComponent],
  templateUrl: './saved-search-pills.component.html',
  styleUrl: './saved-search-pills.component.scss',
})
export class SavedSearchPillsComponent {
  readonly memberships = input.required<SavedSearchMembershipDto[]>();
}
```

`saved-search-pills.component.html`:

```html
@if (memberships().length) {
  <span class="pills">
    @for (m of memberships(); track m.id) {
      <a
        class="pill"
        [routerLink]="['/searches/saved', m.slug]"
        [attr.title]="m.term"
        (click)="$event.stopPropagation()"
      >
        <app-icon name="saved_search" size="xs" />
        <span class="name">{{ m.term }}</span>
      </a>
    }
  </span>
}
```

`saved-search-pills.component.scss` (mirror `source-tags.component.scss`; neutral tokens, no hex, no px literals):

```scss
:host {
  display: block;
  min-width: 0;
}

.pills {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  min-width: 0;
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  min-width: 0;
  padding: var(--space-0) var(--space-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-pill);
  background: var(--surface-1);
  color: var(--text-secondary);
  font-size: var(--fs-xs, 0.72rem);
  line-height: 1.5;
  text-decoration: none;
  cursor: pointer;
}

.pill:hover {
  border-color: var(--border-strong);
  color: var(--text-primary);
}

.name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/saved-search-pills`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/saved-search-pills/
git commit -m "feat(#1118): add the saved-search membership pill component"
```

---

### Task B8: render the pills in list, magazine, and detail; remove the old pill

**Files:**
- Modify: `frontend/src/app/reader/entry-row/entry-row.component.ts` + `.html` + `.scss`, `frontend/src/app/reader/entry-meta/entry-meta.component.ts` + `.html`, `frontend/src/app/reader/magazine/entry-kicker-line.component.html`, `frontend/src/app/reader/reader-view/reader-view.component.html`.

**Interfaces:**
- Consumes: `SavedSearchPillsComponent`, `EntryDto.savedSearches`.

- [ ] **Step 1: List view — entry-row**

In `entry-row.component.ts` imports, add `SavedSearchPillsComponent`. In `entry-row.component.html`:
- Remove the old provenance pill (lines 22-24, the `@if (entry().savedSearchTerm; ...)` span).
- In `.tagline` (lines 33-42), add the pills after `<app-source-tags>`:

```html
    <div class="tagline">
      <app-source-tags [tags]="tags()" />
      <app-saved-search-pills [memberships]="entry().savedSearches ?? []" />
      <app-entry-actions
        [entry]="entry()"
        size="md"
        (favorite)="favorite.emit($event)"
        (keep)="keep.emit($event)"
        (read)="read.emit($event)"
      />
    </div>
```

In `entry-row.component.scss`, remove the `.saved-search-pill` rule (lines 30-36); add, if needed for the flex line, `.tagline app-saved-search-pills { min-width: 0; }` beside the existing `.tagline app-source-tags` rule.

- [ ] **Step 2: Magazine — entry-meta + kicker**

In `entry-meta.component.ts` imports add `SavedSearchPillsComponent`; in `entry-meta.component.html` add the pills after `<app-source-tags>`:

```html
<app-source-tags [tags]="tags()" />
<app-saved-search-pills [memberships]="entry().savedSearches ?? []" />
<app-entry-actions
  [entry]="entry()"
  (favorite)="favorite.emit($event)"
  (keep)="keep.emit($event)"
  (read)="read.emit($event)"
/>
```

In `entry-kicker-line.component.html`, remove the old provenance pill (lines 19-21, the `@if (entry().savedSearchTerm; ...)` span). (The kicker keeps the `<ng-content />`.)

- [ ] **Step 3: Detail — reader-view**

In `reader-view.component.html`, after the `<app-source-tags class="tags" [tags]="tags()" />` (line 127), add:

```html
        <app-saved-search-pills [memberships]="(entry()?.savedSearches) ?? []" />
```

Add `SavedSearchPillsComponent` to `reader-view.component.ts` imports.

- [ ] **Step 4: Typecheck + specs across the changed components**

Run: `docker compose exec -T frontend npx jest src/app/reader/entry-row src/app/reader/magazine src/app/reader/reader-view src/app/reader/entry-meta`
Expected: PASS (update any snapshot/spec that referenced the old `saved-search-pill` span; assert `app-saved-search-pills` renders and the old span is gone).

- [ ] **Step 5: Remove the now-unused `.saved-search-pill` utility**

Grep: `grep -rn "saved-search-pill" frontend/src`. If nothing references it, remove the block from `frontend/src/app/theme/_utilities.scss`. If something still does, leave it.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/entry-row/ frontend/src/app/reader/entry-meta/ frontend/src/app/reader/magazine/entry-kicker-line.component.html frontend/src/app/reader/reader-view/reader-view.component.html frontend/src/app/theme/_utilities.scss
git commit -m "feat(#1118): show saved-search pills on every entry card and detail"
```

---

### Task B9: Phase B full frontend gate

- [ ] **Step 1: Run the frontend CI gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all pass. Fix Prettier 100-col and Stylelint hex/px findings inline.

- [ ] **Step 2: Visual smoke**

In the dev stack, open a list where an entry matches a saved search; confirm the neutral `saved_search` pill sits after the tag pills, wraps, links to `/reader/searches/saved/<slug>`, and appears in list, magazine, and the opened article.

---

# PHASE C — Verification & gates (before `/simplify` and PR)

### Task C1: backend full verification

- [ ] **Step 1: SQLite suite**

Run: `cd backend && php bin/phpunit`
Expected: green.

- [ ] **Step 2: MySQL suite (Docker)**

Run: `docker compose exec php composer test`
Expected: green.

- [ ] **Step 3: Static gates**

Run: `cd backend && composer check && composer md`
Expected: PSR-12, PHPStan level max, phptramp, PHPMD all clean on touched files. (If phptramp is red, check `composer show larspohlmann/phptramp` first — CI runs its `develop` tip.)

- [ ] **Step 4: Migration-from-empty both dialects + schema validate**

Run: SQLite — `cd backend && rm -f var/data_test.db && php bin/console doctrine:migrations:migrate --no-interaction --env=test && php bin/console doctrine:schema:validate --env=test`; MySQL — `docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php php bin/console doctrine:schema:validate`
Expected: clean.

- [ ] **Step 5: PhpStorm inspections on changed PHP**

Run `mcp__phpstorm__lint_files` on the changed backend files.
Expected: no ERROR/WARNING.

- [ ] **Step 6: Dev log scan**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .`
Expected: no new deprecations/errors from this work.

### Task C2: mutation gate

- [ ] **Step 1: Infection over the diff**

Run: `cd backend && composer infection:diff`
Expected: meets `minMsi` in `infection.json5`. Escaped mutants arrive as annotations; add tests to kill them (especially in `SavedSearchSlug`, `savedSearchesByEntry` ordering, and `selectionFromRoute` branches — mirror the id-parse and ordering assertions).

- [ ] **Step 2: Prove test isolation for any parallel run**

If running Infection/parallel with `TEST_TOKEN`, confirm `infection --noop` leaves every noop mutant surviving (a reported kill means broken isolation).

### Task C3: self-review against the spec

- [ ] Confirm each Global Constraint maps to a landed task: slug format (A1/A2), routes + matcher (A7/A8), single-search by id from membership (A4/A6), pill spec (B7/B8), `{id,slug,term}` payload (B1/B2/B5), provenance deletion (B4/B6), old-bookmark breakage accepted (no compat task by design), pills everywhere incl. detail (B8).

---

## Post-plan pipeline (outside the task list, per the requested workflow)

1. Run `/simplify` on the branch diff; apply the quality cleanups.
2. Push the branch; open a PR into `develop` with body `Closes #1118`.
3. Monitor CI; merge only when green.
4. After merge + green on the merge SHA, deploy to Strato by pushing the next `vX.Y.Z-dev.N` tag on the `develop` merge commit (tag-triggered `deploy-strato.yml`).
