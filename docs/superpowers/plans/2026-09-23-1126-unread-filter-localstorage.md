# #1126 Unread filter in localStorage — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The "All posts / only unread" filter keeps working, but its state moves from `?unread=1` to `localStorage`, so it survives every navigation (including into a saved search) and also applies to a direct search.

**Architecture:** A root `UnreadFilterService` holds a boolean signal backed by `localStorage`. The URL parsers stop reading `unread`; the shell combines the parsed selection with the preference through a pure `withUnreadPreference()`. The switch in the entry list becomes a button that emits the wanted state; the shell writes it to the service, and the existing list-load effect reloads.

**Tech Stack:** Angular 20 (standalone, signals), Jest (jsdom), Playwright.

**Spec:** `docs/superpowers/specs/2026-09-23-1126-unread-filter-localstorage-design.md`

## Global Constraints

- Frontend only. No backend change.
- `localStorage` key `sfr.unread-only`, values `'1'` / `'0'`; anything else reads as `false`.
- `?unread=1` is never read or written again. No migration of old bookmarks.
- Favorites, kept, viewed: no switch, always `unread: false`, never change the stored value.
- Run frontend tests inside Docker: `docker compose exec -T frontend npx jest <path>` (never two jest runs at once — the container OOMs). The CI gate is `docker compose exec -T frontend npm run check`.
- Comments: default to none; one line, three at most. Delete comments that describe URL-level unread handling.
- Commit format `type(#1126): …`. Work on `feature/1126-unread-filter-localstorage`; never commit to `develop`.

---

### Task 1: `UnreadFilterService`

**Files:**
- Create: `frontend/src/app/reader/unread-filter.service.ts`
- Test: `frontend/src/app/reader/unread-filter.service.spec.ts`

**Interfaces:**
- Produces: `UnreadFilterService` (`providedIn: 'root'`) with `readonly unreadOnly: WritableSignal<boolean>` and `set(unreadOnly: boolean): void`.

- [ ] **Step 1: Write the failing test**

```ts
import { TestBed } from '@angular/core/testing';
import { UnreadFilterService } from './unread-filter.service';

describe('UnreadFilterService', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
  });

  it('shows everything when nothing is stored', () => {
    expect(new UnreadFilterService().unreadOnly()).toBe(false);
  });

  it('reads a stored unread-only choice back', () => {
    localStorage.setItem('sfr.unread-only', '1');
    expect(new UnreadFilterService().unreadOnly()).toBe(true);
  });

  it('persists and applies each state', () => {
    const svc = new UnreadFilterService();

    svc.set(true);
    expect(localStorage.getItem('sfr.unread-only')).toBe('1');
    expect(svc.unreadOnly()).toBe(true);

    svc.set(false);
    expect(localStorage.getItem('sfr.unread-only')).toBe('0');
    expect(svc.unreadOnly()).toBe(false);
  });

  it('reads a garbage stored value as show-all', () => {
    localStorage.setItem('sfr.unread-only', 'yes');
    expect(new UnreadFilterService().unreadOnly()).toBe(false);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/unread-filter.service.spec.ts`
Expected: FAIL — cannot find module `./unread-filter.service`.

- [ ] **Step 3: Write the implementation**

```ts
import { Injectable, signal } from '@angular/core';

const KEY = 'sfr.unread-only';

@Injectable({ providedIn: 'root' })
export class UnreadFilterService {
  readonly unreadOnly = signal<boolean>(localStorage.getItem(KEY) === '1');

  set(unreadOnly: boolean): void {
    localStorage.setItem(KEY, unreadOnly ? '1' : '0');
    this.unreadOnly.set(unreadOnly);
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/unread-filter.service.spec.ts`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/unread-filter.service.ts frontend/src/app/reader/unread-filter.service.spec.ts
git commit -m "feat(#1126): hold the unread filter in localStorage"
```

---

### Task 2: `withUnreadPreference` and search takes the filter

**Files:**
- Modify: `frontend/src/app/reader/query.ts` (`hasUnreadFilter` ~line 136-146, new function after it)
- Test: `frontend/src/app/reader/query.spec.ts` (`describe('hasUnreadFilter')` ~line 348)

**Interfaces:**
- Produces: `export function withUnreadPreference(selection: Selection, unreadOnly: boolean): Selection` in `query.ts`. `hasUnreadFilter` returns `true` for `kind === 'search'`.

- [ ] **Step 1: Write the failing tests**

In `query.spec.ts`, add `withUnreadPreference` to the import list from `./query`. In `describe('hasUnreadFilter')`, move the `search` line from the `false` group to the `true` group:

```ts
    expect(hasUnreadFilter({ kind: 'search', id: null, unread: false, term: 'x' })).toBe(true);
```

Append a new describe block after `describe('hasUnreadFilter')`:

```ts
describe('withUnreadPreference (#1126)', () => {
  it('refines every list that offers the switch', () => {
    const lists: Selection[] = [
      { kind: 'all', id: null, unread: false },
      { kind: 'tag', id: 3, unread: false },
      { kind: 'subscription', id: 7, unread: false },
      { kind: 'for-you', id: null, unread: false },
      { kind: 'saved-searches', id: null, unread: false },
      { kind: 'saved-search', id: 42, unread: false },
      { kind: 'search', id: null, unread: false, term: 'angular' },
    ];
    for (const list of lists) {
      expect(withUnreadPreference(list, true)).toEqual({ ...list, unread: true });
      expect(withUnreadPreference(list, false)).toEqual({ ...list, unread: false });
    }
  });

  it('leaves the state views showing everything', () => {
    for (const kind of ['favorites', 'kept', 'viewed'] as const) {
      const list: Selection = { kind, id: null, unread: false };
      expect(withUnreadPreference(list, true)).toEqual(list);
    }
  });
});
```

Import `Selection` as a type from `./query` if the spec does not already.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/query.spec.ts`
Expected: FAIL — `withUnreadPreference` is not exported; `hasUnreadFilter` returns false for search.

- [ ] **Step 3: Implement**

Replace `hasUnreadFilter` and its docblock in `query.ts` with:

```ts
/** Whether the list offers the "All posts / only unread" switch. Favorites,
 *  kept and viewed are already filters on entry state, so they do not. */
export function hasUnreadFilter(s: Selection): boolean {
  return (
    canScopedRefresh(s) ||
    s.kind === 'for-you' ||
    s.kind === 'saved-searches' ||
    s.kind === 'saved-search' ||
    s.kind === 'search'
  );
}

export function withUnreadPreference(selection: Selection, unreadOnly: boolean): Selection {
  return hasUnreadFilter(selection) ? { ...selection, unread: unreadOnly } : selection;
}
```

In `queryFromSelection`, `case 'search'`: delete the comment `// Only a saved-search result ever carries unread …` (both lines). The return statement stays unchanged — it already forwards `unread: true`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/query.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/query.ts frontend/src/app/reader/query.spec.ts
git commit -m "feat(#1126): let a direct search take the unread filter"
```

---

### Task 3: Cut over — the shell reads the service, the URL stops carrying `unread`

This is one task because the parts only work together: once the parsers stop reading `?unread=1`, the switch must already write the service.

**Files:**
- Modify: `frontend/src/app/reader/query.ts` (`selectionFromParams` ~line 207-260, `SELECTION_PARAM_NAMES` note ~line 160)
- Modify: `frontend/src/app/reader/reader-matcher.ts` (`selectionFromRoute`)
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (~line 273-286)
- Modify: `frontend/src/app/reader/reader-shell.component.html` (`<app-entry-list>` outputs ~line 132)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` (outputs ~line 228-242)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html` (switch ~line 131-158)
- Test: `frontend/src/app/reader/query.spec.ts`, `frontend/src/app/reader/reader-shell.component.spec.ts`, `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `UnreadFilterService.unreadOnly()` / `.set()` (Task 1); `withUnreadPreference()` (Task 2).
- Produces: `EntryListComponent.unreadOnlyChange = output<boolean>()`; `ReaderShellComponent.unreadFilter` (public readonly, used by the template).

- [ ] **Step 1: Rewrite the parser specs to the new contract**

In `query.spec.ts`, `describe('selectionFromParams')`:
- Replace `it('reads unread=1 as unread-only, and anything else as show-all', …)` with:

```ts
  it('ignores an unread parameter — the filter lives in localStorage (#1126)', () => {
    expect(selectionFromParams(pm({ unread: '1' })).selection.unread).toBe(false);
    expect(selectionFromParams(pm({ view: 'for-you', unread: '1' })).selection.unread).toBe(false);
  });
```

- `it('reads favorites/kept and ignores the unread toggle there', …)`: rename to `'reads favorites/kept'` and drop `unread: '0'` from the `pm(...)` argument.
- `it('reads for-you, and keeps its unread refinement (#710)', …)`: rename to `'reads for-you'`, keep only the first expectation, and drop `unread: '0'` from its argument.
- Delete `it('still ignores the unread toggle on the saved views, which already filter by state', …)`.

In `describe('listSelectionFrom (#579)')`: delete `it('keeps the unread refinement the search hid', …)`.

In `describe('selectionFromRoute')`:
- `it('falls back to the combined view for a slug with no leading id', …)`: pass `noQuery` instead of `convertToParamMap({ unread: '1' })` and expect `unread: false`.
- Replace `it('carries unread=1 from the query params onto a path selection', …)` with:

```ts
  it('ignores an unread parameter on a path selection (#1126)', () => {
    const { selection } = selectionFromRoute(
      convertToParamMap({ savedSearch: '42-climate' }),
      convertToParamMap({ unread: '1' }),
    );
    expect(selection.unread).toBe(false);
  });
```

- [ ] **Step 2: Rewrite the entry-list switch specs**

In `entry-list.component.spec.ts`, `describe('unread filter switch')`:
- Replace `it('shows the switch only for the browsable lists, not search or saved views', …)` with:

```ts
    it('shows the switch for every browsable list and a search, not the state views', () => {
      for (const kind of ['all', 'tag', 'subscription', 'for-you', 'search'] as const) {
        const el = mount({ selection: { kind, id: null, unread: true, term: 'x' } })
          .nativeElement as HTMLElement;
        expect(el.querySelector('.unread-switch')).not.toBeNull();
      }
      for (const kind of ['favorites', 'kept', 'viewed'] as const) {
        const el = mount({
          selection: { kind, id: null, unread: false },
          canMarkAllRead: false,
        }).nativeElement as HTMLElement;
        expect(el.querySelector('.unread-switch')).toBeNull();
      }
    });
```

- Replace the comment block and `it('drops the param to reach all when on, and sets unread=1 to reach unread when off', …)` with:

```ts
    it('asks for the opposite state when clicked', () => {
      for (const unread of [true, false]) {
        const f = mount({ selection: { kind: 'all', id: null, unread } });
        const asked: boolean[] = [];
        f.componentInstance.unreadOnlyChange.subscribe((value) => asked.push(value));

        (f.nativeElement.querySelector('.unread-switch') as HTMLButtonElement).click();

        expect(asked).toEqual([!unread]);
      }
    });
```

- [ ] **Step 3: Rewrite and add the shell specs**

In `reader-shell.component.spec.ts`, import `UnreadFilterService` from `./unread-filter.service`.

`describe('marking everything above the fold as read (#1080)')` → `bootUnreadView()`: replace `qp.next(convertToParamMap({ unread: '1' }));` with `TestBed.inject(UnreadFilterService).set(true);`.

Inside `describe('a single saved search addressed by its path slug (#1118)')`, after `bootSingleSavedSearch()`, add:

```ts
    it('keeps the unread filter on when a tag list moves to a saved search (#1126)', () => {
      localStorage.setItem('sfr.unread-only', '1');
      const f = boot();
      f.componentInstance.savedSearchesStore.load();
      ctrl
        .expectOne('https://api.test/api/saved-searches')
        .flush({ savedSearches: [savedClimate] });
      qp.next(convertToParamMap({ tag: '3' }));
      f.detectChanges();
      const tagList = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
      expect(tagList.request.params.get('view')).toBe('unread');
      tagList.flush({ entries: [], nextCursor: null });

      qp.next(convertToParamMap({}));
      pp.next(convertToParamMap({ savedSearch: '4-climate' }));
      f.detectChanges();

      const saved = ctrl.expectOne(
        (r) => r.url === 'https://api.test/api/entries/saved-searches/4',
      );
      expect(saved.request.params.get('unread')).toBe('1');
      expect(f.componentInstance.selection().unread).toBe(true);
    });
```

Add a new top-level describe next to the #1118 one:

```ts
  describe('the unread filter lives in localStorage (#1126)', () => {
    it('reloads the list when the switch flips, without navigating', () => {
      const f = boot();
      const nav = jest.spyOn(TestBed.inject(Router), 'navigate');

      f.componentInstance.unreadFilter.set(true);
      f.detectChanges();

      const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
      expect(req.request.params.get('view')).toBe('unread');
      req.flush({ entries: [], nextCursor: null });
      expect(nav).not.toHaveBeenCalled();
    });

    it('filters a direct search to unread', () => {
      localStorage.setItem('sfr.unread-only', '1');
      const f = boot();
      qp.next(convertToParamMap({ q: 'angular' }));
      f.detectChanges();

      const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries/search');
      expect(req.request.params.get('unread')).toBe('1');
      req.flush({ entries: [], nextCursor: null, matchedWords: [] });
    });

    it('ignores an unread parameter in the URL', () => {
      const f = boot();
      qp.next(convertToParamMap({ unread: '1' }));
      f.detectChanges();

      expect(f.componentInstance.selection().unread).toBe(false);
      ctrl.expectNone((r) => r.url === 'https://api.test/api/entries');
    });
  });
```

`boot()` seeds its first `/api/entries` flush regardless of params, so seeding `localStorage` before it works. If the search-flush body shape differs from other search specs in this file (see ~line 550), copy theirs.

- [ ] **Step 4: Run the three specs to verify they fail**

Run, one after the other:
`docker compose exec -T frontend npx jest src/app/reader/query.spec.ts`
`docker compose exec -T frontend npx jest src/app/reader/entry-list/entry-list.component.spec.ts`
`docker compose exec -T frontend npx jest src/app/reader/reader-shell.component.spec.ts`
Expected: FAIL — parsers still read `unread`; `unreadOnlyChange` and `unreadFilter` do not exist.

- [ ] **Step 5: Stop parsing `unread`**

`query.ts`, `selectionFromParams`:
- Delete the `unread` constant and its three-line comment (`// unread refines the current list …`).
- In the search branch, delete the sentence of the comment about saved searches never carrying unread; keep the first sentence about `?q=` ignoring tag/feed parameters.
- Every `selection = { …, unread }` becomes `unread: false`. The favorites/kept/viewed, for-you and saved-searches branches collapse their comments to at most one line each or none — none of them may mention unread any more.
- Delete the note `// Unread refines the selected list, so navigation does not clear it.` above `type SelectionParamName`.

`reader-matcher.ts`, `selectionFromRoute`: delete `const unread = queryParams.get('unread') === '1';` and write `unread: false` in both returned selections.

- [ ] **Step 6: Wire the shell**

`reader-shell.component.ts` — import `UnreadFilterService` and `withUnreadPreference`; declare the injection before `parsed`:

```ts
  readonly unreadFilter = inject(UnreadFilterService);
```

Replace the `selection` computed:

```ts
  readonly selection = computed(
    () => withUnreadPreference(this.parsed().selection, this.unreadFilter.unreadOnly()),
    { equal: sameSelection },
  );
```

Keep the existing `// Structural equality …` comment above it.

`reader-shell.component.html` — on `<app-entry-list>`, next to `(markAllRead)`:

```html
          (unreadOnlyChange)="unreadFilter.set($event)"
```

- [ ] **Step 7: Turn the switch into a button**

`entry-list.component.ts` — beside the other outputs:

```ts
  readonly unreadOnlyChange = output<boolean>();
```

`entry-list.component.html` — replace the `<a #unreadSwitch …>…</a>` element (keep the inner `app-icon`, `.txt` span and their comments) with:

```html
      <!-- One switch, not a two-option toggle: filled circle means unread-only,
           hollow means all, matching the "filled means unread" row convention (#602). -->
      <button
        appListAction
        class="unread-switch mobile-icon-only"
        type="button"
        role="switch"
        [attr.aria-checked]="selection().unread"
        [attr.aria-label]="'reader.unread' | transloco"
        [attr.title]="'reader.unread' | transloco"
        (click)="unreadOnlyChange.emit(!selection().unread)"
      >
```

and close it with `</button>`. The old comment's `keydown.space` sentence goes. If `RouterLink` is no longer used anywhere in `entry-list.component.html`, remove it from the component's `imports` (ESLint/Angular will flag it otherwise).

- [ ] **Step 8: Run the three specs to verify they pass**

Run each of the three commands from Step 4 again, one after the other.
Expected: PASS.

- [ ] **Step 9: Sweep for leftovers**

Run: `grep -rn "unread=1\|'unread')\|unread: '1'" frontend/src/app --include='*.ts' --include='*.html'`
Expected: only hits in `reader-api.ts` / `reader-api.spec.ts` (the backend API parameter). Delete any other hit or comment that still describes the filter as a URL parameter.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader
git commit -m "feat(#1126): read the unread filter from localStorage, not the URL"
```

---

### Task 4: e2e — the filter survives navigation

**Files:**
- Modify: `frontend/e2e/saved-searches-combined.spec.ts`

**Interfaces:**
- Consumes: the switch as `getByRole('switch', { name: 'only unread' })`; the stored key `sfr.unread-only`.

- [ ] **Step 1: Stub the single saved-search endpoint**

In `stubReaderData`, add a route for one saved search that honours `unread` like the combined one:

```ts
  await page.route(
    (url) => /^\/api\/entries\/saved-searches\/\d+$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const unread = new URL(route.request().url()).searchParams.get('unread') === '1';
      const entries = unread ? UNREAD_ENTRIES : ALL_ENTRIES;
      await route.fulfill({ status: 200, json: { entries, nextCursor: null } });
    },
  );
```

- [ ] **Step 2: Own the stored state**

At the start of `signInAsAdmin`, before `page.goto('/login')`:

```ts
  await page.addInitScript(() => localStorage.removeItem('sfr.unread-only'));
```

Note: an init script runs on every navigation. Both tests below navigate only through clicks after the first `goto`, or through `page.goto` before the switch is set — keep it that way.

- [ ] **Step 3: Rewrite the switch test**

Replace `test('the unread switch narrows the combined list', …)` with:

```ts
test('the unread switch narrows the list and stays on into a saved search', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.locator('a.savedsearch-toggle', { hasText: 'Saved searches' }).click();
  const rows = page.locator('.rows article');
  await expect(rows).toHaveCount(2);

  const unreadSwitch = page.getByRole('switch', { name: 'only unread' });
  await unreadSwitch.click();

  await expect(rows).toHaveCount(1);
  await expect(page).not.toHaveURL(/unread=/);
  await expect(rows.first().locator('app-saved-search-pills .pill .name')).toHaveText('climate');

  await rows.first().locator('app-saved-search-pills .pill').click();

  await expect(page).toHaveURL(/\/searches\/saved\/501-climate/);
  await expect(unreadSwitch).toHaveAttribute('aria-checked', 'true');
  await expect(rows).toHaveCount(1);
});
```

- [ ] **Step 4: Run it against the Docker stack**

Precondition: the Docker stack runs from this checkout and serves the current code (check the frontend container is current before trusting a result).
Run: `cd frontend && npx playwright test e2e/saved-searches-combined.spec.ts e2e/list-header-actions-mobile.spec.ts`
Expected: PASS. `list-header-actions-mobile.spec.ts` is included because a direct search header now carries the switch too.

- [ ] **Step 5: Commit**

```bash
git add frontend/e2e/saved-searches-combined.spec.ts
git commit -m "test(#1126): prove the unread filter survives a move into a saved search"
```

---

### Task 5: Gate and real-render check

- [ ] **Step 1: Full frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint and Jest all green. Fix formatting with Prettier if it complains; commit as `style(#1126): apply prettier formatting`.

- [ ] **Step 2: Look at the real render**

In the browser pane at the Mobile viewport and on desktop:
- The switch looks the same as before on a tag list (the `<a>` → `<button>` change must not alter size, border or the circle glyph).
- Turn it on in All items, open a saved search from the sidebar, then a direct search: the switch stays on and each list shows only unread posts.
- Reload the page: the switch is still on.
- Favorites shows no switch and all its posts; back in All items the switch is still on.
- A direct search header on mobile is not crowded (save, switch, mark all).

- [ ] **Step 3: Commit any fix, then hand over for review**

Open the PR against `develop` with `Closes #1126` only when the user asks.
