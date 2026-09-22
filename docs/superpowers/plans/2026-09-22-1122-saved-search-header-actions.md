# Restore Saved-Search List Header Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the three header actions (Mark all read, All items / only unread switch, Remove) on the single saved-search list view that #1118 lost when it moved a saved search to `Selection.kind === 'saved-search'`.

**Architecture:** Add a per-saved-search mark-read endpoint that marks one owned saved search's unread membership rows read by id (mirrors the combined `/api/entries/saved-searches/mark-read`). On the frontend, teach `markReadTarget()`, `hasUnreadFilter()`, the mark-read execution, the reader API, and the header's Save/Remove wiring about the `'saved-search'` kind. Removing the saved search you are viewing navigates back to the combined saved-search list.

**Tech Stack:** Symfony 7.4 / PHP 8.4 backend; Angular 20 / signals frontend; Jest (frontend), PHPUnit (backend).

**Spec:** This plan (issue #1122). Root cause: #1118 added the `'saved-search'` kind to `queryFromSelection`, the title, and the count, but never extended the header-action gates.

## Global Constraints

- Response prose to the user is ASD-STE100; code, comments, and commits are normal English.
- PHP: `declare(strict_types=1)`, PSR-12, PHPStan level max, Clean Code (thin controllers, no boolean-flag params, guard clauses, comments only for a non-obvious invariant). Every touched `src` file must be PHPMD-clean.
- Frontend: standalone components + signals; no hex colours or `px` literals in `.scss` outside `theme/`; component styles in sibling `.scss`.
- Native-iOS boundary: the new endpoint is JSON in / 204 out, bearer auth, `application/problem+json` on error — no browser-only inputs.
- The legacy `?q=…&searchOrigin=saved` saved-search-result URL is **removed** (owner: legacy bookmarks may break, confirmed acceptable). A saved search is only ever the `'saved-search'` kind reached by its slug path; a `?q=` URL is always a direct, unsaved search. Delete `searchOrigin`, `isSavedSearchResult`, `savedSearchResult()`, `savedSearchParams`, and `savedSearchTerm`, and migrate the e2e specs that used the old URL to the slug path.
- Backend commit gate: `composer check` + `composer md`; run `php bin/phpunit` (SQLite) and, when Docker is up, `docker compose exec php composer test` (MySQL). Frontend gate: `docker compose exec -T frontend npm test` and `npm run check`.
- Commit format: `type(#1122): summary`.

---

### Task 1: Backend — mark one saved search read by id

**Files:**
- Modify: `backend/src/Service/Reader/SavedSearchMarkReadService.php`
- Modify: `backend/src/Controller/Api/SavedSearchEntriesController.php`
- Test: `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php`

**Interfaces:**
- Consumes: `SavedSearchRepository::findOneOwnedBy(int $id, int $userId): ?SavedSearch`; `SavedSearchEntryRepository::unreadMemberIdsUpTo(int $userId, array $savedSearchIds, \DateTimeImmutable $until): list<int>`; `BulkEntryReadMarker::markRead(int $userId, array $entryIds): void`.
- Produces: `POST /api/entries/saved-searches/{id}/mark-read` (name `api_entries_saved_search_one_mark_read`), body `{ "until": ISO8601 }`, `204` on success, `404` for a non-owned/unknown id. `SavedSearchMarkReadService::markOne(User $user, int $savedSearchId, \DateTimeImmutable $until): void`.

- [ ] **Step 1: Write the failing functional tests**

Add to `SavedSearchEntriesControllerTest.php`, mirroring `testMarkReadFlipsMatchesUpToTheWatermarkOnly`:

```php
public function testMarkOneReadFlipsOnlyThatSearchesMembersUpToTheWatermark(): void
{
    $client = self::createClient();
    $user = $this->factory()->create('mark-one-watermark@example.com');
    $headers = $this->authHeaderFor($user);
    $feed = $this->seedSubscribedFeed($user);
    $old = $this->seedEntry($feed, 'Climate old', new \DateTimeImmutable('2026-07-05T00:00:00Z'));
    $new = $this->seedEntry($feed, 'Climate new', new \DateTimeImmutable('2026-07-15T00:00:00Z'));
    $other = $this->seedEntry($feed, 'Rocket now', new \DateTimeImmutable('2026-07-06T00:00:00Z'));
    $climate = new SavedSearch($user, 'climate', false);
    $rocket = new SavedSearch($user, 'rocket', false);
    $this->em()->persist($climate);
    $this->em()->persist($rocket);
    $this->em()->flush();
    $this->member($climate, $old);
    $this->member($climate, $new);
    $this->member($rocket, $other);

    $client->request(
        'POST',
        '/api/entries/saved-searches/' . $climate->getId() . '/mark-read',
        server: $headers,
        content: json_encode(['until' => '2026-07-10T00:00:00+00:00'], \JSON_THROW_ON_ERROR),
    );

    self::assertResponseStatusCodeSame(204);

    $client->request('GET', '/api/entries/saved-searches?unread=1', server: $headers);
    $titles = array_column($this->payload($client)['entries'], 'title');
    sort($titles);
    self::assertSame(['Climate new', 'Rocket now'], $titles);
}

public function testMarkOneReadIsNotFoundForAnotherUsersSearch(): void
{
    $client = self::createClient();
    $user = $this->factory()->create('mark-one-idor-victim@example.com');
    $stranger = $this->factory()->create('mark-one-idor-stranger@example.com');
    $headers = $this->authHeaderFor($user);
    $strangerSearch = new SavedSearch($stranger, 'climate', false);
    $this->em()->persist($strangerSearch);
    $this->em()->flush();

    $client->request(
        'POST',
        '/api/entries/saved-searches/' . $strangerSearch->getId() . '/mark-read',
        server: $headers,
        content: json_encode(['until' => '2026-07-10T00:00:00+00:00'], \JSON_THROW_ON_ERROR),
    );

    self::assertResponseStatusCodeSame(404);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit --filter 'testMarkOneRead'`
Expected: FAIL — no such route (404 for the first test, or a routing error).

- [ ] **Step 3: Add the service method and DRY the existing one**

In `SavedSearchMarkReadService.php`, replace the body of `mark` and add `markOne`, extracting the shared marking into one private method:

```php
public function mark(User $user, \DateTimeImmutable $until): void
{
    $userId = (int) $user->getId();
    $this->markSearches($userId, $this->savedSearches->idsForUser($userId), $until);
}

public function markOne(User $user, int $savedSearchId, \DateTimeImmutable $until): void
{
    $this->markSearches((int) $user->getId(), [$savedSearchId], $until);
}

/** @param list<int> $searchIds */
private function markSearches(int $userId, array $searchIds, \DateTimeImmutable $until): void
{
    $this->readMarker->markRead($userId, $this->entries->unreadMemberIdsUpTo($userId, $searchIds, $until));
}
```

- [ ] **Step 4: Add the controller action**

In `SavedSearchEntriesController.php`, add after `one()` (the `NotFoundHttpException` import already exists):

```php
#[Route('/{id}/mark-read', name: 'api_entries_saved_search_one_mark_read', methods: ['POST'], requirements: ['id' => '\d+'])]
public function markOneRead(
    int $id,
    #[CurrentUser] User $user,
    #[MapRequestPayload] MarkSavedSearchesReadRequest $request,
): JsonResponse {
    $userId = (int) $user->getId();
    $this->savedSearches->findOneOwnedBy($id, $userId)
        ?? throw new NotFoundHttpException('No such saved search.');
    $this->markRead->markOne($user, $id, $request->until);

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit --filter 'testMarkOneRead'`
Expected: PASS (both).

- [ ] **Step 6: Lint and commit**

Run: `cd backend && composer cs && composer stan && composer md`
Expected: clean.

```bash
git add backend/src/Service/Reader/SavedSearchMarkReadService.php backend/src/Controller/Api/SavedSearchEntriesController.php backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php
git commit -m "feat(#1122): mark one saved search read by id"
```

---

### Task 2: Frontend — teach the query helpers the `'saved-search'` kind

**Files:**
- Modify: `frontend/src/app/reader/query.ts:150-157` (`hasUnreadFilter`), `:345-372` (`MarkReadTarget`, `markReadTarget`)
- Test: `frontend/src/app/reader/query.spec.ts`

**Interfaces:**
- Consumes: `Selection` with `kind: 'saved-search'`, `id: number | null`.
- Produces: `MarkReadTarget` union gains `{ scope: 'saved-search'; id: number }`; `markReadTarget({kind:'saved-search', id})` returns it (or `null` when `id` is null); `hasUnreadFilter({kind:'saved-search'})` is `true`.

- [ ] **Step 1: Write the failing tests**

Add to `query.spec.ts` (near the existing `saved-search` cases around line 301 and the `markReadTarget` / `hasUnreadFilter` blocks):

```ts
it('marks a single saved search read by its id', () => {
  expect(markReadTarget({ kind: 'saved-search', id: 42, unread: false })).toEqual({
    scope: 'saved-search',
    id: 42,
  });
});

it('has no mark-read target for a single saved search without an id', () => {
  expect(markReadTarget({ kind: 'saved-search', id: null, unread: false })).toBeNull();
});

it('offers the unread filter on a single saved search', () => {
  expect(hasUnreadFilter({ kind: 'saved-search', id: 42, unread: false })).toBe(true);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/query.spec.ts -t 'single saved search'`
Expected: FAIL — `markReadTarget` returns `null`; `hasUnreadFilter` returns `false`.

- [ ] **Step 3: Implement**

In `query.ts`, extend the `MarkReadTarget` union with `| { scope: 'saved-search'; id: number }`. Add a case to `markReadTarget` before `default`:

```ts
    case 'saved-search':
      return s.id != null ? { scope: 'saved-search', id: s.id } : null;
```

Extend `hasUnreadFilter` (the standing-list refinements) with `s.kind === 'saved-search'`:

```ts
export function hasUnreadFilter(s: Selection): boolean {
  return (
    canScopedRefresh(s) ||
    s.kind === 'for-you' ||
    s.kind === 'saved-searches' ||
    s.kind === 'saved-search' ||
    isSavedSearchResult(s)
  );
}
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/query.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/query.ts frontend/src/app/reader/query.spec.ts
git commit -m "feat(#1122): map the saved-search kind to a mark-read target and unread filter"
```

---

### Task 3: Frontend — the reader API call and the store's remove callback

**Files:**
- Modify: `frontend/src/app/reader/reader-api.ts:150-152`
- Modify: `frontend/src/app/reader/saved-searches.store.ts:115-119`
- Test: `frontend/src/app/reader/saved-searches.store.spec.ts`

**Interfaces:**
- Produces: `ReaderApi.markSingleSavedSearchRead(id: number, until: string): Observable<void>` → `POST /api/entries/saved-searches/{id}/mark-read` body `{ until }`. `SavedSearchesStore.removeSavedSearch(id: number, onSuccess?: () => void): void`.

- [ ] **Step 1: Write the failing store test**

Add to `saved-searches.store.spec.ts`, mirroring the existing `removeSavedSearch()` test at line 94:

```ts
it('removeSavedSearch() calls back on success', () => {
  const onSuccess = jest.fn();
  store.removeSavedSearch(2, onSuccess);
  ctrl.expectOne('https://api.test/api/saved-searches/2').flush(null);
  expect(onSuccess).toHaveBeenCalledTimes(1);
});
```

(Match the harness setup already used by the sibling test — reuse its `ctrl`/base-url pattern.)

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/saved-searches.store.spec.ts -t 'calls back on success'`
Expected: FAIL — `removeSavedSearch` ignores the second argument.

- [ ] **Step 3: Implement both**

In `reader-api.ts`, add beside `markSavedSearchesRead`:

```ts
markSingleSavedSearchRead(id: number, until: string): Observable<void> {
  return this.http.post<void>(`${this.base}/api/entries/saved-searches/${id}/mark-read`, { until });
}
```

In `saved-searches.store.ts`:

```ts
removeSavedSearch(id: number, onSuccess?: () => void): void {
  this.api.deleteSavedSearch(id).subscribe({
    next: () => {
      this.loaded.update((rows) => rows.filter((row) => row.id !== id));
      onSuccess?.();
    },
  });
}
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/saved-searches.store.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-api.ts frontend/src/app/reader/saved-searches.store.ts frontend/src/app/reader/saved-searches.store.spec.ts
git commit -m "feat(#1122): add single-saved-search mark-read API and a remove callback"
```

---

### Task 4: Frontend — wire the shell's mark-read, Save/Remove, and navigation

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts` — `markReadNow` (`:1035-1082`), `currentSavedSearch` (`:1111-1125`), `onToggleSavedSearch`/`confirmRemoveSavedSearch` (`:1147-1178`); add a `viewingSavedSearch` computed near `savedSearchResult` (`:288`).
- Modify: `frontend/src/app/reader/reader-shell.component.html:302,309`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`

**Interfaces:**
- Consumes: `markReadTarget` scope `'saved-search'`; `ReaderApi.markSingleSavedSearchRead`; `SavedSearchesStore.removeSavedSearch(id, onSuccess?)`; `activeSavedSearch()`.
- Produces: `viewingSavedSearch(): boolean` (true for the saved-search-result kind or the `'saved-search'` kind), used by the header template gate and the `mobile-icon-only` class.

- [ ] **Step 1: Write the failing shell tests**

Add a describe to `reader-shell.component.spec.ts`, reusing `bootSingleSavedSearch()` from the `#1118` describe (extract it to the outer scope if needed) and the `Dialog` mock pattern from the `#769` mark-read describe:

```ts
it('marks a single saved search read via its by-id endpoint, then reloads', () => {
  const f = bootSingleSavedSearch();
  jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(true) } as never);

  f.componentInstance.onMarkAllRead();

  const req = ctrl.expectOne('https://api.test/api/entries/saved-searches/4/mark-read');
  expect(req.request.method).toBe('POST');
  expect(req.request.body).toEqual({ until: expect.any(String) });
  req.flush(null);

  ctrl.expectOne((r) => r.url === 'https://api.test/api/entries/saved-searches/4');
  ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);
  ctrl.expectOne('https://api.test/api/saved-searches').flush({ savedSearches: [] });
});

it('turns on Mark all read and the unread filter for a single saved search', () => {
  const f = bootSingleSavedSearch();
  expect(f.componentInstance.canMarkAllRead()).toBe(true);
  const list = f.debugElement.query(By.directive(EntryListComponent))
    .componentInstance as EntryListComponent;
  expect(list.hasUnreadFilter()).toBe(true);
});

it('removes the viewed saved search and returns to the combined list', () => {
  const f = bootSingleSavedSearch();
  jest.spyOn(TestBed.inject(Dialog), 'open').mockReturnValue({ closed: of(true) } as never);
  const navigate = jest.spyOn(f.componentInstance['router'], 'navigate');

  f.componentInstance.onToggleSavedSearch();

  ctrl.expectOne('https://api.test/api/saved-searches/4').flush(null);
  expect(navigate).toHaveBeenCalledWith(['/searches/saved/all']);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-shell.component.spec.ts -t 'single saved search'`
Expected: FAIL — no by-id mark-read request; `canMarkAllRead()` false; no navigation.

- [ ] **Step 3: Implement the shell changes**

Add the mark-read branch in `markReadNow`, before the generic `markRead` call:

```ts
if (target.scope === 'saved-search') {
  this.entries.runThenReload(this.api.markSingleSavedSearchRead(target.id, until), () => {
    this.entries.load(queryFromSelection(this.selection()));
    this.subs.load();
    this.savedSearchesStore.load();
  });
  return;
}
```

Teach `currentSavedSearch` about the new kind (a saved-search view is, by definition, on a saved search):

```ts
readonly currentSavedSearch = computed(() => {
  if (this.selection().kind === 'saved-search') return this.activeSavedSearch();
  const current = this.searchedTermAndMode();
  if (current === null) return null;
  return (
    this.savedSearchesStore
      .savedSearches()
      .find(
        (saved) =>
          saved.term === current.term &&
          saved.wholeWord === current.wholeWord &&
          saved.phrase === current.phrase,
      ) ?? null
  );
});
```

Navigate away when the removed search is the one on screen. In `confirmRemoveSavedSearch`, pass a callback only for the by-id view:

```ts
ref.closed.subscribe((confirmed) => {
  if (!confirmed) return;
  const returnToCombined =
    this.selection().kind === 'saved-search'
      ? () => void this.router.navigate(['/searches/saved/all'])
      : undefined;
  this.savedSearchesStore.removeSavedSearch(id, returnToCombined);
});
```

Add the computed the template needs, beside `savedSearchResult`:

```ts
readonly viewingSavedSearch = computed(
  () => this.savedSearchResult() || this.selection().kind === 'saved-search',
);
```

- [ ] **Step 4: Update the header template**

In `reader-shell.component.html`, change the Save/Remove gate (line 302) and the mobile-icon-only class (line 309):

```html
  @if (selection().kind === 'search' || selection().kind === 'saved-search') {
```
```html
      [class.mobile-icon-only]="viewingSavedSearch()"
```

- [ ] **Step 5: Run to verify pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/reader-shell.component.spec.ts -t 'single saved search'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.html frontend/src/app/reader/reader-shell.component.spec.ts
git commit -m "feat(#1122): restore Mark all read, unread filter, and Remove on a saved search"
```

---

### Task 5: Verify end to end, dead-code sweep, and quality pass

**Files:** whole diff.

- [ ] **Step 1: Full frontend suite and lint**

Run: `docker compose exec -T frontend npm test` then `cd frontend && npm run check`
Expected: green.

- [ ] **Step 2: Full backend suite (both legs) and the migration/validate check**

Run: `cd backend && php bin/phpunit` and, with Docker up, `docker compose exec php composer test`
Expected: green. (No migration in this change; no schema edit.)

- [ ] **Step 3: Manual/real-render confirmation**

Open a saved search from the sidebar. Confirm the header shows the All items / only unread switch, Mark all read, and Remove; that Mark all read marks the search's members and reloads; that Remove deletes it and returns to the combined list. Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`

- [ ] **Step 4: Remove the legacy `searchOrigin` path**

Delete the dead saved-search-result URL scheme, since a saved search is now only the `'saved-search'` kind:
- `query.ts`: remove the `searchOrigin?: 'saved'` field from `Selection`; drop `a.searchOrigin === b.searchOrigin` from `sameSelection`; simplify `isDirectSearch` to `selection.kind === 'search'`; delete `isSavedSearchResult`; drop `isSavedSearchResult(s)` from `hasUnreadFilter`; remove `'searchOrigin'` from `SELECTION_PARAM_NAMES`; in `selectionFromParams` make the search branch `{ kind: 'search', id: null, unread: false, term }` and drop the `savedOrigin` read; delete `savedSearchParams` and `savedSearchTerm` (no production consumer remains).
- `reader-shell.component.ts`: remove the `isSavedSearchResult` import and the `savedSearchResult` computed; make `viewingSavedSearch` just `this.selection().kind === 'saved-search'`; drop `searchOrigin: null` from the `onSearch` navigation.
- `list-scroll-memory.ts`: replace the `s.searchOrigin === 'saved' ? 'saved-search' : s.kind` line with `s.kind`.
- Update the jest specs that reference the removed symbols (`query.spec.ts`, `query.saved-search.spec.ts`, `reader-shell.component.spec.ts`, `recommendations.service.spec.ts`, `list-scroll-memory.spec.ts`, `entry-list.component.spec.ts`).
- Migrate the e2e specs off `?q=…&searchOrigin=saved` to the `/searches/saved/<slug>` path (`saved-search-layout.spec.ts`, `list-header-actions-mobile.spec.ts`, and any sibling under `frontend/e2e/` that used the old URL).
- Remove any narration comments the change orphaned.

- [ ] **Step 5: `/simplify`**

Run the `/simplify` slash command over the diff and apply its cleanups.

- [ ] **Step 6: Commit any sweep/simplify changes**

```bash
git add -A
git commit -m "refactor(#1122): dead-code and simplification pass"
```
