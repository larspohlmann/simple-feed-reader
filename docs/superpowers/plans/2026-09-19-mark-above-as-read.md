# Mark-Above-As-Read Button Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a lower-left icon button to the reader entry list that marks every entry the reader has fully scrolled past ("above the fold") as read, behind a confirmation dialog that states the count.

**Architecture:** A new context-agnostic backend endpoint `POST /api/entries/mark-read-batch` takes a client-supplied list of entry ids and feeds the existing `BulkEntryReadMarker`. The frontend tags every entry-rendering DOM element with `data-entry-id`, collects the ids of elements whose bottom edge sits at or above the fold line at click time (a snapshot), confirms the count, POSTs the ids, then re-fetches the list on unread views or restyles in place on all-items views.

**Tech Stack:** Symfony 7.4 (PHP 8.4), Doctrine, PHPUnit; Angular 20 standalone components + signals, RxJS, Jest, Transloco, CDK Dialog.

**Spec:** GitHub issue #1080 (https://github.com/larspohlmann/simple-feed-reader/issues/1080). Read it alongside this plan.

## Global Constraints

- **PHP:** `declare(strict_types=1)` in every file; PSR-12; `final readonly` classes with constructor promotion; controllers stay thin (delegate only — `ThinControllerRule`); PHPMD codesize clean on every touched file; PHPStan level max, no new baseline; typed exceptions, never null-for-failure.
- **Clean Code:** names reveal intent; functions do one thing; guard clauses over nesting; no comment unless a future reader would get the code wrong without it; DRY (third occurrence refactors).
- **Frontend:** standalone components + signals, no NgModules; `input()`/`input.required()`, `output()`, `viewChild()` idioms; `ReaderApi` methods return `Observable` (never `firstValueFrom`); component styles in a sibling `.scss` (never inline); **no hex colours and no ad-hoc `px`/media-query literals in `.scss` outside `src/app/theme/`** — use tokens.
- **API boundary (native-iOS):** JSON in, `application/problem+json` out, bearer auth, no browser-only inputs, no CSRF, no HTML fallback.
- **i18n:** every user-visible string is a Transloco key added to **all** locale files.
- **Mutation gate:** CI runs `composer infection:diff` over touched backend files; tests must kill the mutants on the cap boundary and the read-marking.
- **Cap:** the batch endpoint accepts at most **5000** ids (`MarkEntriesReadService::MAX_IDS`).
- **Verification commands:** backend `composer check` + `php bin/phpunit`; frontend `npm run check` and `docker compose exec -T frontend npm test` (Jest runs in the Docker frontend container).

---

## File Structure

**Backend (create):**
- `backend/src/Dto/Entry/MarkEntriesReadRequest.php` — request shape: `list<int> $ids`, validated.
- `backend/src/Service/Reader/MarkEntriesReadService.php` — thin domain service; holds `MAX_IDS`; delegates to `BulkEntryReadMarker`.

**Backend (modify):**
- `backend/src/Controller/Api/EntryController.php` — inject the service; add the `markReadBatch` action.
- `backend/tests/Controller/Api/EntryControllerTest.php` — add functional tests (reuses the existing `auth()`, `seedFeedWithEntries()`, `entriesOf()` helpers).

**Frontend (create):**
- `frontend/src/app/shared/styles/_corner-circle-button.scss` — `@mixin corner-circle-button`, the frosted-circle look, shared by both corner buttons.
- `frontend/src/app/reader/entry-list/above-fold.ts` — pure geometry: `entriesAboveFold(items, foldTop)`.
- `frontend/src/app/reader/entry-list/above-fold.spec.ts` — Jest for the pure function.

**Frontend (modify):**
- `frontend/src/app/reader/reader-api.ts` (+ `reader-api.spec.ts` if present) — add `markEntriesRead(ids)`.
- `frontend/src/app/reader/entries.store.ts` (+ `entries.store.spec.ts` if present) — add `markHiddenLocally(ids)`.
- `frontend/src/app/reader/magazine/entry-block-base.ts` — add the `data-entry-id` host binding (inherited by all 7 magazine leaves + `entry-compact`).
- `frontend/src/app/reader/entry-row/entry-row.component.ts` — add the same host binding for list mode.
- `frontend/src/app/reader/entry-list/entry-list.component.ts` — `hasAboveFold` signal, fold measurement, id collection, `markAboveRead` output, click handler.
- `frontend/src/app/reader/entry-list/entry-list.component.html` — render the lower-left button.
- `frontend/src/app/reader/entry-list/entry-list.component.scss` — position the button; adopt the mixin.
- `frontend/src/app/shared/to-top-button/to-top-button.component.scss` — adopt the mixin (output unchanged).
- `frontend/src/app/reader/reader-shell.component.ts` — bind the output, add the handler (dialog + branch), refresh counts.
- `frontend/src/app/reader/reader-shell.component.html` — wire `(markAboveRead)`.
- Transloco locale JSON files — add `reader.markAboveRead`, `reader.markAboveReadConfirm`, `reader.markAboveReadConfirmMessage`.

**Frontend (optional, outside CI gate):**
- `frontend/e2e/mark-above-as-read.spec.ts` — Playwright smoke.

---

## Task 1: Backend batch mark-read endpoint

**Files:**
- Create: `backend/src/Service/Reader/MarkEntriesReadService.php`
- Create: `backend/src/Dto/Entry/MarkEntriesReadRequest.php`
- Modify: `backend/src/Controller/Api/EntryController.php`
- Test: `backend/tests/Controller/Api/EntryControllerTest.php`

**Interfaces:**
- Consumes: `App\Service\Reader\BulkEntryReadMarker::markRead(int $userId, array $entryIds): void` (existing; chunks at 500, no-ops on `[]`, ignores ids with no matching entry).
- Produces: `POST /api/entries/mark-read-batch` (route name `api_entries_mark_read_batch`), body `{"ids": int[]}`, returns `204`. `MarkEntriesReadService::mark(User $user, array $entryIds): void`. `MarkEntriesReadService::MAX_IDS = 5000`.

- [ ] **Step 1: Write the failing functional test**

Add to `backend/tests/Controller/Api/EntryControllerTest.php` (it already imports `Entry`, `EntityManagerInterface`, and defines `auth()`, `seedFeedWithEntries()`, `entriesOf()`):

```php
    public function testMarkReadBatchMarksOnlyTheGivenEntries(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-markbatch@example.com');
        $sub = $this->seedFeedWithEntries($user, 3);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entries = $em->getRepository(Entry::class)->findBy(['feed' => $sub->getFeed()], ['guid' => 'ASC']);
        $markIds = [$entries[0]->getId(), $entries[1]->getId()];

        $client->request(
            'POST',
            '/api/entries/mark-read-batch',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => $markIds], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        self::assertCount(1, $this->entriesOf($client), 'The unmarked entry stays unread.');
    }
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && php bin/phpunit --filter testMarkReadBatchMarksOnlyTheGivenEntries`
Expected: FAIL — 404 (route not found), so the follow-up GET count is 3, not 1.

- [ ] **Step 3: Create the service**

`backend/src/Service/Reader/MarkEntriesReadService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\User;

final readonly class MarkEntriesReadService
{
    public const int MAX_IDS = 5000;

    public function __construct(private BulkEntryReadMarker $readMarker)
    {
    }

    /** @param list<int> $entryIds */
    public function mark(User $user, array $entryIds): void
    {
        $this->readMarker->markRead((int) $user->getId(), $entryIds);
    }
}
```

- [ ] **Step 4: Create the request DTO**

`backend/src/Dto/Entry/MarkEntriesReadRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use App\Service\Reader\MarkEntriesReadService;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkEntriesReadRequest
{
    /** @param list<int> $ids */
    public function __construct(
        #[Assert\Count(min: 1, max: MarkEntriesReadService::MAX_IDS)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $ids = [],
    ) {
    }
}
```

- [ ] **Step 5: Add the controller action**

In `backend/src/Controller/Api/EntryController.php`, add the service to the constructor (alongside `ForYouMarkReadService $forYouMarkRead`):

```php
        private MarkEntriesReadService $markEntriesRead,
```

Add the import near the other `App\Service\...` imports:

```php
use App\Dto\Entry\MarkEntriesReadRequest;
use App\Service\Reader\MarkEntriesReadService;
```

Add the action (mirrors `markForYouRead`, no scope, no watermark):

```php
    #[Route('/mark-read-batch', name: 'api_entries_mark_read_batch', methods: ['POST'])]
    public function markReadBatch(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkEntriesReadRequest $request,
    ): JsonResponse {
        $this->markEntriesRead->mark($user, $request->ids);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `cd backend && php bin/phpunit --filter testMarkReadBatchMarksOnlyTheGivenEntries`
Expected: PASS.

- [ ] **Step 7: Add the guard tests (validation + auth)**

Append to `EntryControllerTest.php`:

```php
    public function testMarkReadBatchRejectsEmptyIds(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbatch-empty@example.com');

        $client->request(
            'POST',
            '/api/entries/mark-read-batch',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => []], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
    }

    public function testMarkReadBatchRejectsNonPositiveIds(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbatch-neg@example.com');

        $client->request(
            'POST',
            '/api/entries/mark-read-batch',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => [0, -5]], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testMarkReadBatchRejectsOverTheCap(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbatch-cap@example.com');
        $ids = range(1, MarkEntriesReadService::MAX_IDS + 1);

        $client->request(
            'POST',
            '/api/entries/mark-read-batch',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => $ids], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testMarkReadBatchRejectsAnonymous(): void
    {
        $client = self::createClient();

        $client->request(
            'POST',
            '/api/entries/mark-read-batch',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => [1]], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(401);
    }
```

Add the import at the top of the test file:

```php
use App\Service\Reader\MarkEntriesReadService;
```

- [ ] **Step 8: Run the whole test class**

Run: `cd backend && php bin/phpunit --filter EntryControllerTest`
Expected: PASS (all, including the four new guard tests).

- [ ] **Step 9: Run the quality gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`
Expected: `cs`, `stan`, `tramp`, and PHPMD all clean on the two new files and the controller. Fix any finding by improving the design, not the threshold.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Dto/Entry/MarkEntriesReadRequest.php backend/src/Service/Reader/MarkEntriesReadService.php backend/src/Controller/Api/EntryController.php backend/tests/Controller/Api/EntryControllerTest.php
git commit -m "feat(#1080): add POST /api/entries/mark-read-batch"
```

**Note on scope (deliberate):** the endpoint marks whatever ids the caller sends. `BulkEntryReadMarker` scopes every write to the caller's own `userId` and silently ignores ids with no matching `Entry`, so a crafted request can only create hidden-state rows in the caller's own account — no cross-user effect. This matches the existing trust model (the search/for-you callers feed ids they computed for the same user). Do **not** add per-subscription ownership filtering unless the user asks; it buys nothing here and adds a query.

---

## Task 2: Frontend API method

**Files:**
- Modify: `frontend/src/app/reader/reader-api.ts`
- Test: `frontend/src/app/reader/reader-api.spec.ts` (add to it if it exists; otherwise create following the `HttpTestingController` idiom used elsewhere in the repo)

**Interfaces:**
- Produces: `ReaderApi.markEntriesRead(ids: number[]): Observable<void>` — POSTs `{ ids }` to `/api/entries/mark-read-batch`.

- [ ] **Step 1: Locate or scaffold the API spec**

Run: `ls frontend/src/app/reader/reader-api.spec.ts 2>/dev/null; grep -rl "HttpTestingController" frontend/src/app | head`
Use the found spec's setup (provideHttpClientTesting / `HttpTestingController`) as the template. If none exists for `ReaderApi`, create `reader-api.spec.ts` mirroring another `HttpTestingController` spec in the repo.

- [ ] **Step 2: Write the failing test**

```ts
it('posts the id list to mark-read-batch', () => {
  api.markEntriesRead([11, 22, 33]).subscribe();
  const req = httpMock.expectOne((r) => r.url.endsWith('/api/entries/mark-read-batch'));
  expect(req.request.method).toBe('POST');
  expect(req.request.body).toEqual({ ids: [11, 22, 33] });
  req.flush(null);
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `docker compose exec -T frontend npx jest reader-api --silent`
Expected: FAIL — `markEntriesRead` is not a function.

- [ ] **Step 4: Implement the method**

In `frontend/src/app/reader/reader-api.ts`, add after `markSavedSearchesRead`:

```ts
  /** Mark an explicit set of entries read. Context-agnostic: the id list is
   *  self-describing, so it serves every list including the ranked ones. */
  markEntriesRead(ids: number[]): Observable<void> {
    return this.http.post<void>(`${this.base}/api/entries/mark-read-batch`, { ids });
  }
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `docker compose exec -T frontend npx jest reader-api --silent`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts
git commit -m "feat(#1080): ReaderApi.markEntriesRead"
```

---

## Task 3: Store — local restyle for all-items views

**Files:**
- Modify: `frontend/src/app/reader/entries.store.ts`
- Test: `frontend/src/app/reader/entries.store.spec.ts` (add to it if it exists; otherwise create)

**Interfaces:**
- Produces: `EntriesStore.markHiddenLocally(ids: number[]): void` — sets `isHidden: true` on the matching entries in the in-memory list, no HTTP. Used only after the batch POST already succeeded (all-items views, where rows stay visible but restyle to "read").

- [ ] **Step 1: Write the failing test**

Add to `entries.store.spec.ts` (mirror an existing test's TestBed setup for `EntriesStore`; load a list via the same helper the other tests use to populate `entries()`):

```ts
it('marks the given entries hidden in place without an HTTP call', () => {
  // Arrange: load a list with entries 1, 2, 3 all unhidden (use the spec's
  // existing helper / expectOne+flush pattern to populate rawEntries).
  store.markHiddenLocally([1, 3]);

  const byId = new Map(store.entries().map((e) => [e.id, e.isHidden]));
  expect(byId.get(1)).toBe(true);
  expect(byId.get(2)).toBe(false);
  expect(byId.get(3)).toBe(true);
  httpMock.expectNone((r) => r.url.includes('/state'));
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `docker compose exec -T frontend npx jest entries.store --silent`
Expected: FAIL — `markHiddenLocally` is not a function.

- [ ] **Step 3: Implement the method**

In `frontend/src/app/reader/entries.store.ts`, add a public method near `setState`:

```ts
  /** Restyle entries as read in place — no request. The caller marks them read
   *  on the server first (mark-read-batch); this only reflects it in an
   *  all-items list, where the rows stay visible. */
  markHiddenLocally(ids: number[]): void {
    const marked = new Set(ids);
    this.rawEntries.update((cur) =>
      cur.map((e) => (marked.has(e.id) ? { ...e, isHidden: true } : e)),
    );
  }
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `docker compose exec -T frontend npx jest entries.store --silent`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entries.store.ts frontend/src/app/reader/entries.store.spec.ts
git commit -m "feat(#1080): EntriesStore.markHiddenLocally"
```

---

## Task 4: Pure above-fold geometry

**Files:**
- Create: `frontend/src/app/reader/entry-list/above-fold.ts`
- Test: `frontend/src/app/reader/entry-list/above-fold.spec.ts`

**Interfaces:**
- Produces: `interface MeasuredEntry { id: number; bottom: number }` and `entriesAboveFold(items: MeasuredEntry[], foldTop: number): number[]` — returns, in input (visual) order, the ids whose bottom edge is at or above `foldTop`. An entry with `bottom > foldTop` (the topmost partially-visible one, the boundary) is excluded.

- [ ] **Step 1: Write the failing test**

`frontend/src/app/reader/entry-list/above-fold.spec.ts`:

```ts
import { entriesAboveFold } from './above-fold';

describe('entriesAboveFold', () => {
  const items = [
    { id: 1, bottom: 40 },
    { id: 2, bottom: 90 },
    { id: 3, bottom: 150 }, // straddles the fold
    { id: 4, bottom: 260 },
  ];

  it('returns entries fully above the fold, keeping the boundary unread', () => {
    // fold at 100: 1 and 2 are fully above; 3 straddles (bottom 150 > 100).
    expect(entriesAboveFold(items, 100)).toEqual([1, 2]);
  });

  it('treats an entry whose bottom sits exactly on the fold as above', () => {
    expect(entriesAboveFold(items, 90)).toEqual([1, 2]);
  });

  it('returns nothing at the top of the list', () => {
    expect(entriesAboveFold(items, 0)).toEqual([]);
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `docker compose exec -T frontend npx jest above-fold --silent`
Expected: FAIL — cannot find module `./above-fold`.

- [ ] **Step 3: Implement**

`frontend/src/app/reader/entry-list/above-fold.ts`:

```ts
export interface MeasuredEntry {
  id: number;
  bottom: number;
}

/**
 * The ids of entries the reader has fully scrolled past — bottom edge at or
 * above the fold line. The topmost partially-visible entry (bottom below the
 * line) is the boundary and stays unread. Order follows the input.
 */
export function entriesAboveFold(items: MeasuredEntry[], foldTop: number): number[] {
  const ids: number[] = [];
  for (const item of items) {
    if (item.bottom <= foldTop) ids.push(item.id);
  }
  return ids;
}
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `docker compose exec -T frontend npx jest above-fold --silent`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entry-list/above-fold.ts frontend/src/app/reader/entry-list/above-fold.spec.ts
git commit -m "feat(#1080): pure above-fold id selector"
```

---

## Task 5: Tag entries in the DOM

**Files:**
- Modify: `frontend/src/app/reader/magazine/entry-block-base.ts`
- Modify: `frontend/src/app/reader/entry-row/entry-row.component.ts`
- Test: `frontend/src/app/reader/entry-row/entry-row.component.spec.ts` (add to it if it exists; otherwise create)

**Interfaces:**
- Produces: every entry-rendering host element carries `data-entry-id="<EntryDto.id>"`. `EntryBlockBase`'s host binding is inherited by `EntryHero/Wide/Split/Thumb/Quote/Kicker/CompactComponent`; `EntryCompactComponent` is the one `SourceGroupComponent` renders per group member, so group members are tagged too. `EntryRowComponent` carries its own copy for list mode.

- [ ] **Step 1: Write the failing test**

In `entry-row.component.spec.ts` (mirror the spec's existing TestBed setup; `entry` is a required signal input, set it with `fixture.componentRef.setInput('entry', <EntryDto>)`):

```ts
it('exposes its entry id on the host element', () => {
  fixture.componentRef.setInput('entry', { ...baseEntry, id: 4242 });
  fixture.detectChanges();
  expect(fixture.nativeElement.getAttribute('data-entry-id')).toBe('4242');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `docker compose exec -T frontend npx jest entry-row.component --silent`
Expected: FAIL — attribute is `null`.

- [ ] **Step 3: Add the host binding to the magazine base**

In `frontend/src/app/reader/magazine/entry-block-base.ts`, add the `host` map to the `@Directive` decorator (it currently has none):

```ts
@Directive({
  host: { '[attr.data-entry-id]': 'entry().id' },
})
export abstract class EntryBlockBase {
  readonly entry = input.required<EntryDto>();
```

- [ ] **Step 4: Add the same binding to the list row**

In `frontend/src/app/reader/entry-row/entry-row.component.ts`, add `host` to the `@Component` decorator (it currently has none):

```ts
@Component({
  selector: 'app-entry-row',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[attr.data-entry-id]': 'entry().id' },
  imports: [
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `docker compose exec -T frontend npx jest entry-row.component --silent`
Expected: PASS.

- [ ] **Step 6: Guard the magazine leaf too**

Add one assertion in the existing `entry-hero.component.spec.ts` (or the nearest magazine-leaf spec — pick the one that exists), proving the inherited binding works:

```ts
it('exposes its entry id on the host element', () => {
  fixture.componentRef.setInput('entry', { ...baseEntry, id: 77 });
  fixture.detectChanges();
  expect(fixture.nativeElement.getAttribute('data-entry-id')).toBe('77');
});
```

Run: `docker compose exec -T frontend npx jest entry-hero.component --silent`
Expected: PASS (confirms inheritance from `EntryBlockBase`).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/magazine/entry-block-base.ts frontend/src/app/reader/entry-row/entry-row.component.ts frontend/src/app/reader/entry-row/entry-row.component.spec.ts frontend/src/app/reader/magazine/entry-hero.component.spec.ts
git commit -m "feat(#1080): tag entry hosts with data-entry-id"
```

---

## Task 6: Shared frosted-circle mixin

**Files:**
- Create: `frontend/src/app/shared/styles/_corner-circle-button.scss`
- Modify: `frontend/src/app/shared/to-top-button/to-top-button.component.scss`

**Interfaces:**
- Produces: `@mixin corner-circle-button` — the frosted accent circle (`--tap-target` size, `--accent`/`--on-accent`, blur, entry animation, reduced-motion off). Applied to a selector by the consumer. No top-level rules, so `@use` emits nothing until included.

- [ ] **Step 1: Create the mixin (extract verbatim from the existing to-top styles)**

`frontend/src/app/shared/styles/_corner-circle-button.scss`:

```scss
@mixin corner-circle-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--tap-target);
  height: var(--tap-target);
  border: none;
  border-radius: 50%;

  /* A mild frost, not a full-glass panel: the button keeps its accent
     identity, so it can only thin so far. 65% is where the accent still
     looks like itself while the blur gives a gentle frost behind rows. */
  background: var(--accent);
  background: color-mix(in srgb, var(--accent) 65%, transparent);
  backdrop-filter: blur(12px) saturate(1.4);
  color: var(--on-accent);
  cursor: pointer;
  box-shadow: 0 4px 14px rgb(0 0 0 / 25%);
  animation: corner-circle-in 0.15s ease-out;

  @keyframes corner-circle-in {
    from {
      opacity: 0;
      transform: translateY(6px);
    }
  }

  @media (prefers-reduced-motion: reduce) {
    animation: none;
  }
}
```

- [ ] **Step 2: Point the to-top button at the mixin**

Replace the `button { ... }`, `@keyframes to-top-in { ... }`, and the reduced-motion block in `frontend/src/app/shared/to-top-button/to-top-button.component.scss` with an include, keeping the `:host` rule:

```scss
@use '../../shared/styles/corner-circle-button' as circle;

/* Out of flow by default so it can hang in a corner; the consumer supplies
   offsets, stacking order, and whether it pins to the viewport. */
:host {
  position: absolute;
  display: inline-flex;
}

button {
  @include circle.corner-circle-button;
}
```

(Adjust the `@use` path to be correct relative to the `to-top-button` folder — it is a sibling of `shared/styles`, so `'../styles/corner-circle-button'`. Verify at build.)

- [ ] **Step 3: Verify the to-top button is visually unchanged**

Run: `cd frontend && npm run check`
Expected: Stylelint + build pass. Then spot-check the article view / list to-top button still renders the frosted circle (Task 10 covers this in the browser).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/shared/styles/_corner-circle-button.scss frontend/src/app/shared/to-top-button/to-top-button.component.scss
git commit -m "refactor(#1080): extract shared corner-circle button style"
```

---

## Task 7: List button — collect ids, gate visibility, emit

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss`
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` (add to it)

**Interfaces:**
- Consumes: `entriesAboveFold` (Task 4); the `#rows` scroller (`this.rows()`), the `#listHdr` header (`this.listHdr()`), `showToTop` signal, `onRowsScroll`.
- Produces: `markAboveRead = output<number[]>()` — the snapshot of above-fold ids at click time; `hasAboveFold = signal<boolean>(false)`; `onMarkAboveRead(): void`.

- [ ] **Step 1: Write the failing test (collection over a fake scroller)**

In `entry-list.component.spec.ts`, add a unit test of the collection method by stubbing the geometry. Because jsdom has no layout, drive `collectAboveFoldIds()` through a fake `rows`/`listHdr` whose elements return scripted rects:

```ts
it('collects ids of entries fully above the fold at click', () => {
  const el = (id: string, bottom: number): HTMLElement =>
    ({ getAttribute: () => id, getBoundingClientRect: () => ({ bottom }) }) as unknown as HTMLElement;
  const scroller = {
    getBoundingClientRect: () => ({ top: 0 }),
    querySelectorAll: () => [el('1', 40), el('2', 90), el('3', 150)] as unknown as NodeListOf<Element>,
  } as unknown as HTMLElement;
  // fold line = max(scroller.top=0, listHdr.bottom=100) = 100
  const header = { getBoundingClientRect: () => ({ bottom: 100 }) } as unknown as HTMLElement;

  const emitted: number[][] = [];
  component.markAboveRead.subscribe((ids) => emitted.push(ids));
  // Inject the fakes via the component's viewChild signals (spy the signals to
  // return { nativeElement } wrappers), then:
  component.onMarkAboveRead();

  expect(emitted).toEqual([[1, 2]]);
});
```

(Set up the `rows`/`listHdr` viewChild signals to return `{ nativeElement: scroller }` / `{ nativeElement: header }` using `jest.spyOn(component as unknown as { rows: () => unknown }, 'rows')` or by assigning fakes — follow whatever injection style the existing spec uses for view children.)

- [ ] **Step 2: Run it to confirm it fails**

Run: `docker compose exec -T frontend npx jest entry-list.component --silent`
Expected: FAIL — `onMarkAboveRead`/`markAboveRead` do not exist.

- [ ] **Step 3: Implement the signal, measurement, collection, output, and handler**

In `entry-list.component.ts`:

Add the import:

```ts
import { entriesAboveFold, MeasuredEntry } from './above-fold';
```

Add the output next to the other outputs (near `markAllRead`):

```ts
  /** The above-fold ids captured at click — a snapshot, so scrolling or a
   *  background refresh while the confirm dialog is open cannot change the set. */
  readonly markAboveRead = output<number[]>();
```

Add the signal next to `showToTop`:

```ts
  /** Whether at least one entry is fully scrolled past — gates the lower-left
   *  button so it never offers a no-op. Set cheaply from the scroll handler. */
  readonly hasAboveFold = signal(false);
```

Add the fold-line, measurement, collection, and cheap-probe helpers (private, one job each):

```ts
  private foldTop(scroller: HTMLElement): number {
    const header = this.listHdr()?.nativeElement.getBoundingClientRect().bottom ?? 0;
    return Math.max(scroller.getBoundingClientRect().top, header);
  }

  private measuredEntries(scroller: HTMLElement): MeasuredEntry[] {
    return Array.from(scroller.querySelectorAll('[data-entry-id]')).map((node) => ({
      id: Number(node.getAttribute('data-entry-id')),
      bottom: node.getBoundingClientRect().bottom,
    }));
  }

  private collectAboveFoldIds(): number[] {
    const scroller = this.rows()?.nativeElement;
    if (!scroller) return [];
    return entriesAboveFold(this.measuredEntries(scroller), this.foldTop(scroller));
  }

  private hasEntryAboveFold(scroller: HTMLElement): boolean {
    const first = scroller.querySelector('[data-entry-id]');
    return !!first && first.getBoundingClientRect().bottom <= this.foldTop(scroller);
  }

  onMarkAboveRead(): void {
    const ids = this.collectAboveFoldIds();
    if (ids.length > 0) this.markAboveRead.emit(ids);
  }
```

Set `hasAboveFold` in `onRowsScroll`, right after the `showToTop.set(...)` line (one extra cheap probe per frame — the header rect plus the first tagged element):

```ts
    this.hasAboveFold.set(this.hasEntryAboveFold(el));
```

Reset it wherever `showToTop` is reset (the `_resetCollapse` effect) so a list switch does not leave a stale gate:

```ts
    this.hasAboveFold.set(false);
```

- [ ] **Step 4: Render the button (both layouts, next to the to-top button)**

In `entry-list.component.html`, beside the existing `@if (showToTop())` block, add:

```html
@if (showToTop() && hasAboveFold()) {
  <button
    type="button"
    class="mark-above"
    (click)="onMarkAboveRead()"
    [attr.aria-label]="'reader.markAboveRead' | transloco"
    [attr.title]="'reader.markAboveRead' | transloco"
  >
    <app-icon name="playlist_add_check" size="md" />
  </button>
}
```

(`IconComponent` is already imported. Pick the check-style Material icon that matches the catalog — `playlist_add_check` reads as "mark this range done"; confirm the name exists in the icon set, else use `done_all` to match the header's mark-all button.)

- [ ] **Step 5: Position and style the button (lower-left mirror of to-top)**

In `entry-list.component.scss`, add the `@use` at the top (if not already present) and the rules, mirroring the `app-to-top-button` block but on the left:

```scss
@use '../../shared/styles/corner-circle-button' as circle;

.mark-above {
  position: absolute;
  left: var(--space-5);
  bottom: var(--space-5);
  z-index: 4;

  @include circle.corner-circle-button;
}

/* Clear the narrow-layout run-pill toast, same as the to-top corner (#641). */
:host-context(.is-narrow) .mark-above {
  bottom: calc(
    var(--space-5) + var(--space-3) + var(--app-toast-height, calc(-1 * var(--space-3)))
  );
}
```

(Verify the `@use` path relative to the `entry-list` folder resolves to `shared/styles/corner-circle-button`.)

- [ ] **Step 6: Run the tests + check to confirm they pass**

Run: `docker compose exec -T frontend npx jest entry-list.component --silent && cd frontend && npm run check`
Expected: PASS; Stylelint clean (no hex, no raw px).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.ts frontend/src/app/reader/entry-list/entry-list.component.html frontend/src/app/reader/entry-list/entry-list.component.scss frontend/src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "feat(#1080): lower-left mark-above-read button in the list"
```

---

## Task 8: Shell wiring — confirm, POST, re-fetch vs restyle

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.html`
- Modify: Transloco locale JSON files
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts` (add to it)

**Interfaces:**
- Consumes: `EntryListComponent.markAboveRead` output (Task 7); `ReaderApi.markEntriesRead` (Task 2); `EntriesStore.markHiddenLocally` (Task 3), `EntriesStore.runThenReload`, `EntriesStore.load`; `this.list()` (the `EntryListComponent` viewChild) `.scrollToTop()`; `ConfirmDialogComponent` via CDK `Dialog`.
- Produces: `onMarkAboveRead(ids: number[]): void`.

- [ ] **Step 1: Add the i18n keys**

Run: `grep -rl "markAllReadConfirmMessage" frontend/src` to find every locale file. In each, beside the `markAllRead*` keys under `reader`, add:

```json
"markAboveRead": "Mark everything above as read",
"markAboveReadConfirm": "Mark entries above as read?",
"markAboveReadConfirmMessage": "Mark the {{count}} entries you have scrolled past as read? The rest stay unread."
```

For every non-English locale, translate to match the tone of the neighbouring `markAllRead*` entries (mirror how those were localised). Keep `{{count}}` verbatim.

- [ ] **Step 2: Write the failing test (branching)**

In `reader-shell.component.spec.ts` (reuse its harness — a `Dialog` stub whose `open().closed` emits `true`, a spied `ReaderApi`/`EntriesStore`):

```ts
it('re-fetches and returns to the top after marking, on an unread view', () => {
  setSelection({ kind: 'all', id: null, unread: true });
  jest.spyOn(api, 'markEntriesRead').mockReturnValue(of(undefined));
  const runThenReload = jest.spyOn(entries, 'runThenReload');
  const scrollToTop = jest.fn();
  jest.spyOn(component, 'list').mockReturnValue({ scrollToTop } as unknown as EntryListComponent);

  component.onMarkAboveRead([1, 2]);
  // runThenReload runs its reload callback synchronously in the stub
  runThenReload.mock.calls[0][1]();

  expect(api.markEntriesRead).toHaveBeenCalledWith([1, 2]);
  expect(scrollToTop).toHaveBeenCalled();
});

it('restyles in place without a re-fetch, on an all-items view', () => {
  setSelection({ kind: 'all', id: null, unread: false });
  jest.spyOn(api, 'markEntriesRead').mockReturnValue(of(undefined));
  const markHiddenLocally = jest.spyOn(entries, 'markHiddenLocally');
  const load = jest.spyOn(entries, 'load');

  component.onMarkAboveRead([5, 6]);

  expect(markHiddenLocally).toHaveBeenCalledWith([5, 6]);
  expect(load).not.toHaveBeenCalled();
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `docker compose exec -T frontend npx jest reader-shell.component --silent`
Expected: FAIL — `onMarkAboveRead` does not exist.

- [ ] **Step 4: Implement the handler**

In `reader-shell.component.ts`, add (mirrors `onMarkAllRead` / `markReadNow`; the `queryFromSelection`, `ConfirmData`, `ConfirmDialogComponent`, `Dialog`, `i18n`, `subs`, `savedSearchesStore`, `recs` members are all already present):

```ts
  onMarkAboveRead(ids: number[]): void {
    if (ids.length === 0) return;
    const data: ConfirmData = {
      title: this.i18n.translate('reader.markAboveReadConfirm'),
      message: this.i18n.translate('reader.markAboveReadConfirmMessage', { count: ids.length }),
      confirmLabel: this.i18n.translate('reader.markAboveRead'),
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.markAboveReadNow(ids);
    });
  }

  private markAboveReadNow(ids: number[]): void {
    const request = this.api.markEntriesRead(ids);
    // Unread view: the marked rows leave the list, so re-fetch and land the
    // reader at the top of what is left (#1080 Q13b/Q16).
    if (this.selection().unread) {
      this.entries.runThenReload(request, () => {
        this.entries.load(queryFromSelection(this.selection()));
        this.refreshCountsAfterMarkRead();
        this.list()?.scrollToTop();
      });
      return;
    }
    // All-items view: the rows stay; restyle them in place, no re-fetch, no
    // scroll change (#1080 Q15a).
    request.subscribe({
      next: () => {
        this.entries.markHiddenLocally(ids);
        this.refreshCountsAfterMarkRead();
      },
    });
  }

  private refreshCountsAfterMarkRead(): void {
    this.subs.load();
    this.savedSearchesStore.load();
    this.recs.refreshStatus();
  }
```

(Confirm the injected member names against the existing `markReadNow`: `this.subs`, `this.savedSearchesStore`, `this.recs`, `this.dialog`, `this.i18n`, `this.api`, `this.entries`, `this.selection`, `this.list`. Use them exactly as that method does.)

- [ ] **Step 5: Wire the output in the template**

In `reader-shell.component.html`, on the `<app-entry-list ...>` element, add:

```html
  (markAboveRead)="onMarkAboveRead($event)"
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `docker compose exec -T frontend npx jest reader-shell.component --silent`
Expected: PASS (both branches).

- [ ] **Step 7: Full frontend gate**

Run: `cd frontend && npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all green.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.html frontend/src/app/reader/reader-shell.component.spec.ts frontend/src/**/i18n/*.json frontend/src/assets/i18n/*.json
git commit -m "feat(#1080): wire mark-above-read confirm + re-fetch/restyle"
```

---

## Task 9: Browser verification (real layout) and dev-log scan

**Files:**
- Optional: `frontend/e2e/mark-above-as-read.spec.ts` (Playwright; outside the CI gate)

- [ ] **Step 1: Bring up the stack and open the app**

Run: `docker compose up -d` (from repo root), then open the dev SPA (`https://localhost:8443` behind the stack, or `npm start` on `:4200`). Sign in to a seeded account with a long unread list.

- [ ] **Step 2: Verify the button appears and gates correctly**

- At the top: neither corner button is shown.
- Scroll down past ~500px: the lower-left mark-above button and the lower-right to-top button both appear. Confirm the two circles match visually (the shared mixin).
- Confirm the button hides again at the very top and when a single tall item still fills the viewport (nothing fully above → `hasAboveFold` false).

- [ ] **Step 3: Verify the action on an unread view**

- Note the topmost still-visible (boundary) entry.
- Click the button; the dialog shows the exact count of entries above the fold.
- Confirm: the marked entries leave the list, the view lands at the top, the boundary entry is now the first row, and the sidebar unread counts drop.
- Repeat in magazine layout (the primary layout) — including a source-group widget straddling the fold: only the group members fully above the fold get marked.

- [ ] **Step 4: Verify the action on an all-items view**

- Toggle the list to "all items", scroll down, click the button, confirm.
- The above-fold rows stay in place and restyle to "read"; the scroll position does not move; sidebar counts drop.

- [ ] **Step 5: Scan the dev log for swallowed errors**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 80 | jq .`
Expected: no new deprecations or errors from the batch endpoint.

- [ ] **Step 6 (optional): Playwright smoke**

If adding the smoke, stub `POST **/api/entries/mark-read-batch` and assert the request body carries the expected ids after a confirm (own its fixture per the repo's e2e rule; leave the seed as found). Run via `npm run e2e` from the checkout that ran `docker compose up`. This spec stays outside the CI gate.

---

## Self-Review

**Spec coverage (issue #1080):**
- Placement lower-left, mirrors to-top → Task 7 (button + `.mark-above` left offset) + Task 6 (shared circle).
- Visible only after 500px, hidden at top, not actionable at 0 → Task 7 (`showToTop() && hasAboveFold()`).
- "Above" = bottom fully above the fold; boundary stays unread → Task 4 (`entriesAboveFold`, `bottom <= foldTop`) + Task 7 (`foldTop` uses header bottom).
- Snapshot at click → Task 7 (`onMarkAboveRead` collects and emits an array; the array is fixed).
- Confirmation dialog with count → Task 8 (`ConfirmDialogComponent`, `markAboveReadConfirmMessage` with `{{count}}`).
- All contexts incl. ranked → id-based endpoint (Task 1) + tagging incl. group members via `EntryCompactComponent` (Task 5).
- Backend endpoint, DTO with `Assert\Count(max: 5000)`, feeds `BulkEntryReadMarker`, no `isViewed`, iOS-friendly 204 → Task 1.
- Unread view re-fetch + land at top; all-items restyle in place, no scroll change; no toast, no undo → Task 8.

**Placeholder scan:** the only locate-then-edit steps are the i18n file paths (Task 8 Step 1, resolved by grep), the `reader-api`/`entries.store`/`entry-row`/`reader-shell` spec harnesses (reuse the file's existing setup), and the icon name (Task 7 Step 4, with a named fallback). Each is concrete and bounded; no "add error handling"-style gaps.

**Type consistency:** `markEntriesRead(ids: number[])` (Task 2) ↔ `onMarkAboveRead(ids)` / `markAboveReadNow` (Task 8); `markAboveRead = output<number[]>()` (Task 7) ↔ `(markAboveRead)="onMarkAboveRead($event)"` (Task 8); `entriesAboveFold`/`MeasuredEntry` (Task 4) ↔ used in Task 7; `markHiddenLocally(ids)` (Task 3) ↔ called in Task 8; `MarkEntriesReadService::MAX_IDS` (Task 1 service) ↔ referenced in the DTO attribute and the cap test.
