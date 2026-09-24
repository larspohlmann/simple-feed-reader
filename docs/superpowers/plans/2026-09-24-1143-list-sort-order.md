# Per-view list sort order (#1143) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every date-ordered entry list can be flipped between newest first and oldest first, remembered per view and per user on the device, and "mark everything above as read" follows the displayed order.

**Architecture:** The backend takes an explicit `order=asc|desc` on the three list endpoints. The existing query objects carry it into one `EntryListOrdering` value that drives ORDER BY, the keyset cursor predicate, the tag window (#1099) and the Meilisearch sort. The frontend keeps per-user values in a `localStorage` namespace (`UserDeviceStorage`, keyed by the account id from a new `userId` JWT claim, falling back to `/api/me`). The namespace holds the unread filter and the set of oldest-first view keys. `ListPreferences` applies both to the URL-derived `Selection`, which now carries `order`, and the list reloads when it changes. A separate bug fix, needed for oldest-first search, puts `sort` first in Meilisearch's ranking rules.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM, Meilisearch v1.13, Angular 20 (standalone, signals), Jest, Playwright.

**Spec:** `docs/superpowers/specs/2026-09-24-1143-list-sort-order-design.md` and issue #1143. Read both first.

## Global Constraints

- API parameter `order`: `desc` (default, also when absent or empty) or `asc`. Any other value → HTTP 422, problem type `validation_error`, `errors` exactly `{"order": ["Unknown order. Use one of: desc, asc."]}`.
- Oldest first is the exact reverse of today: `effectiveDate ASC, id ASC`; the viewed history `viewedAt ASC, id ASC`.
- For You never gets the toggle. `GET /api/entries?view=for-you&order=asc` answers 200 and ignores the order. An invalid `order` still 422s there.
- JWT claim name `userId` (int).
- Device storage key: `sfr.user.<userId>.<name>`. Names: `unread-only` (value `'1'`; absent means all posts) and `oldest-first-views` (a JSON array of view keys, sorted; absent means none). Flipping back to the default removes the key. The legacy `sfr.unread-only` key is deleted and never read.
- View keys: `all`, `favorites`, `kept`, `viewed`, `saved-searches`, `search` (one for every term), `tag:<id>`, `subscription:<id>`, `saved-search:<id>`.
- Header control: an `appListAction` `<button>`, class `list-order`, placed directly before the unread switch.
  - Icon: `arrow_downward` (newest first) / `arrow_upward` (oldest first).
  - Labels: en "Newest first" / "Oldest first", de "Neueste zuerst" / "Älteste zuerst".
- Commits: `type(#1143): lower-case summary`, one per task at least. Branch `feature/1143-list-sort-order` (already created off `develop`).
- CLAUDE.md is binding. In particular:
  - Clean Code; `final readonly` by default; ≤3 params.
  - No comments unless a future reader would get the code wrong without one; one line, three at most.
  - PSR-12, 120-column PHP; Prettier 100 columns; no hex colours or raw `px` in `.scss`.
- Frontend Jest runs inside Docker: `docker compose exec -T frontend npm test -- <paths>`. Never run two Jest processes at once; the container OOMs.

## Every task, every implementer

- **Prove each new test can fail.** Delete or revert the production line it covers, run the test, and paste the failure. Restore it, run again, and paste the pass. A test nobody has seen fail is not evidence. Restore with an edit, never `git checkout --`.
- **Report, do not redesign.** If the plan is wrong (wrong API, a test value that can't fail, a missed caller), say so in the report with the evidence. Fix it minimally and amend this plan in-branch, so later tasks inherit the correction.
- End the report with `DONE` or `DONE_WITH_CONCERNS` and the findings.
- **Backend gates for touched PHP files:**
  - `composer cs`
  - `bin/console cache:warmup && composer stan`
  - `vendor/bin/phpmd <file> text phpmd.xml.dist` for each touched `src` file
  - `composer tramp`
  - `php bin/phpunit` (SQLite)

  Before its commit, every touched `src` file must be PHPMD-clean.
- **Frontend gates:** `npx prettier --write <touched files>`, then the focused Jest run in Docker. Tasks 10–12 also run `docker compose exec -T frontend npm run check`.
- **Concurrent sessions share this checkout.** Check `git status` and `git branch --show-current` before any checkout, reset or stash.

## File map

| File | Responsibility | Task |
|---|---|---|
| `backend/src/Service/Search/Index/MeilisearchIndex.php` | `rankingRules` with `sort` first; asc sort and cursor filter | 1, 5 |
| `backend/src/Enum/ListOrder.php` (new) | The two orders: request parsing, SQL direction, comparisons, `arrange()` | 2 |
| `backend/src/Repository/EntryListOrdering.php` (new) | Sort column + order, one value for ORDER BY and cursor | 2 |
| `backend/src/Repository/AbstractEntryProjectionRepository.php` | `orderedBy()` / `applyCursor()` take `EntryListOrdering` | 2 |
| `backend/src/Repository/EntryQuery.php` | `order` field, `ordering()` | 2 |
| `backend/src/Repository/EntryListRepository.php` | `listForUser` and `searchForUser` use the query's ordering | 2, 5 |
| `backend/src/Repository/DateOrderedPage.php` | Tag window mirrored for ascending | 3 |
| `backend/src/Controller/Api/EntryController.php` | `order` query parameter | 2 |
| `backend/src/Repository/SavedSearchListQuery.php`, `SavedSearchEntryRepository.php`, `Controller/Api/SavedSearchEntriesController.php` | Saved-search lists take the order | 4 |
| `backend/src/Repository/EntrySearchQuery.php`, `Service/Search/EntrySearchRequestFactory.php`, `Service/Search/Index/IndexSearch.php`, `Service/Search/IndexedEntrySearch.php` | Search takes the order | 5 |
| `backend/src/EventListener/AddUserIdClaimOnTokenIssue.php` (new) | `userId` claim | 6 |
| `frontend/src/app/core/account-identity.ts` (new) | The signed-in account's id: claim, else `/api/me` | 7 |
| `frontend/src/app/core/user-device-storage.ts` (new) | Per-user `localStorage` namespace | 7 |
| `frontend/src/app/settings/account-section.component.ts` | Forget the namespace on account delete | 7 |
| `frontend/src/app/reader/unread-filter.service.ts` | Unread filter on the per-user namespace | 8 |
| `frontend/e2e/list-scroll-reset.spec.ts`, `saved-searches-combined.spec.ts` | Stop seeding/clearing the legacy device-wide key | 8 |
| `frontend/src/app/reader/models.ts`, `query.ts`, `reader-api.ts`, `list-scroll-memory.ts` | `ListOrder`, `Selection.order`, the `order` param, the scroll key | 9 |
| `frontend/src/app/reader/entry-list/entry-list.component.{ts,html}`, `public/i18n/{en,de}.json` | The header toggle | 10 |
| `frontend/src/app/reader/list-order.service.ts` (new), `list-preferences.service.ts` (new), `reader-shell.component.{ts,html}`, `list-scroll-reset.ts`, `core/auth.service.ts`, `core/account-identity.ts`, `core/user-device-storage.ts` | Remembering the order, applying preferences, the load gate (opens on a failed `/api/me` too) | 11 |
| `frontend/src/app/reader/magazine/magazine-planner.ts` | Collapse window anchored on the first entry | 12 |
| `frontend/e2e/list-order.spec.ts` (new) + two stub fixes | End-to-end proof, mark-above included | 13 |

---

### Task 1: Meilisearch ranks by date before relevance (existing paging bug)

`sort` currently sits fifth in the default ranking rules, so relevance picks each page. `IndexedEntrySearch` resumes past the oldest hydrated row, so newer, lower-ranked matches are skipped forever. The spec has the measurements. This task is the fix, and it lands first because oldest-first search depends on it.

**Files:**
- Modify: `backend/src/Service/Search/Index/MeilisearchIndex.php` (the `SETTINGS` const and its docblock, ~lines 55–84)
- Modify: `backend/tests/Service/Search/Index/MeilisearchIndexTest.php` (`testConfigureSendsTheSettingsToThePatchEndpoint`, ~line 394)
- Modify: `docs/meilisearch-wire-format.md` ("Index settings the adapter applies")

**Interfaces:** Produces: the index settings carry `rankingRules: ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness']`.

- [ ] **Step 1: Measure the bug on the dev index** (Docker stack up):

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
PROBE=$(cat <<'PY'
import json, sys
hits = json.load(sys.stdin)['hits']
page = hits[:100]
inversions = sum(1 for a, b in zip(page, page[1:]) if (a['effectiveDate'], a['id']) < (b['effectiveDate'], b['id']))
last = min((hit['effectiveDate'], hit['id']) for hit in page) if page else None
skipped = sum(1 for hit in hits[100:] if last and (hit['effectiveDate'], hit['id']) > last)
print(sys.argv[1], 'matches', len(hits), 'inversions', inversions, 'skipped', skipped)
PY
)
for q in "climate change" "berlin police"; do
  docker compose exec -T php sh -c "curl -s -H \"Authorization: Bearer \$MEILISEARCH_KEY\" -H 'Content-Type: application/json' -X POST \"\$MEILISEARCH_URL/indexes/entries/search\" -d '{\"q\":\"$q\",\"sort\":[\"effectiveDate:desc\",\"id:desc\"],\"matchingStrategy\":\"all\",\"limit\":1000,\"attributesToRetrieve\":[\"id\",\"effectiveDate\"]}'" | python3 -c "$PROBE" "$q"
done
```

Expected: a non-zero `inversions` and `skipped` for at least one term. On 2026-09-24 "climate change" measured 17 inversions and 705 skipped. If the dev index has no hits for these terms, pick two multi-word terms that return more than 100 hits.

- [ ] **Step 2: Write the failing test.** In `testConfigureSendsTheSettingsToThePatchEndpoint`, delete the six-line comment above the `searchableAttributes` assertion; with `sort` first it no longer describes a ranking contract. Then add after the `sortableAttributes` assertion:

```php
        self::assertSame(
            ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness'],
            $decoded['rankingRules'],
        );
```

- [ ] **Step 3: Run it and watch it fail**

Run: `cd backend && php bin/phpunit --filter testConfigureSendsTheSettingsToThePatchEndpoint`
Expected: FAIL (undefined array key `rankingRules`).

- [ ] **Step 4: Implement.** In `MeilisearchIndex`:
  - Replace the docblock paragraph starting "The ORDER of that list is a behavioural contract" (through "…riding in on the feed's own name.") with the three lines below.
  - Add `rankingRules: list<string>,` to the `@var array{…}` shape.
  - Add the entry to `SETTINGS`.

```php
     * `sort` leads `rankingRules`: the keyset cursor pages by (effectiveDate, id), so a
     * page must be the next rows in that order. A relevance rule ahead of it lets an
     * old title match onto page one, and the cursor then skips every newer match.
```

```php
    private const array SETTINGS = [
        'searchableAttributes' => ['title', 'summary', 'content', 'feedTitle'],
        'filterableAttributes' => ['feedId', 'effectiveDate', 'id'],
        'sortableAttributes' => ['effectiveDate', 'id'],
        'rankingRules' => ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness'],
    ];
```

- [ ] **Step 5: Run the test file and watch it pass**

Run: `cd backend && php bin/phpunit tests/Service/Search/Index/MeilisearchIndexTest.php`
Expected: PASS.

- [ ] **Step 6: Apply the settings to the dev index and re-measure.** `EntryIndexer::configureOnce()` re-applies settings on the first index write of each process in production. Here, apply them directly:

```bash
docker compose exec -T php sh -c "curl -s -X PATCH -H \"Authorization: Bearer \$MEILISEARCH_KEY\" -H 'Content-Type: application/json' \"\$MEILISEARCH_URL/indexes/entries/settings\" -d '{\"rankingRules\":[\"sort\",\"words\",\"typo\",\"proximity\",\"attribute\",\"exactness\"]}'"
docker compose exec -T php sh -c "curl -s -H \"Authorization: Bearer \$MEILISEARCH_KEY\" \"\$MEILISEARCH_URL/tasks?limit=1\""
```

Repeat the second command until the task `status` is `succeeded`, then re-run Step 1.
Expected: `inversions 0 skipped 0` for every term. Paste both runs, before and after, into the report.

- [ ] **Step 7: Update the doc.** In `docs/meilisearch-wire-format.md`:
  - In the settings JSON block, add `"rankingRules": ["sort", "words", "typo", "proximity", "attribute", "exactness"]`.
  - Replace the paragraph "`searchableAttributes`' order is a behavioural contract…" with:

```markdown
`rankingRules` puts `sort` first (#1143). The adapter pages with a keyset cursor
on `(effectiveDate, id)`, so every page must be the next rows in that order.
With Meilisearch's default rules (`words, typo, proximity, attribute, sort,
exactness`), relevance chose each page and the cursor, resuming past the oldest
row of page one, skipped every newer match that ranked lower — measured on
2026-09-24 as 705 of 812 matches for "climate change". The reader shows every
page in date order anyway, so nothing visible was lost by demoting relevance.
```

- [ ] **Step 8: Gates and commit**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan
vendor/bin/phpmd src/Service/Search/Index/MeilisearchIndex.php text phpmd.xml.dist
git add src/Service/Search/Index/MeilisearchIndex.php tests/Service/Search/Index/MeilisearchIndexTest.php ../docs/meilisearch-wire-format.md
git commit -m "fix(#1143): rank search hits by date before relevance so paging never skips"
```

---

### Task 2: `ListOrder`, `EntryListOrdering` and oldest first on `GET /api/entries`

**Files:**
- Create: `backend/src/Enum/ListOrder.php`
- Create: `backend/src/Repository/EntryListOrdering.php`
- Modify: `backend/src/Repository/AbstractEntryProjectionRepository.php` (`newestFirst`, `orderedBy`, `applyCursor`)
- Modify: `backend/src/Repository/EntryQuery.php`
- Modify: `backend/src/Repository/EntryListRepository.php` (`listForUser`; `searchForUser`'s `applyCursor` call compiles against the new signature)
- Modify: `backend/src/Repository/SavedSearchEntryRepository.php` (`listMembers`' `applyCursor` call, compile only)
- Modify: `backend/src/Controller/Api/EntryController.php` (`list`)
- Create: `backend/tests/Enum/ListOrderTest.php`
- Modify: `backend/tests/Repository/EntryQueryTest.php`, `backend/tests/Repository/EntryListTest.php`, `backend/tests/Controller/Api/EntryControllerTest.php`

**Interfaces:**
- Produces:
  - `App\Enum\ListOrder` (string-backed: `NewestFirst = 'desc'`, `OldestFirst = 'asc'`) with:
    - `static fromRequestValue(?string): self` (throws `ValidationException`)
    - `sqlDirection(): string`
    - `strictlyAfter(): string`
    - `atOrBefore(): string`
    - `arrange(list<T>): list<T>`
  - `App\Repository\EntryListOrdering(EntryListSort $sort, ListOrder $order = NewestFirst)`.
  - `EntryQuery::$order` (last constructor parameter) and `EntryQuery::ordering(): EntryListOrdering`.
  - `AbstractEntryProjectionRepository::orderedBy(QueryBuilder, EntryListOrdering)` and `applyCursor(QueryBuilder, ?EntryCursor, EntryListOrdering)`.

Note: the tag window (`DateOrderedPage`) is mirrored in Task 3. Until then, an ascending tag list is not correct; no test in this task covers one.

- [ ] **Step 1: Write the failing enum test** — `backend/tests/Enum/ListOrderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ListOrder;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ListOrderTest extends TestCase
{
    public function testAnAbsentOrEmptyValueIsNewestFirst(): void
    {
        self::assertSame(ListOrder::NewestFirst, ListOrder::fromRequestValue(null));
        self::assertSame(ListOrder::NewestFirst, ListOrder::fromRequestValue(''));
    }

    public function testDescAndAscNameTheTwoOrders(): void
    {
        self::assertSame(ListOrder::NewestFirst, ListOrder::fromRequestValue('desc'));
        self::assertSame(ListOrder::OldestFirst, ListOrder::fromRequestValue('asc'));
    }

    public function testAnyOtherValueIsAValidationErrorOnTheOrderField(): void
    {
        try {
            ListOrder::fromRequestValue('ASC');
            self::fail('An unknown order must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(['order' => ['Unknown order. Use one of: desc, asc.']], $exception->errors);
        }
    }

    public function testEachOrderNamesItsSqlDirectionAndComparisons(): void
    {
        $newest = ListOrder::NewestFirst;
        $oldest = ListOrder::OldestFirst;

        self::assertSame(['DESC', '<', '>='], [$newest->sqlDirection(), $newest->strictlyAfter(), $newest->atOrBefore()]);
        self::assertSame(['ASC', '>', '<='], [$oldest->sqlDirection(), $oldest->strictlyAfter(), $oldest->atOrBefore()]);
    }

    public function testArrangeKeepsANewestFirstListOrReversesItForOldestFirst(): void
    {
        self::assertSame([30, 20, 10], ListOrder::NewestFirst->arrange([30, 20, 10]));
        self::assertSame([10, 20, 30], ListOrder::OldestFirst->arrange([30, 20, 10]));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Enum/ListOrderTest.php`
Expected: FAIL (class `App\Enum\ListOrder` not found).

- [ ] **Step 3: Implement the enum** — `backend/src/Enum/ListOrder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum ListOrder: string
{
    case NewestFirst = 'desc';
    case OldestFirst = 'asc';

    /**
     * @throws ValidationException when the value names neither order
     */
    public static function fromRequestValue(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::NewestFirst;
        }

        return self::tryFrom($value)
            ?? throw new ValidationException(['order' => ['Unknown order. Use one of: desc, asc.']]);
    }

    public function sqlDirection(): string
    {
        return match ($this) {
            self::NewestFirst => 'DESC',
            self::OldestFirst => 'ASC',
        };
    }

    /** How a later row's instant compares to an earlier row's in this order. */
    public function strictlyAfter(): string
    {
        return match ($this) {
            self::NewestFirst => '<',
            self::OldestFirst => '>',
        };
    }

    /** How an instant at or before a bound in this order compares to the bound. */
    public function atOrBefore(): string
    {
        return match ($this) {
            self::NewestFirst => '>=',
            self::OldestFirst => '<=',
        };
    }

    /**
     * @template T
     *
     * @param list<T> $newestFirst
     *
     * @return list<T>
     */
    public function arrange(array $newestFirst): array
    {
        return $this === self::OldestFirst ? array_reverse($newestFirst) : $newestFirst;
    }
}
```

- [ ] **Step 4: Run the enum test** — Expected: PASS.

- [ ] **Step 5: Write the failing repository and query tests.**

In `backend/tests/Repository/EntryQueryTest.php`, add these imports: `use App\Enum\ListOrder;` and `use App\Repository\EntryListSort;`. Then add:

```php
    public function testTheOrderingPairsTheViewsSortWithTheRequestedOrder(): void
    {
        $viewed = (new EntryQuery(1, 'viewed', order: ListOrder::OldestFirst))->ordering();
        self::assertSame(EntryListSort::ViewedAt, $viewed->sort);
        self::assertSame(ListOrder::OldestFirst, $viewed->order);

        $all = (new EntryQuery(1, 'all'))->ordering();
        self::assertSame(EntryListSort::PublishedDate, $all->sort);
        self::assertSame(ListOrder::NewestFirst, $all->order);
    }
```

In `backend/tests/Repository/EntryListTest.php`, add `use App\Enum\ListOrder;` and two private helpers after `joinPrefixQueries()`:

```php
    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<string>
     */
    private function guids(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): string => $row->entry->getGuid(), $rows);
    }

    private function cursorAfter(EntryListRow $row): EntryCursor
    {
        return new EntryCursor(
            $row->entry->getEffectiveDate(),
            $row->entry->getId() ?? throw new \LogicException('A persisted entry must have an id.'),
        );
    }
```

Then the tests. The seeding order is deliberate: ids never agree with dates, so an id-only sort cannot pass.

```php
    public function testOldestFirstReversesTheListIdTieBreakIncluded(): void
    {
        $tied = '2026-07-12T00:00:00Z';
        $this->entryAt('newest', '2026-07-01T00:00:00Z', '2026-07-15T00:00:00Z');
        $this->entryAt('tied-first', $tied, $tied);
        $this->entryAt('tied-second', $tied, $tied);
        $this->entryAt('older', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');

        $rows = $this->repo()->listForUser(
            new EntryQuery($this->user->getId() ?? 0, order: ListOrder::OldestFirst),
        );

        self::assertSame(['older', 'tied-first', 'tied-second', 'newest'], $this->guids($rows));
    }

    public function testOldestFirstKeysetPaginatesAcrossATiedEffectiveDate(): void
    {
        $tied = '2026-07-12T00:00:00Z';
        $this->entryAt('later', '2026-07-20T00:00:00Z', '2026-07-20T00:00:00Z');
        $this->entryAt('e1', $tied, $tied);
        $this->entryAt('e2', $tied, $tied);
        $this->entryAt('e3', $tied, $tied);
        $userId = $this->user->getId() ?? 0;

        $page1 = $this->repo()->listForUser(new EntryQuery($userId, limit: 2, order: ListOrder::OldestFirst));
        self::assertSame(['e1', 'e2'], $this->guids($page1));

        $page2 = $this->repo()->listForUser(new EntryQuery(
            $userId,
            cursor: $this->cursorAfter($page1[1]),
            limit: 2,
            order: ListOrder::OldestFirst,
        ));
        self::assertSame(['e3', 'later'], $this->guids($page2));
    }

    public function testViewedViewOldestFirstListsTheEarliestOpenedFirst(): void
    {
        $early = $this->entryAt('early', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $late = $this->entryAt('late', '2026-07-01T00:00:00Z', '2026-07-20T00:00:00Z');
        $earlyState = new EntryState($this->user, $early);
        $earlyState->markViewed(new \DateTimeImmutable('2026-08-05T09:00:00Z'));
        $lateState = new EntryState($this->user, $late);
        $lateState->markViewed(new \DateTimeImmutable('2026-08-01T09:00:00Z'));
        $this->em->persist($earlyState);
        $this->em->persist($lateState);
        $this->em->flush();

        $rows = $this->repo()->listForUser(
            new EntryQuery($this->user->getId() ?? 0, view: 'viewed', order: ListOrder::OldestFirst),
        );

        self::assertSame(['late', 'early'], $this->guids($rows));
    }
```

In `backend/tests/Controller/Api/EntryControllerTest.php`, after `testRejectsUnknownView`:

```php
    public function testListsOldestFirstWhenAskedAndPagesOnward(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-oldest@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request('GET', '/api/entries?order=asc&limit=2', server: $headers);
        $page1 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page1);
        self::assertIsArray($page1['entries']);
        self::assertSame(['Post 1', 'Post 2'], array_column($page1['entries'], 'title'));
        self::assertIsString($page1['nextCursor']);

        $client->request(
            'GET',
            '/api/entries?order=asc&limit=2&cursor=' . urlencode($page1['nextCursor']),
            server: $headers,
        );
        $page2 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page2);
        self::assertIsArray($page2['entries']);
        self::assertSame(['Post 3'], array_column($page2['entries'], 'title'));
    }

    public function testRejectsAnUnknownOrder(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-order@example.com');

        $client->request('GET', '/api/entries?order=up', server: $headers);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertSame(['order' => ['Unknown order. Use one of: desc, asc.']], $body['errors']);
    }

    public function testTheForYouViewAcceptsAnOrderItDoesNotApply(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-order-for-you@example.com');

        $client->request('GET', '/api/entries?view=for-you&order=asc', server: $headers);

        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownOrderIsRejectedOnTheForYouViewToo(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-order-for-you-bad@example.com');

        $client->request('GET', '/api/entries?view=for-you&order=up', server: $headers);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertSame(['order' => ['Unknown order. Use one of: desc, asc.']], $body['errors']);
    }
```

- [ ] **Step 6: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Repository/EntryQueryTest.php tests/Repository/EntryListTest.php tests/Controller/Api/EntryControllerTest.php`
Expected: FAIL — unknown named parameter `order` and undefined method `ordering()`. The two controller tests show newest-first titles, and `order=up` returns 200.

- [ ] **Step 7: Implement.**

`backend/src/Repository/EntryListOrdering.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\ListOrder;

/**
 * The instant a list ranks by and the direction it runs in. One value, because
 * the ORDER BY, the keyset predicate and the tag window must all read the same pair.
 */
final readonly class EntryListOrdering
{
    public function __construct(
        public EntryListSort $sort,
        public ListOrder $order = ListOrder::NewestFirst,
    ) {
    }
}
```

`AbstractEntryProjectionRepository`: replace `newestFirst`, `orderedBy` and `applyCursor` with:

```php
    /**
     * The publish-date order, newest first, for the reads that never take the
     * caller's order: search-engine hydration and the digest.
     */
    protected function newestFirst(QueryBuilder $qb): QueryBuilder
    {
        return $this->orderedBy($qb, new EntryListOrdering(EntryListSort::PublishedDate));
    }

    /**
     * The sort's instant column, then id as the tiebreaker a refresh run's tied
     * instants need, both in the ordering's direction. applyCursor() reads the same
     * EntryListOrdering, so the ORDER BY and the keyset predicate cannot disagree.
     */
    protected function orderedBy(QueryBuilder $qb, EntryListOrdering $ordering): QueryBuilder
    {
        $direction = $ordering->order->sqlDirection();

        return $qb
            ->orderBy($ordering->sort->orderColumn(), $direction)
            ->addOrderBy('e.id', $direction);
    }

    protected function applyCursor(QueryBuilder $qb, ?EntryCursor $cursor, EntryListOrdering $ordering): void
    {
        if ($cursor === null) {
            return;
        }

        $qb->andWhere(\sprintf(
            '(%1$s %2$s :curInstant OR (%1$s = :curInstant AND e.id %2$s :curId))',
            $ordering->sort->orderColumn(),
            $ordering->order->strictlyAfter(),
        ))
            ->setParameter('curInstant', $cursor->sortInstant, Types::DATETIME_IMMUTABLE)
            ->setParameter('curId', $cursor->id);
    }
```

`EntryQuery`: add `use App\Enum\ListOrder;`. Add `public ListOrder $order = ListOrder::NewestFirst,` as the **last** constructor parameter, after `int $limit = self::DEFAULT_LIMIT,`. Then add:

```php
    public function ordering(): EntryListOrdering
    {
        return new EntryListOrdering(EntryListSort::forView($this->view), $this->order);
    }
```

`EntryListRepository::listForUser`:
- Replace `$sort = EntryListSort::forView($query->view);` with `$ordering = $query->ordering();`.
- In `$pageQuery`: `use ($query, $ordering, $applyScope)`, `$this->orderedBy($this->rowQueryBuilder($query->userId), $ordering)` and `$this->applyCursor($qb, $query->cursor, $ordering);`.
- In `$windowProbe`, build `$probeOrdering = new EntryListOrdering(EntryListSort::PublishedDate, $query->order);` and pass it to both `orderedBy()` and `applyCursor()`.
- In the method docblock, change "sorted newest first" to "in the query's order".

`EntryListRepository::searchForUser` and `SavedSearchEntryRepository::listMembers`: replace the `EntryListSort::PublishedDate` argument of `applyCursor(...)` with `new EntryListOrdering(EntryListSort::PublishedDate)`. This is compile only; Tasks 4 and 5 give them their real ordering.

`EntryController::list`: add `use App\Enum\ListOrder;` and a parameter `#[MapQueryParameter] ?string $order = null,` after `$unread`. Right after the `$view = match (…)` statement, add `$listOrder = ListOrder::fromRequestValue($order);`, before the for-you branch, so an invalid order 422s everywhere. Pass `order: $listOrder,` to `new EntryQuery(...)`.

- [ ] **Step 8: Run the tests and watch them pass**

Run: `cd backend && php bin/phpunit tests/Enum tests/Repository tests/Controller/Api/EntryControllerTest.php`
Expected: PASS, including every pre-existing `EntryListTest` case (newest first unchanged).

- [ ] **Step 9: Prove each new test can fail** (see "Every task"). At minimum:
  - swap the two `sqlDirection()` arms
  - make `orderedBy` use a fixed `'DESC'` for the id tiebreak (not `'ASC'` — with
    NewestFirst's own direction already `'DESC'`, an `'ASC'` mutation cannot turn
    any new test red; `'DESC'` does, via `testOldestFirstReversesTheListIdTieBreakIncluded`)
  - swap the `strictlyAfter()` arms
  - pass `$query->order` as `NewestFirst` in `ordering()`

  Show each red run, restore it, and show green.

- [ ] **Step 10: Gates and commit**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan && composer tramp
for f in src/Enum/ListOrder.php src/Repository/EntryListOrdering.php src/Repository/AbstractEntryProjectionRepository.php src/Repository/EntryQuery.php src/Repository/EntryListRepository.php src/Repository/SavedSearchEntryRepository.php src/Controller/Api/EntryController.php; do vendor/bin/phpmd $f text phpmd.xml.dist; done
php bin/phpunit
git add -A src tests
git commit -m "feat(#1143): list entries oldest first on request"
```

---

### Task 3: Mirror the tag window (#1099) for oldest first, and check the MySQL plans

**Files:**
- Modify: `backend/src/Repository/DateOrderedPage.php`
- Modify: `backend/tests/Repository/EntryListTest.php`

**Interfaces:**
- Consumes: `EntryQuery::$order` and `ListOrder::atOrBefore()` (Task 2).
- Produces: the tag-scoped window is correct in both orders.

- [ ] **Step 1: Write the failing tests** in `EntryListTest` (they use the Task 2 helpers `guids()` and `cursorAfter()`):

```php
    public function testOldestFirstDenseTagWindowedAttemptEqualsThePlainQuerysPage(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $this->entryIn($this->fillerFeed(), 'filler', '2026-07-08T00:00:00Z');
        $this->entryIn($feed, 't1', '2026-07-09T00:00:00Z');
        $this->entryIn($feed, 't2', '2026-07-10T00:00:00Z');
        $this->entryIn($feed, 't3', '2026-07-11T00:00:00Z');

        $query = new EntryQuery(
            $this->user->getId() ?? 0,
            tagId: $tag->getId(),
            limit: 2,
            order: ListOrder::OldestFirst,
        );
        $recorded = $this->recordedList($this->repoWithWindow(2), $query);

        self::assertCount(2, $recorded['queries'], 'a full windowed page must never fall back');
        self::assertSame(['t1', 't2'], $this->guids($recorded['rows']));
    }

    public function testOldestFirstCursorContinuesAWindowedPageWithoutGapOrOverlap(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        foreach (['t1' => 10, 't2' => 11, 't3' => 12, 't4' => 13, 't5' => 14] as $guid => $day) {
            $this->entryIn($feed, $guid, sprintf('2026-07-%02dT00:00:00Z', $day));
        }
        $repo = $this->repoWithWindow(2);
        $userId = $this->user->getId() ?? 0;

        $page1 = $repo->listForUser(
            new EntryQuery($userId, tagId: $tag->getId(), limit: 2, order: ListOrder::OldestFirst),
        );
        self::assertSame(['t1', 't2'], $this->guids($page1));

        $recorded = $this->recordedList($repo, new EntryQuery(
            $userId,
            tagId: $tag->getId(),
            cursor: $this->cursorAfter($page1[1]),
            limit: 2,
            order: ListOrder::OldestFirst,
        ));
        self::assertCount(2, $recorded['queries'], 'the cursor must keep this page windowed, not fall back');
        self::assertSame(['t3', 't4'], $this->guids($recorded['rows']));
    }
```

Why they discriminate:
- With the window still `>=`, the first test returns `t2, t3`.
- With a probe that still walks DESC, it falls back (3 queries).
- With a probe that drops the cursor, the second test falls back.

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit --filter 'OldestFirst' tests/Repository/EntryListTest.php`
Expected: the two new tests FAIL.

- [ ] **Step 3: Implement** in `DateOrderedPage`:
  - Add `use App\Enum\ListOrder;`.
  - Rename `windowStart` → `windowEdge`, in the method and in the variable in `tagScopedRows`.
  - Pass `$query->order` to `windowed()`.
  - Docblocks:
    - `windowEdge()`: "The K-th effectiveDate beyond the cursor in the page's own order, or null when fewer than K rows lie beyond it."
    - `$windowProbe` parameter of `rows()`: "a fresh Entry-only query selecting e.effectiveDate in the page's order, carrying the page's own cursor".

```php
        $windowed = $this->hintedResult($this->windowed($pageQuery(), $windowEdge, $query->order));
```

```php
    private function windowed(QueryBuilder $pageQuery, \DateTimeImmutable $windowEdge, ListOrder $order): QueryBuilder
    {
        return $pageQuery
            ->andWhere(\sprintf('e.effectiveDate %s :windowEdge', $order->atOrBefore()))
            ->setParameter('windowEdge', $windowEdge, Types::DATETIME_IMMUTABLE);
    }
```

- [ ] **Step 4: Run the whole `EntryListTest`** — Expected: PASS, the existing newest-first window tests included.

- [ ] **Step 5: Run the MySQL leg** of the list tests:

Run: `docker compose exec php composer test -- --filter 'EntryListTest|EntryControllerTest|ListOrderTest'`
Expected: PASS.

- [ ] **Step 6: Time both orders on the dev database** (read-only GETs; `php` must serve the current code):

```bash
docker compose exec php bin/console cache:clear
docker compose exec -T php bin/console dbal:run-sql "SELECT u.email, COUNT(*) AS subscriptions FROM subscription s JOIN app_user u ON u.id = s.user_id GROUP BY u.email ORDER BY subscriptions DESC LIMIT 1"
EMAIL='<the email printed above>'
TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token "$EMAIL" | grep -Eo 'eyJ[A-Za-z0-9._-]+')
TAG=$(docker compose exec -T php bin/console dbal:run-sql "SELECT t.id FROM tag t JOIN app_user u ON u.id = t.user_id WHERE u.email = '$EMAIL' LIMIT 1" | grep -Eo '^ *[0-9]+' | head -1 | tr -d ' ')
for query in "view=all" "view=unread" "view=all&tag=$TAG" "view=unread&tag=$TAG"; do
  for order in desc asc; do
    for run in 1 2 3; do
      curl -sk -o /dev/null -w "$query $order %{time_total}\n" -H "Authorization: Bearer $TOKEN" "https://localhost:8443/api/entries?$query&order=$order&limit=100"
    done
  done
done
```

Expected: `asc` is within about 2× of `desc` for every query on warm runs (2 and 3). Paste the table into the report. If any `asc` query is much slower:
1. Capture its SQL from today's dev log (`ls -t backend/var/log/dev-*.log | head -1`, JSON lines, `jq 'select(.channel=="doctrine")'`).
2. Run `EXPLAIN FORMAT=TREE` on it in the `mysql` container.
3. Report `DONE_WITH_CONCERNS` with the plan. Do not add index hints without a decision.

- [ ] **Step 7: Gates and commit**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan && composer tramp
vendor/bin/phpmd src/Repository/DateOrderedPage.php text phpmd.xml.dist
git add src/Repository/DateOrderedPage.php tests/Repository/EntryListTest.php
git commit -m "feat(#1143): mirror the tag window for oldest-first lists"
```

---

### Task 4: Saved-search lists take the order

**Files:**
- Modify: `backend/src/Repository/SavedSearchListQuery.php`
- Modify: `backend/src/Repository/SavedSearchEntryRepository.php` (`listMembers`)
- Modify: `backend/src/Controller/Api/SavedSearchEntriesController.php` (`list`, `one`)
- Modify: `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php`

**Interfaces:**
- Consumes: `ListOrder`, `EntryListOrdering`.
- Produces: `SavedSearchListQuery::$order` (last parameter) and `SavedSearchListQuery::ordering()`.

- [ ] **Step 1: Write the failing tests** in `SavedSearchEntriesControllerTest`. The newer entry is seeded first, so its lower id cannot fake the order.

```php
    public function testListsOldestFirstWhenAsked(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('saved-oldest@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = $this->seedSubscribedFeed($user);
        $later = $this->seedEntry($feed, 'Climate later', new \DateTimeImmutable('2026-07-05T00:00:00Z'));
        $earlier = $this->seedEntry($feed, 'Climate earlier', new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        $search = new SavedSearch($user, 'climate', false);
        $this->em()->persist($search);
        $this->em()->flush();
        $this->member($search, $later);
        $this->member($search, $earlier);

        foreach (['/api/entries/saved-searches', '/api/entries/saved-searches/' . $search->getId()] as $path) {
            $client->request('GET', $path . '?order=asc', server: $headers);
            self::assertResponseIsSuccessful();
            $body = $this->payload($client);
            self::assertIsArray($body['entries']);
            self::assertSame(['Climate earlier', 'Climate later'], array_column($body['entries'], 'title'), $path);
        }
    }

    public function testPagesOldestFirst(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('saved-oldest-pages@example.com');
        $headers = $this->authHeaderFor($user);
        $feed = $this->seedSubscribedFeed($user);
        $later = $this->seedEntry($feed, 'Climate later', new \DateTimeImmutable('2026-07-05T00:00:00Z'));
        $earlier = $this->seedEntry($feed, 'Climate earlier', new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        $search = new SavedSearch($user, 'climate', false);
        $this->em()->persist($search);
        $this->em()->flush();
        $this->member($search, $later);
        $this->member($search, $earlier);

        $client->request('GET', '/api/entries/saved-searches?order=asc&limit=1', server: $headers);
        $page1 = $this->payload($client);
        self::assertIsArray($page1['entries']);
        self::assertSame(['Climate earlier'], array_column($page1['entries'], 'title'));
        self::assertIsString($page1['nextCursor']);

        $client->request(
            'GET',
            '/api/entries/saved-searches?order=asc&limit=1&cursor=' . urlencode($page1['nextCursor']),
            server: $headers,
        );
        $page2 = $this->payload($client);
        self::assertIsArray($page2['entries']);
        self::assertSame(['Climate later'], array_column($page2['entries'], 'title'));
    }

    public function testRejectsAnUnknownOrder(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('saved-bad-order@example.com');

        $client->request('GET', '/api/entries/saved-searches?order=sideways', server: $this->authHeaderFor($user));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['order' => ['Unknown order. Use one of: desc, asc.']], $this->payload($client)['errors']);
    }
```

The two seeding blocks are identical. If you keep both tests, extract a private `seedTwoClimateMembers(User $user): SavedSearch` helper (DRY) and use it in both.

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedSearchEntriesControllerTest.php`
Expected: the three new tests FAIL.

- [ ] **Step 3: Implement.**

`SavedSearchListQuery`:
- Add `use App\Enum\ListOrder;`.
- Add the last constructor parameter `public ListOrder $order = ListOrder::NewestFirst,`.
- Add:

```php
    public function ordering(): EntryListOrdering
    {
        return new EntryListOrdering(EntryListSort::PublishedDate, $this->order);
    }
```

`SavedSearchEntryRepository::listMembers`:
- Use `$qb = $this->orderedBy($this->rowQueryBuilder($query->userId), $query->ordering())->setMaxResults($query->limit);` and `$this->applyCursor($qb, $query->cursor, $query->ordering());`.
- In its docblock, change "newest first" to "in the query's order".

`SavedSearchEntriesController`: add `use App\Enum\ListOrder;`. In both `list()` and `one()`, add the parameter `#[MapQueryParameter] ?string $order = null,` after `$unread`, and pass `order: ListOrder::fromRequestValue($order),` to `new SavedSearchListQuery(...)`.

- [ ] **Step 4: Run the tests** — Expected: PASS (the whole file).

- [ ] **Step 5: Prove the new tests can fail.** Drop `$query->ordering()` for `new EntryListOrdering(EntryListSort::PublishedDate)` in `listMembers`, run, restore.

- [ ] **Step 6: Gates and commit**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan && composer tramp
for f in src/Repository/SavedSearchListQuery.php src/Repository/SavedSearchEntryRepository.php src/Controller/Api/SavedSearchEntriesController.php; do vendor/bin/phpmd $f text phpmd.xml.dist; done
git add -A src tests
git commit -m "feat(#1143): list saved-search matches oldest first on request"
```

---

### Task 5: Search takes the order (LIKE and Meilisearch)

**Files:**
- Modify: `backend/src/Repository/EntrySearchQuery.php`
- Modify: `backend/src/Service/Search/EntrySearchRequestFactory.php`
- Modify: `backend/src/Repository/EntryListRepository.php` (`searchForUser`)
- Modify: `backend/src/Service/Search/Index/IndexSearch.php`
- Modify: `backend/src/Service/Search/IndexedEntrySearch.php`
- Modify: `backend/src/Service/Search/Index/MeilisearchIndex.php` (`searchPayload`, `filterFor`)
- Modify: `docs/meilisearch-wire-format.md` (the `sort` and `filter` bullets)
- Tests:
  - `backend/tests/Service/Search/EntrySearchRequestFactoryTest.php`
  - `backend/tests/Repository/EntrySearchTest.php`
  - `backend/tests/Service/Search/IndexedEntrySearchTest.php`
  - `backend/tests/Service/Search/Index/MeilisearchIndexTest.php`
  - `backend/tests/Controller/Api/EntrySearchControllerTest.php`

**Interfaces:**
- Produces:
  - `EntrySearchQuery::$order` (last parameter) and `EntrySearchQuery::ordering()`.
  - `IndexSearch::$order` (last parameter, default `NewestFirst`).
  - `EntrySearchRequestFactory::ALLOWED_PARAMETERS` gains `'order'`.

- [ ] **Step 1: Write the failing tests.**

`EntrySearchRequestFactoryTest` (add `use App\Enum\ListOrder;`):

```php
    public function testReadsTheOrderAndDefaultsToNewestFirst(): void
    {
        $asked = $this->factory->fromRequest(Request::create('/api/entries/search?q=angular&order=asc'), $this->buildUser());
        $absent = $this->factory->fromRequest(Request::create('/api/entries/search?q=angular'), $this->buildUser());

        self::assertSame(ListOrder::OldestFirst, $asked->order);
        self::assertSame(ListOrder::NewestFirst, $absent->order);
    }

    public function testRejectsAnUnknownOrder(): void
    {
        try {
            $this->factory->fromRequest(Request::create('/api/entries/search?q=angular&order=up'), $this->buildUser());
            self::fail('An unknown order must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(['Unknown order. Use one of: desc, asc.'], $exception->errors['order'] ?? null);
        }
    }
```

`EntrySearchTest` (LIKE; add `use App\Enum\ListOrder;`). Add a helper next to `unreadSearch()` and two tests:

```php
    /** @return list<string> the guids an oldest-first search returned, in order */
    private function oldestFirstSearch(string $input, ?EntryCursor $cursor = null): array
    {
        $rows = $this->repo()->searchForUser(new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput($input),
            cursor: $cursor,
            order: ListOrder::OldestFirst,
        ));

        return array_map(static fn ($row) => $row->entry->getGuid(), $rows);
    }

    public function testReturnsOldestFirstWhenAsked(): void
    {
        $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');
        $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');

        self::assertSame(['older', 'newer'], $this->oldestFirstSearch('angular'));
    }

    public function testPagesOldestFirstWithTheKeysetCursor(): void
    {
        $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');
        $older = $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');

        $cursor = new EntryCursor($older->getEffectiveDate(), $older->getId() ?? 0);

        self::assertSame(['newer'], $this->oldestFirstSearch('angular', $cursor));
    }
```

`IndexedEntrySearchTest` (add `use App\Enum\ListOrder;`):

```php
    public function testAnOldestFirstPageIsHydratedOldestFirstAndResumesAfterItsNewestRow(): void
    {
        $newer = $this->entry('newer', '2026-07-12T00:00:00Z');
        $older = $this->entry('older', '2026-07-10T00:00:00Z');
        $reader = new FakeSearchIndexReader(entryIds: [$older->getId() ?? 0, $newer->getId() ?? 0]);

        $result = $this->search($reader, new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput('angular'),
            limit: 2,
            order: ListOrder::OldestFirst,
        ));

        self::assertNotNull($reader->received);
        self::assertSame(ListOrder::OldestFirst, $reader->received->order);
        self::assertSame(['older', 'newer'], array_map(static fn ($row) => $row->entry->getGuid(), $result->rows));
        self::assertSame('newer', $result->continuationRow?->entry->getGuid());
    }
```

`MeilisearchIndexTest` (add `use App\Enum\ListOrder;`):

```php
    public function testAnOldestFirstSearchSortsBothKeysAscending(): void
    {
        $search = new IndexSearch(SearchTerms::fromInput('widgets'), [1, 2], null, 20, null, ListOrder::OldestFirst);
        $this->index($this->clientCapturing(new MockResponse('{"hits":[]}')))->find($search);

        self::assertSame(['effectiveDate:asc', 'id:asc'], $this->capturedJsonObject()['sort']);
    }

    public function testAnOldestFirstCursorAddsTheMirroredPredicate(): void
    {
        $cursor = new EntryCursor(new \DateTimeImmutable('@100'), 5);
        $search = new IndexSearch(SearchTerms::fromInput('widgets'), [1, 2], $cursor, 20, null, ListOrder::OldestFirst);
        $this->index($this->clientCapturing(new MockResponse('{"hits":[]}')))->find($search);

        self::assertSame(
            'feedId IN [1,2] AND (effectiveDate > 100 OR (effectiveDate = 100 AND id > 5))',
            $this->capturedJsonObject()['filter'],
        );
    }
```

`EntrySearchControllerTest` (the test environment has no engine, so this runs through LIKE). `seedSubscribedFeedWithEntries` titles entries "`$prefix` Post `$i`", dated July `$i`:

```php
    public function testSearchesOldestFirstWhenAsked(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('s-oldest@example.com');
        $this->seedSubscribedFeedWithEntries($user, 'Angular', 3);

        $client->request('GET', '/api/entries/search?q=angular&order=asc', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertIsArray($body['entries']);
        self::assertSame(
            ['Angular Post 1', 'Angular Post 2', 'Angular Post 3'],
            array_column($body['entries'], 'title'),
        );
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Service/Search tests/Repository/EntrySearchTest.php tests/Controller/Api/EntrySearchControllerTest.php`
Expected: the new tests FAIL. The factory rejects `order` as an unknown parameter, and the named parameter `order` does not exist.

- [ ] **Step 3: Implement.**

`EntrySearchQuery`: add `use App\Enum\ListOrder;`. Add the last parameter `public ListOrder $order = ListOrder::NewestFirst,` after `public bool $unread = false,`, and:

```php
    public function ordering(): EntryListOrdering
    {
        return new EntryListOrdering(EntryListSort::PublishedDate, $this->order);
    }
```

`EntrySearchRequestFactory`:
- `public const array ALLOWED_PARAMETERS = ['q', 'cursor', 'limit', 'unread', 'order'];`
- Add `use App\Enum\ListOrder;`.
- Add `order: ListOrder::fromRequestValue($this->singleValue($request, 'order')),` to the `new EntrySearchQuery(...)`.

`EntryListRepository::searchForUser`:
- `$qb = $this->orderedBy($this->rowQueryBuilder($query->userId), $query->ordering())->setMaxResults($query->limit);`
- `$this->applyCursor($qb, $query->cursor, $query->ordering());`
- Delete the now-stale two-line comment above the old `applyCursor` call.
- Docblock: change "newest first" to "in the query's order".

`IndexSearch`: add `use App\Enum\ListOrder;` and the last constructor parameter `public ListOrder $order = ListOrder::NewestFirst,` after `public ?array $entryIds = null,`.

`IndexedEntrySearch::search`:
- Pass `order: $query->order,` into `new IndexSearch(...)`.
- Replace the hydration line with:

```php
        $candidates = $query->order->arrange(
            $this->entries->rowsByIdsForUser($matches->entryIds, $query->userId),
        );
```

`MeilisearchIndex::searchPayload`: replace the `sort` entry and its comment with:

```php
            'sort' => ['effectiveDate:' . $search->order->value, 'id:' . $search->order->value],
```

`MeilisearchIndex::filterFor`: replace the cursor clause and its comment with:

```php
        if ($search->cursor !== null) {
            $clauses[] = sprintf(
                '(effectiveDate %3$s %1$d OR (effectiveDate = %1$d AND id %3$s %2$d))',
                $search->cursor->sortInstant->getTimestamp(),
                $search->cursor->id,
                $search->order->strictlyAfter(),
            );
        }
```

Docs (`docs/meilisearch-wire-format.md`):
- `sort` bullet: "matches the order the caller asked for — `effectiveDate:desc, id:desc` by default, both `:asc` for an oldest-first search (#1143)".
- `filter` bullet: "everything strictly past the cursor in the requested order (`<` newest first, `>` oldest first), plus same-date rows past its `id`".

- [ ] **Step 4: Run the tests** — Expected: PASS, including every pre-existing search test.

- [ ] **Step 5: Prove the new tests can fail.**
  - Remove the `arrange()` call.
  - Hard-code `':desc'` in the sort.
  - Hard-code `'<'` in the filter.
  - Drop `'order'` from `ALLOWED_PARAMETERS`.

  Run each, then restore.

- [ ] **Step 6: Gates and commit**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan && composer tramp
for f in src/Repository/EntrySearchQuery.php src/Service/Search/EntrySearchRequestFactory.php src/Repository/EntryListRepository.php src/Service/Search/Index/IndexSearch.php src/Service/Search/IndexedEntrySearch.php src/Service/Search/Index/MeilisearchIndex.php; do vendor/bin/phpmd $f text phpmd.xml.dist; done
git add -A src tests ../docs/meilisearch-wire-format.md
git commit -m "feat(#1143): search oldest first on request"
```

---

### Task 6: The `userId` JWT claim

**Files:**
- Create: `backend/src/EventListener/AddUserIdClaimOnTokenIssue.php`
- Create: `backend/tests/EventListener/AddUserIdClaimOnTokenIssueTest.php`

**Interfaces:** Produces: every issued JWT (password, passkey, OAuth; they all dispatch `Events::JWT_CREATED`) carries `userId: int`.

- [ ] **Step 1: Write the failing test.** Two users, so the id checked is not simply the first row.

```php
<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\User;
use App\Tests\DbTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class AddUserIdClaimOnTokenIssueTest extends DbTestCase
{
    public function testAnIssuedTokenCarriesTheAccountIdAsAClaim(): void
    {
        $this->em->persist(new User('first@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $second = new User('second@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($second);
        $this->em->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $claims = $tokens->parse($tokens->create($second));

        self::assertSame($second->getId(), $claims['userId'] ?? null);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/EventListener/AddUserIdClaimOnTokenIssueTest.php`
Expected: FAIL (`null` is not the id).

- [ ] **Step 3: Implement** — `backend/src/EventListener/AddUserIdClaimOnTokenIssue.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lets a client key per-account device state before `/api/me` answers (#1143).
 * Registered on Events::JWT_CREATED, not the class name — see StampLastLoginOnTokenIssue.
 */
#[AsEventListener(event: Events::JWT_CREATED, method: '__invoke')]
final readonly class AddUserIdClaimOnTokenIssue
{
    public const string CLAIM = 'userId';

    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $event->setData([
            ...$event->getData(),
            self::CLAIM => $user->getId() ?? throw new \LogicException('A signed-in user must have an id.'),
        ]);
    }
}
```

- [ ] **Step 4: Run the test** — Expected: PASS. Then run the auth suites: `php bin/phpunit tests/Controller/Api tests/Security tests/Service/OAuth`. Expected: PASS.

- [ ] **Step 5: Gates and commit**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan
vendor/bin/phpmd src/EventListener/AddUserIdClaimOnTokenIssue.php text phpmd.xml.dist
git add src/EventListener/AddUserIdClaimOnTokenIssue.php tests/EventListener/AddUserIdClaimOnTokenIssueTest.php
git commit -m "feat(#1143): carry the account id as a jwt claim"
```

---

### Task 7: `AccountIdentity` and `UserDeviceStorage`

**Files:**
- Create: `frontend/src/app/core/account-identity.ts`, `frontend/src/app/core/account-identity.spec.ts`
- Create: `frontend/src/app/core/user-device-storage.ts`, `frontend/src/app/core/user-device-storage.spec.ts`
- Modify: `frontend/src/app/settings/account-section.component.ts` (`deleteAccount`), `frontend/src/app/settings/account-section.component.spec.ts`

**Interfaces:**
- Produces:
  - `AccountIdentity.userId: Signal<number | null>`
  - `userIdClaim(token: string | null): number | null`
  - `UserDeviceStorage.read(name: string): string | null` (reactive)
  - `UserDeviceStorage.write(name: string, value: string | null): void`
  - `UserDeviceStorage.forgetCurrentUser(): void`
  - `UserDeviceStorage.ready: Signal<boolean>`

`AccountIdentity` reads `AuthService.user()` for the fallback. `AuthService` must therefore never inject `AccountIdentity` or `UserDeviceStorage` (it would be a DI cycle). That's why the account-delete cleanup lives in `AccountSectionComponent`.

- [ ] **Step 1: Write the failing tests.**

`frontend/src/app/core/account-identity.spec.ts`:

```ts
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity, userIdClaim } from './account-identity';
import { AuthService } from './auth.service';
import { TokenStore } from './token.store';

function jwtWith(claims: object): string {
  const encode = (part: object): string =>
    btoa(JSON.stringify(part)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  return `${encode({ alg: 'RS256' })}.${encode(claims)}.signature`;
}

describe('userIdClaim', () => {
  it('reads the userId claim', () => {
    expect(userIdClaim(jwtWith({ userId: 4711, username: 'a@b.c' }))).toBe(4711);
  });

  it('decodes a base64url payload that plain base64 would reject', () => {
    const token = jwtWith({ userId: 58, username: '>>>???>>>???' });
    expect(token.split('.')[1]).toMatch(/[-_]/);
    expect(userIdClaim(token)).toBe(58);
  });

  it('answers null for a token without the claim', () => {
    expect(userIdClaim(jwtWith({ username: 'a@b.c' }))).toBeNull();
  });

  it('answers null for no token, a token that is no JWT, and a claim that is no integer', () => {
    expect(userIdClaim(null)).toBeNull();
    expect(userIdClaim('stub-token-for-the-guard')).toBeNull();
    expect(userIdClaim('a.%%%.c')).toBeNull();
    expect(userIdClaim(jwtWith({ userId: '4711' }))).toBeNull();
    expect(userIdClaim(jwtWith({ userId: 47.5 }))).toBeNull();
  });
});

describe('AccountIdentity', () => {
  function setup(user: { id: number } | null) {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [{ provide: AuthService, useValue: { user: signal(user) } }],
    });
    return { identity: TestBed.inject(AccountIdentity), tokens: TestBed.inject(TokenStore) };
  }

  it("prefers the stored token's claim over the loaded account", () => {
    const { identity, tokens } = setup({ id: 12 });
    tokens.set(jwtWith({ userId: 34 }));
    expect(identity.userId()).toBe(34);
  });

  it('falls back to the loaded account for a token issued before the claim', () => {
    const { identity, tokens } = setup({ id: 12 });
    tokens.set('legacy.token.value');
    expect(identity.userId()).toBe(12);
  });

  it('knows no account before either answers', () => {
    expect(setup(null).identity.userId()).toBeNull();
  });
});
```

`frontend/src/app/core/user-device-storage.spec.ts`:

```ts
import { WritableSignal, computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity } from './account-identity';
import { UserDeviceStorage } from './user-device-storage';

describe('UserDeviceStorage', () => {
  let userId: WritableSignal<number | null>;
  let storage: UserDeviceStorage;

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(7);
    TestBed.configureTestingModule({ providers: [{ provide: AccountIdentity, useValue: { userId } }] });
    storage = TestBed.inject(UserDeviceStorage);
  });

  it('keeps each value under the signed-in user', () => {
    storage.write('unread-only', '1');
    expect(localStorage.getItem('sfr.user.7.unread-only')).toBe('1');
    expect(storage.read('unread-only')).toBe('1');
  });

  it("shows one user nothing of another's", () => {
    storage.write('unread-only', '1');
    userId.set(8);
    expect(storage.read('unread-only')).toBeNull();
    userId.set(7);
    expect(storage.read('unread-only')).toBe('1');
  });

  it('removes a value written as null', () => {
    storage.write('unread-only', '1');
    storage.write('unread-only', null);
    expect(localStorage.getItem('sfr.user.7.unread-only')).toBeNull();
  });

  it('neither reads nor writes before the account is known, and says so', () => {
    userId.set(null);
    storage.write('unread-only', '1');
    expect(localStorage.length).toBe(0);
    expect(storage.read('unread-only')).toBeNull();
    expect(storage.ready()).toBe(false);
  });

  it('notifies a computed that read it when a value is written', () => {
    const views = computed(() => storage.read('oldest-first-views'));
    expect(views()).toBeNull();
    storage.write('oldest-first-views', '["all"]');
    expect(views()).toBe('["all"]');
  });

  it("forgets every value of the signed-in user and nobody else's", () => {
    storage.write('unread-only', '1');
    storage.write('oldest-first-views', '["all"]');
    localStorage.setItem('sfr.user.70.unread-only', '1');
    localStorage.setItem('sfr.layout', 'list');

    storage.forgetCurrentUser();

    expect(localStorage.getItem('sfr.user.7.unread-only')).toBeNull();
    expect(localStorage.getItem('sfr.user.7.oldest-first-views')).toBeNull();
    expect(localStorage.getItem('sfr.user.70.unread-only')).toBe('1');
    expect(localStorage.getItem('sfr.layout')).toBe('list');
  });
});
```

In `account-section.component.spec.ts`, after `'deletes the account and logs out once confirmed'`. The spec's `user` fixture is a `CurrentUser` with an `id`, and the real `AuthService` holds it, so the real `AccountIdentity` resolves it.

```ts
  it("forgets this account's device settings once the delete succeeds", () => {
    const f = mount(user);
    localStorage.setItem(`sfr.user.${user.id}.unread-only`, '1');
    dialogStub.open.mockReturnValue({ closed: of(true) });

    f.componentInstance.confirmThenDelete();
    httpMock.expectOne(`${base}/api/me`).flush(null, { status: 204, statusText: 'No Content' });

    expect(localStorage.getItem(`sfr.user.${user.id}.unread-only`)).toBeNull();
  });
```

In the existing test `'shows the problem detail in an error banner when the delete request fails'`:
- Seed `localStorage.setItem(`sfr.user.${user.id}.unread-only`, '1')` before the delete.
- Assert afterwards that the value is still `'1'`.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npm test -- src/app/core/account-identity.spec.ts src/app/core/user-device-storage.spec.ts src/app/settings/account-section.component.spec.ts`
Expected: FAIL (modules not found; the stored value survives the delete).

- [ ] **Step 3: Implement.**

`frontend/src/app/core/account-identity.ts`:

```ts
import { Injectable, computed, inject } from '@angular/core';
import { AuthService } from './auth.service';
import { TokenStore } from './token.store';

/** The signed-in account's id: from the token's claim at once, or from `/api/me`
 *  for a token issued before the claim existed (#1143). */
@Injectable({ providedIn: 'root' })
export class AccountIdentity {
  private readonly tokens = inject(TokenStore);
  private readonly auth = inject(AuthService);

  readonly userId = computed(
    () => userIdClaim(this.tokens.token()) ?? this.auth.user()?.id ?? null,
  );
}

export function userIdClaim(token: string | null): number | null {
  const payload = token?.split('.')[1];
  if (!payload) return null;
  try {
    const claims = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/'))) as unknown;
    const userId = (claims as { userId?: unknown } | null)?.userId;
    return typeof userId === 'number' && Number.isInteger(userId) ? userId : null;
  } catch {
    return null;
  }
}
```

`frontend/src/app/core/user-device-storage.ts`:

```ts
import { Injectable, computed, inject, signal } from '@angular/core';
import { AccountIdentity } from './account-identity';

/** Per-account values that stay on this device: `sfr.user.<id>.<name>` in localStorage. */
@Injectable({ providedIn: 'root' })
export class UserDeviceStorage {
  private readonly identity = inject(AccountIdentity);
  private readonly revision = signal(0);

  readonly ready = computed(() => this.identity.userId() !== null);

  read(name: string): string | null {
    this.revision();
    const key = this.keyFor(name);
    return key === null ? null : localStorage.getItem(key);
  }

  write(name: string, value: string | null): void {
    const key = this.keyFor(name);
    if (key === null) return;
    if (value === null) localStorage.removeItem(key);
    else localStorage.setItem(key, value);
    this.revision.update((count) => count + 1);
  }

  forgetCurrentUser(): void {
    const prefix = this.keyFor('');
    if (prefix === null) return;
    for (let index = localStorage.length - 1; index >= 0; index--) {
      const key = localStorage.key(index);
      if (key?.startsWith(prefix)) localStorage.removeItem(key);
    }
    this.revision.update((count) => count + 1);
  }

  private keyFor(name: string): string | null {
    const userId = this.identity.userId();
    return userId === null ? null : `sfr.user.${userId}.${name}`;
  }
}
```

`AccountSectionComponent`:
- Add `private readonly deviceStorage = inject(UserDeviceStorage);`.
- In `deleteAccount()`, the success handler becomes:

```ts
      next: () => {
        this.deviceStorage.forgetCurrentUser();
        this.auth.logout();
      },
```

Keep the existing comment above it. It explains `logout()`, which is still true.

- [ ] **Step 4: Run the three specs** — Expected: PASS.

- [ ] **Step 5: Prove the new tests can fail.**
  - Swap the `??` operands in `userId`.
  - Drop the `-`/`_` replacement.
  - Drop the trailing `.` from the key prefix. The `sfr.user.70` trap must go red.
  - Drop the `forgetCurrentUser()` call.

  Run each, then restore.

- [ ] **Step 6: Commit**

```bash
cd frontend && npx prettier --write src/app/core/account-identity*.ts src/app/core/user-device-storage*.ts src/app/settings/account-section.component*.ts
git add src/app/core/account-identity*.ts src/app/core/user-device-storage*.ts src/app/settings/account-section.component*.ts
git commit -m "feat(#1143): keep per-account device values under the account id"
```

---

### Task 8: The unread filter moves to the per-user namespace

**Files:**
- Modify: `frontend/src/app/reader/unread-filter.service.ts`, `frontend/src/app/reader/unread-filter.service.spec.ts`
- Modify: `frontend/src/app/reader/list-scroll-reset.spec.ts`. In both `TestBed.configureTestingModule` provider lists, add `{ provide: AccountIdentity, useValue: { userId: signal(1) } }`.
- Modify: `frontend/src/app/reader/reader-shell.component.spec.ts`:
  - The fake `auth.user` gains `id: 1` everywhere it is set: the initial `signal(…)` at ~line 68, and the `auth.user.set(…)` calls at ~140, ~3659, ~3682.
  - Every `'sfr.unread-only'` literal becomes `'sfr.user.1.unread-only'`. Find them with `grep -n "sfr.unread-only"`.
- Modify: `frontend/e2e/list-scroll-reset.spec.ts`. Two e2e specs still used the legacy
  device-wide key (pre-flight conflict P10) and would have broken once it is never
  read. `list-scroll-reset.spec.ts`'s `beforeEach` seeded unread mode with
  `localStorage.setItem('sfr.unread-only', '1')` in an init script; that is replaced
  with turning unread mode on through the UI switch after sign-in (the spec already
  has an `unreadSwitch(page)` helper), and the switch is turned back off in a
  `test.afterEach`, since the per-user value now persists for the admin account
  across runs.
- Modify: `frontend/e2e/saved-searches-combined.spec.ts`. `signInAsAdmin` removed
  the legacy key; that removal is now dead and is replaced with an init script that
  removes any `sfr.user.*.unread-only` keys, so the spec still starts at "All"
  regardless of what an earlier run left behind.

**Interfaces:**
- Consumes: `UserDeviceStorage`.
- Produces: `UnreadFilterService.unreadOnly: Signal<boolean>` (a `computed` now, same call shape) and `set(boolean)`.

- [ ] **Step 1: Rewrite the spec** — `unread-filter.service.spec.ts`:

```ts
import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity } from '../core/account-identity';
import { UnreadFilterService } from './unread-filter.service';

describe('UnreadFilterService', () => {
  let userId: WritableSignal<number | null>;
  const filter = () => TestBed.inject(UnreadFilterService);

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(3);
    TestBed.configureTestingModule({ providers: [{ provide: AccountIdentity, useValue: { userId } }] });
  });

  it('shows everything when nothing is stored', () => {
    expect(filter().unreadOnly()).toBe(false);
  });

  it("reads the account's stored unread-only choice back", () => {
    localStorage.setItem('sfr.user.3.unread-only', '1');
    expect(filter().unreadOnly()).toBe(true);
  });

  it('stores unread-only under the account and forgets it again for all posts', () => {
    const service = filter();
    service.set(true);
    expect(localStorage.getItem('sfr.user.3.unread-only')).toBe('1');
    expect(service.unreadOnly()).toBe(true);

    service.set(false);
    expect(localStorage.getItem('sfr.user.3.unread-only')).toBeNull();
    expect(service.unreadOnly()).toBe(false);
  });

  it("keeps one account's choice from the next", () => {
    filter().set(true);
    userId.set(4);
    expect(filter().unreadOnly()).toBe(false);
  });

  it('drops the old device-wide value instead of adopting it', () => {
    localStorage.setItem('sfr.unread-only', '1');
    expect(filter().unreadOnly()).toBe(false);
    expect(localStorage.getItem('sfr.unread-only')).toBeNull();
  });

  it('reads a garbage stored value as show-all', () => {
    localStorage.setItem('sfr.user.3.unread-only', 'yes');
    expect(filter().unreadOnly()).toBe(false);
  });
});
```

- [ ] **Step 2: Run it and watch it fail** — `docker compose exec -T frontend npm test -- src/app/reader/unread-filter.service.spec.ts`. Expected: FAIL.

- [ ] **Step 3: Implement** — `unread-filter.service.ts`:

```ts
import { Injectable, computed, inject } from '@angular/core';
import { UserDeviceStorage } from '../core/user-device-storage';

const NAME = 'unread-only';
const LEGACY_DEVICE_KEY = 'sfr.unread-only';

@Injectable({ providedIn: 'root' })
export class UnreadFilterService {
  private readonly storage = inject(UserDeviceStorage);

  readonly unreadOnly = computed(() => this.storage.read(NAME) === '1');

  constructor() {
    localStorage.removeItem(LEGACY_DEVICE_KEY);
  }

  set(unreadOnly: boolean): void {
    this.storage.write(NAME, unreadOnly ? '1' : null);
  }
}
```

- [ ] **Step 4: Update the two dependent specs** as listed under Files. Then run: `docker compose exec -T frontend npm test -- src/app/reader/unread-filter.service.spec.ts src/app/reader/list-scroll-reset.spec.ts src/app/reader/reader-shell.component.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/reader/unread-filter.service*.ts src/app/reader/list-scroll-reset.spec.ts src/app/reader/reader-shell.component.spec.ts
git add src/app/reader/unread-filter.service*.ts src/app/reader/list-scroll-reset.spec.ts src/app/reader/reader-shell.component.spec.ts
git commit -m "feat(#1143): keep the unread filter per account"
```

---

### Task 9: `ListOrder` in the model, the query, the API call and the scroll key

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`ListOrder`, `EntryQuery.order`)
- Modify: `frontend/src/app/reader/query.ts` (`Selection.order`, `hasListOrder`, `listOrderOf`, `withListOrder`, `listOrderKey`, `sameSelection`, `queryFromSelection`)
- Modify: `frontend/src/app/reader/reader-api.ts` (`entries` + `pageParams`; delete `searchEntries`, `savedSearchEntries`, `singleSavedSearchEntries`)
- Modify: `frontend/src/app/reader/list-scroll-memory.ts` (`scrollKey`)
- Tests: `query.spec.ts`, `reader-api.spec.ts`, `list-scroll-memory.spec.ts`

**Interfaces:**
- Produces:
  - `export type ListOrder = 'newest' | 'oldest'` (models.ts)
  - `EntryQuery.order?: ListOrder`
  - `Selection.order?: ListOrder`
  - `hasListOrder(s): boolean`
  - `listOrderOf(s): ListOrder`
  - `withListOrder(s, order): Selection`
  - `listOrderKey(s): string | null`

- [ ] **Step 1: Write the failing tests.**

`query.spec.ts` (import the new functions):

```ts
describe('list order', () => {
  const tag: Selection = { kind: 'tag', id: 3, unread: false };

  it('reads an unordered selection as newest first', () => {
    expect(listOrderOf(tag)).toBe('newest');
    expect(listOrderOf(withListOrder(tag, 'oldest'))).toBe('oldest');
  });

  it('never orders the ranked for-you feed', () => {
    const forYou: Selection = { kind: 'for-you', id: null, unread: false };
    expect(hasListOrder(forYou)).toBe(false);
    expect(withListOrder(forYou, 'oldest')).toBe(forYou);
    expect(listOrderKey(forYou)).toBeNull();
  });

  it('keys each feed, tag, saved search and view apart, and every search term together', () => {
    const key = (s: Selection) => listOrderKey(s);
    expect(key({ kind: 'all', id: null, unread: true })).toBe('all');
    expect(key({ kind: 'viewed', id: null, unread: false })).toBe('viewed');
    expect(key({ kind: 'saved-searches', id: null, unread: false })).toBe('saved-searches');
    expect(key(tag)).toBe('tag:3');
    expect(key({ kind: 'subscription', id: 12, unread: false })).toBe('subscription:12');
    expect(key({ kind: 'saved-search', id: 7, unread: false })).toBe('saved-search:7');
    expect(key({ kind: 'search', id: null, unread: false, term: 'angular' })).toBe('search');
    expect(key({ kind: 'search', id: null, unread: false, term: 'react' })).toBe('search');
  });

  it('tells a list and its reversed self apart', () => {
    expect(sameSelection(tag, withListOrder(tag, 'oldest'))).toBe(false);
    expect(sameSelection(tag, withListOrder(tag, 'newest'))).toBe(true);
  });

  it('asks for oldest first only when the selection is ordered so', () => {
    expect(queryFromSelection(withListOrder(tag, 'oldest'))).toEqual({ view: 'all', tag: 3, order: 'oldest' });
    expect(queryFromSelection(tag)).toEqual({ view: 'all', tag: 3 });
    expect(
      queryFromSelection(withListOrder({ kind: 'search', id: null, unread: true, term: 'x y' }, 'oldest')),
    ).toEqual({ view: 'all', q: 'x y', unread: true, order: 'oldest' });
  });
});
```

`reader-api.spec.ts`:

```ts
  it('asks every list endpoint for oldest first only when the query says so', () => {
    api.entries({ view: 'all', order: 'oldest' }).subscribe();
    api.entries({ view: 'all', q: 'testing', order: 'oldest' }).subscribe();
    api.entries({ view: 'saved-searches', order: 'oldest' }).subscribe();
    api.entries({ view: 'all', savedSearchId: 42, order: 'oldest' }).subscribe();
    api.entries({ view: 'all', order: 'newest' }).subscribe();

    const requests = ctrl.match((r) => r.url.startsWith('https://api.test/api/entries'));
    expect(requests.map((r) => r.request.params.get('order'))).toEqual(['asc', 'asc', 'asc', 'asc', null]);
    for (const request of requests) request.flush({ entries: [], nextCursor: null });
  });
```

`list-scroll-memory.spec.ts`, inside `describe('scrollKey')`:

```ts
  it('is distinct per list order', () => {
    const tag = sel({ kind: 'tag', id: 3 });
    expect(scrollKey(tag)).not.toBe(scrollKey({ ...tag, order: 'oldest' }));
  });
```

- [ ] **Step 2: Run them and watch them fail** — `docker compose exec -T frontend npm test -- src/app/reader/query.spec.ts src/app/reader/reader-api.spec.ts src/app/reader/list-scroll-memory.spec.ts`. Expected: FAIL.

- [ ] **Step 3: Implement.**

`models.ts` — above `EntryQuery`, add `export type ListOrder = 'newest' | 'oldest';`, and add the field `order?: ListOrder;` to `EntryQuery`.

`query.ts`:
- Import `ListOrder` from `./models`.
- Add the field to `Selection`, after `term?`:

```ts
  /** Absent means newest first; only a list `hasListOrder` accepts ever carries one. */
  order?: ListOrder;
```

- `sameSelection` gains `&& listOrderOf(a) === listOrderOf(b)`.
- New functions (next to `withUnreadPreference`):

```ts
/** Whether the list offers the newest/oldest-first toggle; the ranked for-you
 *  feed has no date order to reverse. */
export function hasListOrder(s: Selection): boolean {
  return s.kind !== 'for-you';
}

export function listOrderOf(s: Selection): ListOrder {
  return s.order ?? 'newest';
}

/** Applies an order to a selection parsed from the URL, which never carries one. */
export function withListOrder(selection: Selection, order: ListOrder): Selection {
  return hasListOrder(selection) && order === 'oldest' ? { ...selection, order } : selection;
}

/** Where a list's order is remembered: per feed, tag, saved search and view, and
 *  once for every direct search, whose terms are throwaway. */
export function listOrderKey(s: Selection): string | null {
  if (!hasListOrder(s)) return null;
  if (s.kind === 'search') return 'search';
  return s.id === null ? s.kind : `${s.kind}:${s.id}`;
}
```

- `queryFromSelection` becomes a wrapper. Rename the existing function, body unchanged, to `function viewQuery(s: Selection): EntryQuery` (not exported), and add:

```ts
export function queryFromSelection(s: Selection): EntryQuery {
  const query = viewQuery(s);
  return listOrderOf(s) === 'oldest' ? { ...query, order: 'oldest' } : query;
}
```

`reader-api.ts` — replace `entries()` and delete the three private list methods with their docblocks:

```ts
  entries(query: EntryQuery, cursor?: string | null): Observable<EntriesPage> {
    const params = this.pageParams(query, cursor);
    if (query.savedSearchId != null) {
      return this.http.get<EntriesPage>(
        `${this.base}/api/entries/saved-searches/${query.savedSearchId}`,
        { params },
      );
    }
    if (query.q) {
      return this.http.get<EntriesPage>(`${this.base}/api/entries/search`, {
        params: params.set('q', query.q),
      });
    }
    if (query.view === 'saved-searches') {
      return this.http.get<EntriesPage>(`${this.base}/api/entries/saved-searches`, { params });
    }
    let listParams = params.set('view', query.view);
    if (query.subscription != null) listParams = listParams.set('subscription', query.subscription);
    if (query.tag != null) listParams = listParams.set('tag', query.tag);
    return this.http.get<EntriesPage>(`${this.base}/api/entries`, { params: listParams });
  }

  /** What every list endpoint takes alike: the page, the unread refinement and the order. */
  private pageParams(query: EntryQuery, cursor?: string | null): HttpParams {
    let params = new HttpParams().set('limit', PAGE_SIZE);
    if (query.unread) params = params.set('unread', '1');
    if (query.order === 'oldest') params = params.set('order', 'asc');
    if (cursor) params = params.set('cursor', cursor);
    return params;
  }
```

`list-scroll-memory.ts` — import `listOrderOf`. The key becomes:
`` `feed-reader:list-scroll:${s.kind}:${s.id ?? ''}:${s.unread ? 'u' : 'a'}:${s.term ?? ''}:${listOrderOf(s)}` ``. In the function docblock, change "unread-vs-all" to "unread-vs-all and the list order".

- [ ] **Step 4: Run the three specs plus the whole reader folder** — `docker compose exec -T frontend npm test -- src/app/reader`. Expected: PASS. Every pre-existing `reader-api.spec` case must pass unchanged. That is the proof the refactor kept the non-order params.

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/reader/models.ts src/app/reader/query*.ts src/app/reader/reader-api*.ts src/app/reader/list-scroll-memory*.ts
git add src/app/reader/models.ts src/app/reader/query*.ts src/app/reader/reader-api*.ts src/app/reader/list-scroll-memory*.ts
git commit -m "feat(#1143): carry the list order from the selection to the api"
```

---

### Task 10: The header toggle

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` (`orderChange` output; `hasListOrder` and `oldestFirst` computeds)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html` (the button, directly before `@if (hasUnreadFilter())`)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (the `reader` section, next to `markAllRead`)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`
- Modify: `frontend/e2e/list-header-actions-mobile.spec.ts` (the direct-search count `toHaveCount(3)` → `toHaveCount(4)`, ~line 64)

**Interfaces:**
- Consumes: `hasListOrder`, `listOrderOf`, `ListOrder`.
- Produces: `EntryListComponent.orderChange: OutputEmitterRef<ListOrder>`.

- [ ] **Step 1: Write the failing tests** in `entry-list.component.spec.ts`, as a new `describe` after `'unread filter switch'`:

```ts
  describe('list order toggle', () => {
    const toggle = (f: ComponentFixture<EntryListComponent>) =>
      f.nativeElement.querySelector('.list-order') as HTMLButtonElement;

    it('shows newest first and asks for oldest first', () => {
      const f = mount({ selection: { kind: 'tag', id: 3, unread: false } });
      const asked: ListOrder[] = [];
      f.componentInstance.orderChange.subscribe((order) => asked.push(order));

      expect(toggle(f).querySelector('.txt')?.textContent?.trim()).toBe('Newest first');
      expect(toggle(f).querySelector('app-icon')?.textContent?.trim()).toBe('arrow_downward');
      toggle(f).click();

      expect(asked).toEqual(['oldest']);
    });

    it('shows oldest first and asks for newest first', () => {
      const f = mount({ selection: { kind: 'tag', id: 3, unread: false, order: 'oldest' } });
      const asked: ListOrder[] = [];
      f.componentInstance.orderChange.subscribe((order) => asked.push(order));

      expect(toggle(f).querySelector('.txt')?.textContent?.trim()).toBe('Oldest first');
      expect(toggle(f).querySelector('app-icon')?.textContent?.trim()).toBe('arrow_upward');
      expect(toggle(f).getAttribute('aria-label')).toBe('Oldest first, switch to newest first');
      toggle(f).click();

      expect(asked).toEqual(['newest']);
    });

    it('offers the toggle on every list but for you', () => {
      const kinds = ['all', 'tag', 'subscription', 'favorites', 'kept', 'viewed'] as const;
      for (const kind of [...kinds, 'saved-searches', 'saved-search', 'search'] as const) {
        const f = mount({ selection: { kind, id: 1, unread: false, term: 'x' }, canMarkAllRead: false });
        expect(toggle(f)).not.toBeNull();
      }
      const forYou = mount({ selection: { kind: 'for-you', id: null, unread: false } });
      expect(toggle(forYou)).toBeNull();
    });

    it('sits directly before the unread switch', () => {
      const f = mount({ selection: { kind: 'all', id: null, unread: false } });
      expect(toggle(f).nextElementSibling?.classList.contains('unread-switch')).toBe(true);
    });
  });
```

Import `ComponentFixture` if it is missing, and `ListOrder` from `'../models'`.

- [ ] **Step 2: Run it and watch it fail** — `docker compose exec -T frontend npm test -- src/app/reader/entry-list/entry-list.component.spec.ts`. Expected: FAIL.

- [ ] **Step 3: Implement.**

`entry-list.component.ts`:
- Import `hasListOrder` and `listOrderOf` from `'../query'` and `ListOrder` from `'../models'`.
- Next to `unreadOnlyChange`:

```ts
  readonly orderChange = output<ListOrder>();
```

- Next to `hasUnreadFilter`:

```ts
  readonly hasListOrder = computed(() => hasListOrder(this.selection()));
  readonly oldestFirst = computed(() => listOrderOf(this.selection()) === 'oldest');
```

`entry-list.component.html`, directly before `@if (hasUnreadFilter()) {`:

```html
    @if (hasListOrder()) {
      <button
        appListAction
        class="list-order"
        type="button"
        [attr.aria-label]="
          (oldestFirst() ? 'reader.sortedOldestFirst' : 'reader.sortedNewestFirst') | transloco
        "
        [attr.title]="
          (oldestFirst() ? 'reader.sortedOldestFirst' : 'reader.sortedNewestFirst') | transloco
        "
        (click)="orderChange.emit(oldestFirst() ? 'newest' : 'oldest')"
      >
        <app-icon [name]="oldestFirst() ? 'arrow_upward' : 'arrow_downward'" size="sm" />
        <span class="txt">{{
          (oldestFirst() ? 'reader.oldestFirst' : 'reader.newestFirst') | transloco
        }}</span>
      </button>
    }
```

`en.json` (`reader` section, after `"markAllRead"`):

```json
    "newestFirst": "Newest first",
    "oldestFirst": "Oldest first",
    "sortedNewestFirst": "Newest first, switch to oldest first",
    "sortedOldestFirst": "Oldest first, switch to newest first",
```

`de.json` (same place):

```json
    "newestFirst": "Neueste zuerst",
    "oldestFirst": "Älteste zuerst",
    "sortedNewestFirst": "Neueste zuerst, auf älteste zuerst umstellen",
    "sortedOldestFirst": "Älteste zuerst, auf neueste zuerst umstellen",
```

`e2e/list-header-actions-mobile.spec.ts`: in the direct-search test, change `toHaveCount(3)` to `toHaveCount(4)`. The test name "a direct search uses the same icon-only form as every other list" stays true.

- [ ] **Step 4: Run the spec, then the full gate and the build**

```bash
docker compose exec -T frontend npm test -- src/app/reader/entry-list/entry-list.component.spec.ts
docker compose exec -T frontend npm run check
docker compose exec -T frontend npm run build
```

Expected: PASS; `check` exits 0 (the i18n parity spec included); the build compiles the template (`strictTemplates`).

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/reader/entry-list/entry-list.component.* e2e/list-header-actions-mobile.spec.ts
git add src/app/reader/entry-list/entry-list.component.* public/i18n/en.json public/i18n/de.json e2e/list-header-actions-mobile.spec.ts
git commit -m "feat(#1143): newest/oldest-first toggle in the list header"
```

---

### Task 11: Remember the order, apply both preferences, gate the first load

**Files:**
- Create: `frontend/src/app/reader/list-order.service.ts`, `list-order.service.spec.ts`
- Create: `frontend/src/app/reader/list-preferences.service.ts`, `list-preferences.service.spec.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (the `selection` computed ~line 287, the load effect ~line 612, injections)
- Modify: `frontend/src/app/reader/reader-shell.component.html` (both `<app-entry-list>` elements, ~lines 136 and 202: add `(orderChange)`)
- Modify: `frontend/src/app/reader/list-scroll-reset.ts` (use `ListPreferences`; treat an order flip like an unread flip)
- Modify (review round 1): `frontend/src/app/core/auth.service.ts` (`accountLoadFailed`), `core/account-identity.ts` (`settled`), `core/user-device-storage.ts` (drop the now-unused `ready`)
- Tests: `reader-shell.component.spec.ts`, `list-scroll-reset.spec.ts`, `core/auth.service.spec.ts`, `core/account-identity.spec.ts`, `core/user-device-storage.spec.ts`

**Amendment (review round 1) — the gate never hangs.** A pre-deploy token has no `userId` claim, so the id
arrives with `/api/me`; if that call fails (network, 5xx) the id never arrives. The first-load gate opens
when the id is known **or** the `/api/me` attempt has failed; the list then loads with the defaults (all
posts, newest first), as before this task.
- `AuthService.accountLoadFailed: WritableSignal<boolean>` — `loadMe` sets it on error, clears it on
  success; `logout` clears it.
- `AccountIdentity.settled = computed(() => this.userId() !== null || this.auth.accountLoadFailed())`.
- `ListPreferences.ready = inject(AccountIdentity).settled`. `UserDeviceStorage.ready` (Task 7) is
  deleted, having no reader left.
- Tests: `AuthService` records/clears the failure and forgets it on logout; `AccountIdentity` is settled
  on an id, unsettled while loading, settled on a failure with no id; `ListPreferences` is ready with the
  defaults when the account never loads; the shell, with the real `AuthService` (reset + reconfigure via
  a `configureShell(authProviders)` helper), flushes `/api/me` with a 500 and expects `/api/entries` with
  no `order` and `view=all`. `ListOrderService` reads a non-array stored value (`'"tag:3"'`) as newest;
  `parseViewKeys` keeps `try` around `JSON.parse` only, so that branch is no longer shadowed by the catch.
  The flipped-list shell test also asserts the reload has no `cursor`.

**Interfaces:**
- Consumes: `AccountIdentity`, `UserDeviceStorage`, `UnreadFilterService`, `withUnreadPreference`, `withListOrder`, `listOrderKey`.
- Produces:
  - `ListOrderService.orderFor(Selection): ListOrder`
  - `ListOrderService.set(Selection, ListOrder): void`
  - `ListOrderService.oldestFirstViews: Signal<ReadonlySet<string>>`
  - `ListPreferences.appliedTo(Selection): Selection`
  - `ListPreferences.ready: Signal<boolean>`

- [ ] **Step 1: Write the failing tests.**

`list-order.service.spec.ts`:

```ts
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity } from '../core/account-identity';
import { ListOrderService } from './list-order.service';
import { Selection } from './query';

describe('ListOrderService', () => {
  const tag3: Selection = { kind: 'tag', id: 3, unread: false };
  const tag4: Selection = { kind: 'tag', id: 4, unread: false };
  const stored = () => localStorage.getItem('sfr.user.5.oldest-first-views');
  const service = () => TestBed.inject(ListOrderService);

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [{ provide: AccountIdentity, useValue: { userId: signal(5) } }],
    });
  });

  it('starts every list newest first', () => {
    expect(service().orderFor(tag3)).toBe('newest');
  });

  it('remembers oldest first for the one list it was set on', () => {
    service().set(tag3, 'oldest');
    expect(service().orderFor(tag3)).toBe('oldest');
    expect(service().orderFor(tag4)).toBe('newest');
    expect(JSON.parse(stored()!)).toEqual(['tag:3']);
  });

  it('keeps the other lists when one flips back', () => {
    service().set(tag3, 'oldest');
    service().set(tag4, 'oldest');
    service().set(tag3, 'newest');
    expect(JSON.parse(stored()!)).toEqual(['tag:4']);
  });

  it('drops the stored value once no list is oldest first', () => {
    service().set(tag3, 'oldest');
    service().set(tag3, 'newest');
    expect(stored()).toBeNull();
  });

  it('shares one order across every direct search term', () => {
    service().set({ kind: 'search', id: null, unread: false, term: 'angular' }, 'oldest');
    expect(service().orderFor({ kind: 'search', id: null, unread: false, term: 'react' })).toBe('oldest');
  });

  it('never stores an order for for you', () => {
    service().set({ kind: 'for-you', id: null, unread: false }, 'oldest');
    expect(localStorage.length).toBe(0);
  });

  it('reads an unparseable stored value as no oldest-first list', () => {
    localStorage.setItem('sfr.user.5.oldest-first-views', '{nope');
    expect(service().orderFor(tag3)).toBe('newest');
  });

  it('ignores stored entries that are not keys', () => {
    localStorage.setItem('sfr.user.5.oldest-first-views', '[3, "tag:3"]');
    expect(service().orderFor(tag3)).toBe('oldest');
    expect(service().oldestFirstViews().size).toBe(1);
  });

  // Guards `sameViewKeys`: every storage write re-reads this computed (import UnreadFilterService).
  it('keeps the same views when another preference of the account is written', () => {
    service().set(tag3, 'oldest');
    const before = service().oldestFirstViews();

    TestBed.inject(UnreadFilterService).set(true);

    expect(service().oldestFirstViews()).toBe(before);
  });
});
```

`list-preferences.service.spec.ts`:

```ts
import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity } from '../core/account-identity';
import { ListOrderService } from './list-order.service';
import { ListPreferences } from './list-preferences.service';
import { UnreadFilterService } from './unread-filter.service';

describe('ListPreferences', () => {
  let userId: WritableSignal<number | null>;

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(2);
    TestBed.configureTestingModule({ providers: [{ provide: AccountIdentity, useValue: { userId } }] });
  });

  it("applies the account's unread filter and this list's order to a parsed selection", () => {
    TestBed.inject(UnreadFilterService).set(true);
    TestBed.inject(ListOrderService).set({ kind: 'tag', id: 9, unread: false }, 'oldest');

    const applied = TestBed.inject(ListPreferences).appliedTo({ kind: 'tag', id: 9, unread: false });

    expect(applied).toEqual({ kind: 'tag', id: 9, unread: true, order: 'oldest' });
  });

  it('is ready only once the account is known', () => {
    const preferences = TestBed.inject(ListPreferences);
    expect(preferences.ready()).toBe(true);
    userId.set(null);
    expect(preferences.ready()).toBe(false);
  });
});
```

`reader-shell.component.spec.ts` — a new `describe('list order (#1143)')`. Use the file's own `boot()`, `qp`, `ctrl` and `auth`:

```ts
  describe('list order (#1143)', () => {
    it('reloads a flipped list oldest first and remembers it for that list only', () => {
      const f = boot();

      (f.nativeElement.querySelector('.list-order') as HTMLButtonElement).click();
      f.detectChanges();
      const flipped = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
      expect(flipped.request.params.get('order')).toBe('asc');
      flipped.flush({ entries: [], nextCursor: null });
      expect(JSON.parse(localStorage.getItem('sfr.user.1.oldest-first-views')!)).toEqual(['all']);

      qp.next(convertToParamMap({ tag: '9' }));
      f.detectChanges();
      const other = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
      expect(other.request.params.get('order')).toBeNull();
      other.flush({ entries: [], nextCursor: null });
    });

    it('holds the first list load until the account is known', () => {
      auth.user.set({ email: 'a@b.c', preferences: { passkeyOfferAnswered: true } } as never);
      const f = TestBed.createComponent(ReaderShellComponent);
      f.detectChanges();
      expect(ctrl.match((r) => r.url === 'https://api.test/api/entries')).toHaveLength(0);

      auth.user.set({ id: 1, email: 'a@b.c', preferences: { passkeyOfferAnswered: true } });
      f.detectChanges();
      ctrl
        .expectOne((r) => r.url === 'https://api.test/api/entries')
        .flush({ entries: [], nextCursor: null });
    });
  });
```

The second test leaves the other boot requests (subscriptions, tags, saved searches, recommendations, version) pending. Drain them the way `boot()` does if this file verifies the controller after each test. The cast is needed because the fake's type now includes `id`.

`list-scroll-reset.spec.ts` — in `describe('ListScrollReset')`:

```ts
  it('forgets the list a flip of its order shows', () => {
    navigate('/?tag=5');
    TestBed.tick();

    TestBed.inject(ListOrderService).set({ kind: 'tag', id: 5, unread: false }, 'oldest');
    TestBed.tick();

    expect(memory.forget).toHaveBeenCalledWith({ kind: 'tag', id: 5, unread: false, order: 'oldest' });
  });

  it('reads a flip of the unread filter as one flip, not also as one of the order', () => {
    navigate('/?tag=5');
    TestBed.tick();

    TestBed.inject(UnreadFilterService).set(true);
    TestBed.tick();

    expect(memory.forget).toHaveBeenCalledTimes(1);
  });
```

- [ ] **Step 2: Run them and watch them fail** — `docker compose exec -T frontend npm test -- src/app/reader/list-order.service.spec.ts src/app/reader/list-preferences.service.spec.ts src/app/reader/reader-shell.component.spec.ts src/app/reader/list-scroll-reset.spec.ts`. Expected: FAIL.

- [ ] **Step 3: Implement.**

`list-order.service.ts`:

```ts
import { Injectable, computed, inject } from '@angular/core';
import { UserDeviceStorage } from '../core/user-device-storage';
import { ListOrder } from './models';
import { Selection, listOrderKey } from './query';

const NAME = 'oldest-first-views';

@Injectable({ providedIn: 'root' })
export class ListOrderService {
  private readonly storage = inject(UserDeviceStorage);

  readonly oldestFirstViews = computed(() => parseViewKeys(this.storage.read(NAME)), {
    equal: sameViewKeys,
  });

  orderFor(selection: Selection): ListOrder {
    const key = listOrderKey(selection);
    return key !== null && this.oldestFirstViews().has(key) ? 'oldest' : 'newest';
  }

  set(selection: Selection, order: ListOrder): void {
    const key = listOrderKey(selection);
    if (key === null) return;
    const views = new Set(this.oldestFirstViews());
    if (order === 'oldest') views.add(key);
    else views.delete(key);
    this.storage.write(NAME, views.size === 0 ? null : JSON.stringify([...views].sort()));
  }
}

function parseViewKeys(stored: string | null): ReadonlySet<string> {
  const parsed = parsedJson(stored);
  if (!Array.isArray(parsed)) return new Set();
  return new Set(parsed.filter((key): key is string => typeof key === 'string'));
}

function parsedJson(stored: string | null): unknown {
  if (stored === null) return null;
  try {
    return JSON.parse(stored) as unknown;
  } catch {
    return null;
  }
}

function sameViewKeys(a: ReadonlySet<string>, b: ReadonlySet<string>): boolean {
  return a.size === b.size && [...a].every((key) => b.has(key));
}
```

`list-preferences.service.ts`:

```ts
import { Injectable, inject } from '@angular/core';
import { AccountIdentity } from '../core/account-identity';
import { ListOrderService } from './list-order.service';
import { Selection, withListOrder, withUnreadPreference } from './query';
import { UnreadFilterService } from './unread-filter.service';

/** The list a URL names, as this account reads it: neither the unread filter nor the
 *  order rides in the URL, so both are applied here. */
@Injectable({ providedIn: 'root' })
export class ListPreferences {
  private readonly unreadFilter = inject(UnreadFilterService);
  private readonly listOrder = inject(ListOrderService);

  readonly ready = inject(AccountIdentity).settled;

  appliedTo(selection: Selection): Selection {
    return withListOrder(
      withUnreadPreference(selection, this.unreadFilter.unreadOnly()),
      this.listOrder.orderFor(selection),
    );
  }
}
```

`reader-shell.component.ts`:
- Inject `private readonly listPreferences = inject(ListPreferences);` and `readonly listOrder = inject(ListOrderService);`.
- `selection` becomes `computed(() => this.listPreferences.appliedTo(this.parsed().selection), { equal: sameSelection })`.
- Drop the `withUnreadPreference` import if it is now unused.
- The list-load effect (~line 612) starts with:

```ts
    effect(() => {
      if (!this.listPreferences.ready()) return;
      const q = queryFromSelection(this.selection());
```

`reader-shell.component.html` — on both `<app-entry-list>` elements, next to `(unreadOnlyChange)="unreadFilter.set($event)"`, add `(orderChange)="listOrder.set(selection(), $event)"`.

`list-scroll-reset.ts`:
- Inject `ListPreferences` and `ListOrderService` alongside `UnreadFilterService`.
- Replace the unread-only subscription with one over both preferences:

```ts
    // A flip is no navigation; as a root effect this erases before the entry list reads the key.
    merge(flipsOf(this.unreadFilter.unreadOnly), flipsOf(this.listOrder.oldestFirstViews))
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.onListPreferenceFlip());
```

- Add the module-level helper (import `Signal` from `@angular/core`, and `merge` and `Observable` from `rxjs`):

```ts
function flipsOf<T>(preference: Signal<T>): Observable<T> {
  return toObservable(preference).pipe(startWith(preference()), distinctUntilChanged(), skip(1));
}
```

- Rename `onUnreadFilterFlip` → `onListPreferenceFlip`.
- `forgetShownList` becomes:

```ts
  /** The URL carries neither the unread filter nor the order, so the key gets them here.
   *  A place holding them would make an article opened after a flip read as a new list. */
  private forgetShownList(place: ReaderPlace): void {
    this.memory.forget(this.listPreferences.appliedTo(place.shown));
  }
```

- Drop the `withUnreadPreference` import.
- Add the `ListOrderService` import to the spec.

- [ ] **Step 4: Run the four specs, then the whole suite**

```bash
docker compose exec -T frontend npm test -- src/app/reader/list-order.service.spec.ts src/app/reader/list-preferences.service.spec.ts src/app/reader/reader-shell.component.spec.ts src/app/reader/list-scroll-reset.spec.ts
docker compose exec -T frontend npm run check
docker compose exec -T frontend npm run build
```

Expected: PASS / exit 0.

- [ ] **Step 5: Prove the new tests can fail.**
  - Remove the `ready()` guard.
  - Drop `withListOrder` from `appliedTo`.
  - Remove the `flipsOf(this.listOrder…)` stream.
  - Use `equal` identity instead of `sameViewKeys`. Does any test notice? If none does, say so; a spurious flip would forget the scroll memory.
    (Amended in Task 11: without the two equality-guard tests above, none did. With them, both fail.)

  Restore after each.

- [ ] **Step 6: Commit**

```bash
cd frontend && npx prettier --write src/app/reader/list-order.service*.ts src/app/reader/list-preferences.service*.ts src/app/reader/reader-shell.component.* src/app/reader/list-scroll-reset*.ts
git add src/app/reader/list-order.service*.ts src/app/reader/list-preferences.service*.ts src/app/reader/reader-shell.component.* src/app/reader/list-scroll-reset*.ts
git commit -m "feat(#1143): remember each list's order per account and reload on a flip"
```

---

### Task 12: The magazine planner stays prefix-stable in either order

`activeSourceCount` anchors its 24-hour window on the newest loaded entry. In oldest first, that entry changes with every appended page. A page of newer, single-source entries can then switch collapse off and re-plan blocks the reader already scrolled past. Anchor on the first entry in display order.

**Files:**
- Modify: `frontend/src/app/reader/magazine/magazine-planner.ts` (`activeSourceCount` ~line 155; the "newest" wording in the docblocks of `FEATURED_LEAD` ~35, `LEAD_IMAGE_REACH` ~46, the comment at ~74, `leadWithImage` ~220 and `emitFeaturedLead` ~267)
- Modify: `frontend/src/app/reader/magazine/magazine-planner.spec.ts`

- [ ] **Step 1: Write the failing test** in `magazine-planner.spec.ts`, in `describe('planMagazine')`:

```ts
  it('judges the collapse gate from the first entry, so a newer page appended oldest first keeps it', () => {
    const firstPage = [
      ...many(12, (i) => big(i, { subscriptionId: (i % 3) + 2, publishedAt: at(100 - i) })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Burst', publishedAt: at(88 - i) })),
      ...many(12, (i) => big(200 + i, { subscriptionId: (i % 3) + 2, publishedAt: at(80 - i) })),
    ];
    const newerPage = many(30, (i) =>
      big(300 + i, { subscriptionId: 9, source: 'Late', publishedAt: at(30 - i) }),
    );

    const burstGroup = (blocks: MagazineBlock[]) =>
      blocks.find((b) => b.kind === 'group' && b.entries.some((entry) => entry.source === 'Burst'));

    const before = planMagazine({ entries: firstPage, grouping: true, complete: true });
    const after = planMagazine({ entries: [...firstPage, ...newerPage], grouping: true, complete: true });

    expect(burstGroup(before)).toBeDefined();
    expect(burstGroup(after)).toBeDefined();
  });
```

If `before` has no Burst group with these values (the run qualification also depends on `detectRun`), adjust the fixture until it does. Paste the adjusted fixture into the report. The test is only meaningful once it FAILS on the current code, where the appended single-source page leaves one active source, disables collapse and so drops the Burst group.

- [ ] **Step 2: Run it and watch it fail** — `docker compose exec -T frontend npm test -- src/app/reader/magazine/magazine-planner.spec.ts`. Expected: the new test FAILS on `burstGroup(after)`.

- [ ] **Step 3: Implement** — replace `activeSourceCount` and its docblock:

```ts
/** Distinct sources active within ACTIVE_WINDOW_MS of the first entry. Anchored on
 *  the first entry, not the newest or the wall clock, so it is prefix-stable in either
 *  list order: an appended page can only ADD a source, never remove one. */
function activeSourceCount(entries: EntryDto[]): number {
  if (entries.length === 0) return 0;
  const anchor = effectiveTime(entries[0]);
  return distinctSources(
    entries.filter((entry) => Math.abs(effectiveTime(entry) - anchor) <= ACTIVE_WINDOW_MS),
  );
}
```

In the docblocks listed under Files, change "newest" to "first" where it means position in the list: "The first entries of a collapsing run…", "…when the first entries have none", "…when the first are image-less", "If the first entry has no usable image…", "The run's first entries, laid out…".

- [ ] **Step 4: Run the planner spec and the reader folder** — `docker compose exec -T frontend npm test -- src/app/reader`. Expected: PASS. If a pre-existing test changes, report which one and why. A newest-first list whose first entry is not its latest by `publishedAt ?? createdAt` is the only case where behaviour moves.

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/reader/magazine/magazine-planner*.ts
git add src/app/reader/magazine/magazine-planner*.ts
git commit -m "fix(#1143): anchor the magazine collapse window on the first entry"
```

---

### Task 13: End-to-end proof, mark-above included

Preconditions:
- Run from **this checkout** — the one that ran `docker compose up`. From a worktree, the preflight guard fails, and it should.
- Containers must be current:
  - `docker compose exec php bin/console cache:clear` (the new listener and the new service arguments)
  - `docker compose restart frontend`
  - Then prove the served bundle has the toggle: fetch `http://localhost:4200/main.js`, find the reader chunk, grep it for `list-order`.
- The seeded admin must exist (`app:e2e:seed-admin`).

**Files:**
- Create: `frontend/e2e/list-order.spec.ts`
- Modify:
  - `frontend/e2e/list-header-count-one-line.spec.ts` (~line 83)
  - `frontend/e2e/saved-search-layout.spec.ts` (~line 42)

  In both, the stubbed `/api/me` body gains `id: 1`. The first list load now waits for an account id, and their stub token carries no claim.

- [ ] **Step 1: Write the spec** — `frontend/e2e/list-order.spec.ts`. It signs in for real, so the real JWT claim keys the storage, and owns every list it asserts on:

```ts
import { expect, Page, test } from '@playwright/test';

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const TAG = { id: 7101, name: 'Order fixture', color: null, icon: null, position: 0 };
const ENTRY_COUNT = 40;

/** Entry n is published on day n, so ascending id is ascending date. */
function entry(id: number) {
  const day = new Date(Date.UTC(2026, 6, 1) + id * 86_400_000).toISOString();
  return {
    id,
    title: `Order fixture entry ${id}`,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: 'A fixture summary, long enough to give the row some height.',
    excerpt: 'Fixture body.',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: day,
    createdAt: day,
    subscriptionId: 7102,
    source: 'Order fixture feed',
    faviconUrl: null,
    isHidden: false,
    isFavorite: false,
    isKept: false,
  };
}

const OLDEST_FIRST = Array.from({ length: ENTRY_COUNT }, (_, i) => entry(i + 1));
const NEWEST_FIRST = [...OLDEST_FIRST].reverse();

const SUBSCRIPTIONS = {
  subscriptions: [
    {
      id: 7102,
      feedId: 7103,
      title: 'Order fixture feed',
      customTitle: null,
      lastFetchedAt: '2026-08-01T10:00:00+00:00',
      feedUrl: 'https://fixtures.invalid/feed.xml',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: '2026-08-01T10:00:00+00:00',
      tags: [TAG],
      unreadCount: ENTRY_COUNT,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
};

interface Recorded {
  listRequests: URL[];
  markedIds: number[][];
}

async function stubReaderData(page: Page): Promise<Recorded> {
  const recorded: Recorded = { listRequests: [], markedIds: [] };
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const url = new URL(route.request().url());
      recorded.listRequests.push(url);
      const entries = url.searchParams.get('order') === 'asc' ? OLDEST_FIRST : NEWEST_FIRST;
      await route.fulfill({ status: 200, json: { entries, nextCursor: null } });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/entries/mark-read-batch',
    async (route) => {
      recorded.markedIds.push((route.request().postDataJSON() as { ids: number[] }).ids);
      await route.fulfill({ status: 204 });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/subscriptions',
    (route) => route.fulfill({ status: 200, json: SUBSCRIPTIONS }),
  );
  await page.route(
    (url) => url.pathname === '/api/tags',
    (route) => route.fulfill({ status: 200, json: { tags: [TAG] } }),
  );
  return recorded;
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar.or(page.getByRole('alert'))).toBeVisible();
  return sidebar.isVisible();
}

const lastListRequest = (recorded: Recorded): URL => recorded.listRequests.at(-1)!;

test.describe('list order (#1143)', () => {
  test('a flipped list loads oldest first, stays so after a reload, and only for that list', async ({
    page,
  }) => {
    const recorded = await stubReaderData(page);
    test.skip(!(await signInAsAdmin(page)), 'seeded admin login unavailable');

    await page.goto(`/?tag=${TAG.id}`);
    await expect(page.getByText('Order fixture entry 40', { exact: true })).toBeVisible();
    await expect(page.locator('.list-header .list-order .txt')).toHaveText('Newest first');

    await page.locator('.list-header .list-order').click();
    await expect(page.locator('.list-header .list-order .txt')).toHaveText('Oldest first');
    await expect(page.getByText('Order fixture entry 1', { exact: true })).toBeVisible();
    expect(lastListRequest(recorded).searchParams.get('order')).toBe('asc');

    await page.reload();
    await expect(page.locator('.list-header .list-order .txt')).toHaveText('Oldest first');
    expect(lastListRequest(recorded).searchParams.get('order')).toBe('asc');

    await page.goto('/');
    await expect(page.locator('.list-header .list-order .txt')).toHaveText('Newest first');
    expect(lastListRequest(recorded).searchParams.get('order')).toBeNull();
  });

  test('mark everything above as read marks the older rows in an oldest-first list', async ({ page }) => {
    const recorded = await stubReaderData(page);
    test.skip(!(await signInAsAdmin(page)), 'seeded admin login unavailable');

    await page.goto(`/?tag=${TAG.id}`);
    await page.locator('.list-header .list-order').click();
    await expect(page.getByText('Order fixture entry 1', { exact: true })).toBeVisible();

    await page.locator('app-entry-list .rows').first().evaluate((el) => el.scrollTo({ top: 1500 }));
    await page.locator('.mark-above').click();
    await page.locator('[data-testid=confirm]').click();

    await expect.poll(() => recorded.markedIds.length).toBe(1);
    const marked = recorded.markedIds[0];
    expect(marked).toContain(1);
    expect(Math.max(...marked)).toBeLessThan(ENTRY_COUNT / 2);
  });
});
```

- [ ] **Step 2: Run it and the header/layout specs this branch touches**

```bash
cd frontend
npx playwright test e2e/list-order.spec.ts e2e/list-header-actions-mobile.spec.ts e2e/list-header-narrow-pane.spec.ts e2e/list-header-count-one-line.spec.ts e2e/saved-search-layout.spec.ts e2e/list-scroll-reset.spec.ts e2e/magazine-smoke.spec.ts
```

Expected: all pass, and no skip on the two new tests. If `list-header-count-one-line` fails because the extra labelled action wraps the header, report it with a screenshot. Do not shrink the label; the unified-header rule forbids per-action short labels.

- [ ] **Step 3: Prove the mark-above test discriminates.** Temporarily make `stubReaderData` answer `NEWEST_FIRST` for `asc` too. The test must fail on `toContain(1)`. Restore it.

- [ ] **Step 4: Commit**

```bash
npx prettier --write e2e/list-order.spec.ts e2e/list-header-count-one-line.spec.ts e2e/saved-search-layout.spec.ts
git add e2e/list-order.spec.ts e2e/list-header-count-one-line.spec.ts e2e/saved-search-layout.spec.ts
git commit -m "test(#1143): e2e for the list order toggle and oldest-first mark-above"
```

---

### Task 14: Whole-branch verification

- [ ] **Step 1: Backend, both legs, and the static gates**

```bash
cd backend
composer check
for f in $(git diff --name-only origin/develop -- src | sed 's#^backend/##'); do vendor/bin/phpmd "$f" text phpmd.xml.dist; done
php bin/phpunit
docker compose exec php composer test
composer infection:diff
```

Expected: all green. `infection:diff` must meet `minMsi`. Escaped mutants on touched lines need a test, never a lower threshold.

- [ ] **Step 2: PhpStorm inspections** on every changed PHP file (`mcp__phpstorm__lint_files`). Block on ERROR and WARNING.

- [ ] **Step 3: Frontend gate and build** — `docker compose exec -T frontend npm run check` then `docker compose exec -T frontend npm run build`. Expected: exit 0.

- [ ] **Step 4: Scan the dev log** — ``ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'``. Expected: nothing new from this branch.

- [ ] **Step 5: Manual check in the browser (magazine, the primary layout).** On `https://localhost:4200`:
  - Flip a tag to oldest first. The list starts at the oldest entry and the opener leads with it.
  - Scroll to load a second page. Blocks above do not re-plan.
  - Flip back.
  - The phone viewport shows the icon-only form alongside the other actions.

- [ ] **Step 6: Whole-branch review.** This step is not optional (see `skipping-sdd-final-review-is-risky`). Things to attack specifically:
  - Anything per-account that survives logout.
  - A flip that forgets the wrong scroll key.
  - A stale-token session: the reader must not stay blank when `/api/me` answers.
  - For You never showing the toggle.
  - The `sfr.user.7` vs `sfr.user.70` prefix.
