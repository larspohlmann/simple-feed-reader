# Saved-search sidebar ranking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rank the sidebar's saved searches unread-first, cap the visible list at six rows, and add a "Show N more" link that reveals the rest.

**Architecture:** A frontend-only change inside `SidebarComponent`. The component holds a *frozen* sort order (a list of saved-search ids) so live badge changes never reshuffle the list under the reader. A computed derives the visible slice (top six, plus the active search pinned when it falls outside the six) and the hidden count. The store, the API, and the entity are untouched.

**Tech Stack:** Angular 20 (standalone components, signals), Transloco i18n, Jest (jsdom) via the Docker frontend container.

**Spec:** GitHub issue [#876](https://github.com/larspohlmann/simple-feed-reader/issues/876) — "Rank and cap saved searches in the sidebar (unread-first, top 6, show more)".

## Global Constraints

- Frontend only. Do not change the API, `SavedSearch` entity, repository, or the `SavedSearchWire` shape. All data is already on the client.
- Sort key: `unreadCount` descending, then `id` descending. There is no total-match count and no created-date field; `id` is the creation-order proxy.
- Visible cap: **6** rows. The "more" link appears only when strictly more than six rows exist.
- The expand ("Show more") state and the frozen order are **in memory only** (reset on reload). The saved-search section keeps its existing chevron and stays **collapsed by default**.
- Freeze the order while the section is open. Recompute only on: section open, full page reload (component init), and a structural change (a saved search created or deleted). Do **not** recompute on: reading an entry, a counts-poll tick, or SPA navigation.
- A count of `0` shows no badge. A row read to `0` keeps its slot until the next recompute.
- No hex colours and no ad-hoc `px` in `.scss` outside `src/app/theme/` — use tokens.
- Run tests inside the Docker frontend container: `docker compose exec -T frontend npm test`. The CI gate is `npm run check` (run from `frontend/`).

## File Structure

- `frontend/src/app/reader/sidebar/sidebar.component.ts` — add the frozen order, the sort helper, the visible/hidden computeds, the more/less toggle, and the section-open re-freeze. **Modify.**
- `frontend/src/app/reader/sidebar/sidebar.component.html` — render the visible slice instead of the full list; add the "Show N more" / "Show less" link. **Modify** (the `@if (savedSearches().length)` block, lines ~95–187).
- `frontend/src/app/reader/sidebar/sidebar.component.scss` — style `.savedsearch-more` as an indented text link. **Modify.**
- `frontend/public/i18n/en.json` and `frontend/public/i18n/de.json` — add `reader.showMoreSavedSearches` and `reader.showLessSavedSearches`. **Modify.**
- `frontend/src/app/reader/sidebar/sidebar.component.spec.ts` — add tests for order, freeze, cap, more/less, and the active pin. **Modify.**

No new files. The store (`saved-searches.store.ts`) and models (`models.ts`) stay as they are.

---

### Task 1: Frozen unread-first order

Rank the saved searches by unread count (then id), and freeze that order so reading an entry does not reshuffle the list. Recompute on section open and on a structural change (create/delete).

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `savedSearches = input<SavedSearchDto[]>([])` and `savedSearchLinks` (existing), each row carrying `id: number` and `unreadCount: number`.
- Produces:
  - `SIDEBAR_SAVED_SEARCH_LIMIT = 6` (module const).
  - `orderedSavedSearches: Signal<Array<SavedSearchDto & { params: ... }>>` — the full list in frozen order, with live params and counts.
  - `savedSearchesExpanded` (existing section chevron signal) and `toggleSavedSearches()` (existing) — extended to re-freeze on open.
  - `frozenSavedSearchOrder = signal<number[]>([])`, `private refreezeSavedSearchOrder()`.

- [ ] **Step 1: Create the branch**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git checkout develop && git pull
git checkout -b feature/876-saved-search-sidebar-ranking
```

- [ ] **Step 2: Write the failing test — order is unread-first, then id-desc**

Add inside the existing `describe('saved searches', ...)` block in `sidebar.component.spec.ts`. Helper to read the rendered terms in order:

```ts
const openSaved = (f: ReturnType<typeof mount>) => {
  (f.nativeElement.querySelector('.savedsearch-head .chevzone') as HTMLButtonElement).click();
  f.detectChanges();
};
const terms = (f: ReturnType<typeof mount>) =>
  Array.from(f.nativeElement.querySelectorAll('.savedsearch-item .saved-term')).map(
    (n) => (n as HTMLElement).textContent?.trim(),
  );
const saved = (id: number, term: string, unreadCount: number): SavedSearchDto => ({
  id,
  term,
  wholeWord: false,
  phrase: false,
  position: 0,
  unreadCount,
  includeInDigest: false,
});

it('orders saved searches unread-first, then by id descending', () => {
  const f = mount({
    savedSearches: [saved(1, 'oldest', 0), saved(2, 'busy', 5), saved(3, 'quiet', 0), saved(4, 'busier', 5)],
  });
  openSaved(f);
  // unread>0 first, by count desc then id desc: busier(4,5), busy(2,5) -> id desc; then quiet(3,0), oldest(1,0)
  expect(terms(f)).toEqual(['busier', 'busy', 'quiet', 'oldest']);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `docker compose exec -T frontend npx jest --silent -t "orders saved searches unread-first"`
Expected: FAIL — the current template renders insertion order (`oldest, busy, quiet, busier`).

- [ ] **Step 4: Add the constant, the sort helper, the frozen order, and the ordered computed**

In `sidebar.component.ts`, add near the other module consts (after line 47):

```ts
/** How many saved-search rows the sidebar shows before the "Show more" link. */
const SIDEBAR_SAVED_SEARCH_LIMIT = 6;

/** Ids of a saved-search list, ranked unread-first (count desc) then newest
 *  first (id desc). Id is the creation-order proxy — there is no date field. */
const rankedSavedSearchIds = (searches: readonly SavedSearchDto[]): number[] =>
  [...searches].sort((a, b) => b.unreadCount - a.unreadCount || b.id - a.id).map((s) => s.id);

const sameIds = (a: readonly number[], b: readonly number[]): boolean => {
  if (a.length !== b.length) return false;
  const set = new Set(b);
  return a.every((id) => set.has(id));
};
```

Then inside the class, replace the `savedSearchesExpanded` / `savedSearchesUnread` / `toggleSavedSearches` region (lines 174–183) with:

```ts
readonly savedSearchesExpanded = signal(false);

/** The frozen display order (saved-search ids). Recomputed only when the
 *  section opens or the set of searches changes — never on a count change —
 *  so reading an entry does not reshuffle the list under the reader (#876). */
private readonly frozenSavedSearchOrder = signal<number[]>([]);

/** Re-rank on a structural change: the initial load, a create, or a delete.
 *  Keyed on the id set only, so a count-only change leaves the order frozen. */
private readonly refreezeOnStructuralChange = effect(() => {
  const ids = this.savedSearches().map((s) => s.id);
  if (!sameIds(ids, untracked(this.frozenSavedSearchOrder))) {
    this.frozenSavedSearchOrder.set(rankedSavedSearchIds(this.savedSearches()));
  }
});

/** The saved searches in frozen order, each with live params and count. */
protected readonly orderedSavedSearches = computed(() => {
  const byId = new Map(this.savedSearchLinks().map((row) => [row.id, row]));
  return this.frozenSavedSearchOrder()
    .map((id) => byId.get(id))
    .filter((row): row is NonNullable<typeof row> => row !== undefined);
});

/** Total unread matches across all saved searches, for the collapsed badge. */
readonly savedSearchesUnread = computed(() =>
  this.savedSearches().reduce((sum, saved) => sum + saved.unreadCount, 0),
);

toggleSavedSearches(): void {
  const opening = !this.savedSearchesExpanded();
  this.savedSearchesExpanded.set(opening);
  // Opening the section is a fresh view: re-rank with the current counts.
  if (opening) this.frozenSavedSearchOrder.set(rankedSavedSearchIds(this.savedSearches()));
}
```

- [ ] **Step 5: Point the template at the ordered list**

In `sidebar.component.html`, change the saved-search loop (line 125) from `savedSearchLinks()` to `orderedSavedSearches()`:

```html
@for (saved of orderedSavedSearches(); track saved.id) {
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker compose exec -T frontend npx jest --silent -t "orders saved searches unread-first"`
Expected: PASS.

- [ ] **Step 7: Write the failing freeze tests**

```ts
it('keeps the frozen order when a count drops to zero (no reshuffle on read)', () => {
  const f = mount({ savedSearches: [saved(1, 'a', 3), saved(2, 'b', 5)] });
  openSaved(f);
  expect(terms(f)).toEqual(['b', 'a']); // 5 before 3
  // A read drops b's count below a's; the order must stay frozen while open.
  f.componentRef.setInput('savedSearches', [saved(1, 'a', 3), saved(2, 'b', 1)]);
  f.detectChanges();
  expect(terms(f)).toEqual(['b', 'a']);
});

it('re-ranks on the next section open', () => {
  const f = mount({ savedSearches: [saved(1, 'a', 3), saved(2, 'b', 5)] });
  openSaved(f); // b, a
  f.componentRef.setInput('savedSearches', [saved(1, 'a', 3), saved(2, 'b', 1)]);
  f.detectChanges();
  openSaved(f); // close
  openSaved(f); // open again -> re-rank: a(3) before b(1)
  expect(terms(f)).toEqual(['a', 'b']);
});

it('re-ranks immediately when a saved search is deleted (structural change)', () => {
  const f = mount({ savedSearches: [saved(1, 'a', 3), saved(2, 'b', 5), saved(3, 'c', 4)] });
  openSaved(f); // b(5), c(4), a(3)
  f.componentRef.setInput('savedSearches', [saved(1, 'a', 3), saved(3, 'c', 4)]);
  f.detectChanges();
  expect(terms(f)).toEqual(['c', 'a']); // c(4) before a(3), no stale b
});
```

- [ ] **Step 8: Run the freeze tests to verify they pass**

Run: `docker compose exec -T frontend npx jest --silent -t "frozen|re-ranks"`
Expected: PASS (the implementation from Step 4 already satisfies them). If the first freeze test fails because the effect re-ranks on a count change, confirm `refreezeOnStructuralChange` keys on the id set only.

- [ ] **Step 9: Run the full sidebar spec to catch regressions**

Run: `docker compose exec -T frontend npx jest --silent sidebar.component`
Expected: PASS — existing saved-search tests assert row *counts* and presence, not insertion order, so re-ranking does not break them.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader/sidebar/sidebar.component.ts frontend/src/app/reader/sidebar/sidebar.component.html frontend/src/app/reader/sidebar/sidebar.component.spec.ts
git commit -m "feat(#876): rank saved searches unread-first with a frozen order"
```

---

### Task 2: Cap at six with a "Show more" / "Show less" link

Show only the top six rows; add an indented text link that reveals the rest and collapses back. Reset the expansion when the section re-opens.

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `orderedSavedSearches`, `SIDEBAR_SAVED_SEARCH_LIMIT` (Task 1).
- Produces:
  - `savedSearchListExpanded = signal(false)` — the more/less state (distinct from the section chevron).
  - `visibleSavedSearches: Signal<...>` — the rows to render (top six for now; Task 3 adds the pin).
  - `hiddenSavedSearchCount: Signal<number>`, `hasHiddenSavedSearches: Signal<boolean>`.
  - `toggleSavedSearchList(): void`.
  - i18n keys `reader.showMoreSavedSearches` (`{{count}}`), `reader.showLessSavedSearches`.

- [ ] **Step 1: Add the i18n keys**

In `frontend/public/i18n/en.json`, inside the `"reader"` object next to the saved-search keys (after `"toggleSavedSearches"`, ~line 841):

```json
    "showMoreSavedSearches": "Show {{count}} more",
    "showLessSavedSearches": "Show less",
```

In `frontend/public/i18n/de.json`, at the matching place:

```json
    "showMoreSavedSearches": "{{count}} weitere anzeigen",
    "showLessSavedSearches": "Weniger anzeigen",
```

- [ ] **Step 2: Write the failing cap + more/less tests**

Reuse `saved`, `openSaved`, `terms` from Task 1.

```ts
const many = Array.from({ length: 8 }, (_, i) => saved(i + 1, `s${i + 1}`, 8 - i));
// counts 8..1, so id 1 (count 8) ... id 8 (count 1): already ranked by both keys.

it('shows only six rows and a "Show more" link when there are more than six', () => {
  const f = mount({ savedSearches: many });
  openSaved(f);
  expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
  const more = f.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement;
  expect(more).not.toBeNull();
  expect(more.textContent).toContain('Show 2 more');
});

it('reveals the full list on "Show more" and collapses again on "Show less"', () => {
  const f = mount({ savedSearches: many });
  openSaved(f);
  (f.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement).click();
  f.detectChanges();
  expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(8);
  expect((f.nativeElement.querySelector('.savedsearch-more') as HTMLElement).textContent).toContain('Show less');
  (f.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement).click();
  f.detectChanges();
  expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
});

it('shows no "Show more" link at exactly six saved searches', () => {
  const f = mount({ savedSearches: many.slice(0, 6) });
  openSaved(f);
  expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
  expect(f.nativeElement.querySelector('.savedsearch-more')).toBeNull();
});

it('resets to the top six when the section is re-opened after expanding', () => {
  const f = mount({ savedSearches: many });
  openSaved(f);
  (f.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement).click();
  f.detectChanges();
  expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(8);
  openSaved(f); // close
  openSaved(f); // open again
  expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest --silent -t "Show more|Show less|exactly six|resets to the top six"`
Expected: FAIL — all eight rows render and `.savedsearch-more` does not exist.

- [ ] **Step 4: Add the visible slice, the hidden count, and the toggle**

In `sidebar.component.ts`, after `orderedSavedSearches` (Task 1):

```ts
/** Whether the "Show more" expansion is open. In memory only, reset when the
 *  section re-opens (#876) — the section chevron and this are separate states. */
readonly savedSearchListExpanded = signal(false);

/** The rows to render: the whole list when expanded, otherwise the top six. */
protected readonly visibleSavedSearches = computed(() => {
  const all = this.orderedSavedSearches();
  if (this.savedSearchListExpanded()) return all;
  return all.slice(0, SIDEBAR_SAVED_SEARCH_LIMIT);
});

/** How many ranked searches are not currently on screen. */
protected readonly hiddenSavedSearchCount = computed(
  () => this.orderedSavedSearches().length - this.visibleSavedSearches().length,
);
protected readonly hasHiddenSavedSearches = computed(() => this.hiddenSavedSearchCount() > 0);

toggleSavedSearchList(): void {
  this.savedSearchListExpanded.update((open) => !open);
}
```

Then extend `toggleSavedSearches` (from Task 1) so opening the section also collapses the more/less state:

```ts
toggleSavedSearches(): void {
  const opening = !this.savedSearchesExpanded();
  this.savedSearchesExpanded.set(opening);
  if (opening) {
    this.frozenSavedSearchOrder.set(rankedSavedSearchIds(this.savedSearches()));
    this.savedSearchListExpanded.set(false);
  }
}
```

- [ ] **Step 5: Render the visible slice and the link**

In `sidebar.component.html`, change the loop source from `orderedSavedSearches()` (Task 1) to `visibleSavedSearches()`:

```html
@for (saved of visibleSavedSearches(); track saved.id) {
```

Then, still inside `@if (savedSearchesExpanded()) { ... }` and after the `@for`, add the link:

```html
@if (savedSearchListExpanded()) {
  <button class="savedsearch-more" type="button" (click)="toggleSavedSearchList()">
    {{ 'reader.showLessSavedSearches' | transloco }}
  </button>
} @else if (hasHiddenSavedSearches()) {
  <button class="savedsearch-more" type="button" (click)="toggleSavedSearchList()">
    {{ 'reader.showMoreSavedSearches' | transloco: { count: hiddenSavedSearchCount() } }}
  </button>
}
```

- [ ] **Step 6: Style the link as an indented text link**

In `sidebar.component.scss`, after the `.savedsearch-item` rules (after line 266):

```scss
/* The "Show more/less" control: a subdued text link, indented to the same
   column as the saved-search terms (`--space-6`), reading as one of the rows
   without a nav row's box (#876). */
.savedsearch-more {
  display: block;
  width: 100%;
  padding: var(--space-1) var(--space-2) var(--space-1) var(--space-6);
  background: none;
  border: none;
  font: inherit;
  text-align: left;
  color: var(--text-muted);
  cursor: pointer;
}

.savedsearch-more:hover {
  color: var(--text-secondary);
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npx jest --silent -t "Show more|Show less|exactly six|resets to the top six"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/sidebar/ frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#876): cap saved searches at six with a show-more link"
```

---

### Task 3: Pin the active saved search when it falls outside the top six

When the currently selected saved search is not in the visible six, show it as one extra pinned row below the six and above the link, so the active highlight is always on screen. No duplicate when the list is expanded.

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `orderedSavedSearches`, `savedSearchListExpanded`, `SIDEBAR_SAVED_SEARCH_LIMIT` (Tasks 1–2); `activeSavedSearchId = input<number | null>(null)` (existing). The row highlight already uses `[class.active]="saved.id === activeSavedSearchId()"`.
- Produces: `visibleSavedSearches` updated to append the active row when it is outside the top six (collapsed only).

- [ ] **Step 1: Write the failing pin tests**

```ts
it('pins the active saved search as an extra row when it is outside the top six', () => {
  const f = mount({ savedSearches: many, activeSavedSearchId: 8 }); // id 8 has the lowest count -> last
  openSaved(f);
  const rows = f.nativeElement.querySelectorAll('.savedsearch-item');
  expect(rows.length).toBe(7); // top 6 + the pinned active
  const last = rows[rows.length - 1] as HTMLElement;
  expect(last.querySelector('.saved-term')?.textContent?.trim()).toBe('s8');
  expect(last.classList).toContain('active');
});

it('does not pin when the active search is already in the top six', () => {
  const f = mount({ savedSearches: many, activeSavedSearchId: 1 }); // id 1 has the highest count -> first
  openSaved(f);
  expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
});

it('shows no duplicate pinned row once the list is expanded', () => {
  const f = mount({ savedSearches: many, activeSavedSearchId: 8 });
  openSaved(f);
  (f.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement).click();
  f.detectChanges();
  const rows = Array.from(f.nativeElement.querySelectorAll('.savedsearch-item .saved-term')).map(
    (n) => (n as HTMLElement).textContent?.trim(),
  );
  expect(rows.length).toBe(8);
  expect(rows.filter((t) => t === 's8').length).toBe(1);
});

it('excludes the pinned active row from the hidden count', () => {
  const f = mount({ savedSearches: many, activeSavedSearchId: 8 });
  openSaved(f);
  // 8 total, 6 in the top + 1 pinned active on screen -> 1 hidden.
  expect((f.nativeElement.querySelector('.savedsearch-more') as HTMLElement).textContent).toContain('Show 1 more');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest --silent -t "pins the active|does not pin|duplicate pinned|excludes the pinned"`
Expected: FAIL — only the top six render; the active id-8 row is hidden.

- [ ] **Step 3: Add the pin to the visible slice**

In `sidebar.component.ts`, replace `visibleSavedSearches` (Task 2) with:

```ts
/** The rows to render: the whole list when expanded; otherwise the top six,
 *  plus the active search pinned as an extra row when it is not among them,
 *  so the current selection is always visible (#876). */
protected readonly visibleSavedSearches = computed(() => {
  const all = this.orderedSavedSearches();
  if (this.savedSearchListExpanded()) return all;
  const top = all.slice(0, SIDEBAR_SAVED_SEARCH_LIMIT);
  const activeId = this.activeSavedSearchId();
  if (activeId === null || top.some((row) => row.id === activeId)) return top;
  const active = all.find((row) => row.id === activeId);
  return active ? [...top, active] : top;
});
```

`hiddenSavedSearchCount` (Task 2) already subtracts `visibleSavedSearches().length`, so the pinned row is counted as on-screen — no change needed.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npx jest --silent -t "pins the active|does not pin|duplicate pinned|excludes the pinned"`
Expected: PASS.

- [ ] **Step 5: Run the full sidebar spec**

Run: `docker compose exec -T frontend npx jest --silent sidebar.component`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/sidebar/sidebar.component.ts frontend/src/app/reader/sidebar/sidebar.component.spec.ts
git commit -m "feat(#876): pin the active saved search when it falls outside the top six"
```

---

### Task 4: Verify, gate, and open the PR

Run the CI gate, sanity-check the render in the browser, and open the pull request.

**Files:** none (verification only).

- [ ] **Step 1: Run the frontend CI gate**

Run (from `frontend/`): `npm run check`
Expected: ESLint, Prettier, Stylelint, and Jest all pass. Fix any Prettier (100-col) or Stylelint (token) findings before proceeding. Note: the native run skips the typecheck the Docker gate applies, so also run `docker compose exec -T frontend npm test` once to confirm the container leg is green.

- [ ] **Step 2: Visually confirm the render**

Start the dev server and check the sidebar: create seven or more saved searches, confirm the top six show ranked unread-first, the "Show N more" link reveals the rest, "Show less" collapses back, a fully-read search drops its badge but keeps its slot until re-open, and selecting a low-ranked search pins it into view.

```bash
cd frontend && npm start
```

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feature/876-saved-search-sidebar-ranking
gh pr create --repo larspohlmann/simple-feed-reader --base develop \
  --title "Rank and cap saved searches in the sidebar (#876)" \
  --body "Closes #876"
```

- [ ] **Step 4: Confirm CI is green on the PR**

Run: `gh pr checks --repo larspohlmann/simple-feed-reader`
Expected: all required checks pass. If red, read the failing job before changing code — a red build here can be an upstream phptramp change, but this frontend-only branch should not touch that gate.

---

## Self-Review

**Spec coverage:**
- Sort unread-first, count desc, id tiebreaker → Task 1 (`rankedSavedSearchIds`).
- Freeze while open; recompute on open / structural change; not on read/poll/nav → Task 1 (`refreezeOnStructuralChange` keyed on id set; `toggleSavedSearches` re-freezes on open).
- Full-reload recompute → component init seeds `frozenSavedSearchOrder` via the effect on first population.
- Cap at six; "Show N more" only when > 6; inline expand; "Show less"; reset on re-open → Task 2.
- In-memory expand state → `savedSearchListExpanded` signal, no storage.
- Section stays collapsed by default → `savedSearchesExpanded = signal(false)` unchanged.
- 0 = no badge; row read to 0 keeps its slot → existing `@if (saved.unreadCount > 0)` badge + frozen order (Task 1 freeze test).
- 0 saved searches: section absent → existing `@if (savedSearches().length)` untouched.
- 1–6: all shown, no link → Task 2 "exactly six" test and the `hasHiddenSavedSearches` guard.
- Active pin outside top six; no duplicate when expanded; excluded from hidden count → Task 3.
- Indented text link styling with tokens → Task 2 Step 6.
- Frontend-only → no backend/entity/store/model files in the plan.

**Placeholder scan:** none — every code and test step carries real content.

**Type consistency:** `rankedSavedSearchIds`, `sameIds`, `frozenSavedSearchOrder`, `orderedSavedSearches`, `visibleSavedSearches`, `hiddenSavedSearchCount`, `hasHiddenSavedSearches`, `savedSearchListExpanded`, `toggleSavedSearchList`, `SIDEBAR_SAVED_SEARCH_LIMIT` are named identically across Tasks 1–3. `toggleSavedSearches` is defined once in Task 1 and extended in Task 2 (the executor replaces the whole method with the Task 2 version). The i18n keys `reader.showMoreSavedSearches` / `reader.showLessSavedSearches` match between the JSON (Task 2 Step 1) and the template (Task 2 Step 5).
