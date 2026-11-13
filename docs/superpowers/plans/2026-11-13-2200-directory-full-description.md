# Directory rows grow with their description (#2200) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Directory rows show their full description at their natural height. The list renders in batches of 100, adding a batch as you near the bottom.

**Architecture:** `FeedDirectoryStore` owns the batch window: a `limit` that resets on any change to the result set, but not on subscription marks. It exposes `shownRows`, `hasMore` and `showMore()`. The section drops the CDK virtual scroll, renders `shownRows()` with `@for`, and calls `showMore()` from an IntersectionObserver on a sentinel. The row loses its fixed height and its line clamp.

**Tech Stack:** Angular 20 signals (`linkedSignal`), IntersectionObserver, Jest (jsdom), SCSS.

**Spec:** `docs/superpowers/specs/2026-11-13-2200-directory-full-description-design.md`

## Global Constraints

- Work in `frontend/src/app/settings/feed-directory/`. Branch `feature/2200-directory-full-description`.
- Batch size **100**.
- Commit format `type(#2200): …`, with no attribution lines.
- Jest runs in the container: `docker compose exec -T frontend npx jest <path>` from the repo root. The gate is `docker compose exec -T frontend npm run check`.
- Run Prettier on single files only, never on the whole directory: `feed-directory-url-cases.json` must stay as it is.
- No comments unless a future reader would get the code wrong without them (CLAUDE.md).

## Review Focus

1. **Subscribing from row 300.** A new `subscriptions()` value re-marks every row and gives `store.rows()` a new value. The limit must stay put. Task 1 tests this.
2. **The sentinel stays in view after a batch lands** (short rows, a tall screen). The observer only fires on a change in intersection, so a sentinel that never leaves the margin would stall the list. After each `showMore()`, the section re-observes the sentinel, which makes IntersectionObserver report its current state again. Task 2 tests this.
3. **A filter that leaves fewer than 100 rows.** `hasMore()` is false, so there's no sentinel and nothing loads. Task 1 tests this.
4. **The scope changes and the directory loads again** with the same filters. The limit resets to 100. Task 1 tests this.
5. **The page is destroyed while the observer is live.** The observer is disconnected. Task 2 tests this.

---

### Task 1: The store's batch window

**Files:**
- Modify: `feed-directory.store.ts`
- Test: `feed-directory.store.spec.ts`

**Interfaces:**
- Produces: `export const FEED_DIRECTORY_BATCH = 100;` in `feed-directory.store.ts`.
- Produces on `FeedDirectoryStore`: `readonly shownRows: Signal<FeedDirectoryRow[]>` (the first `limit` rows of `rows()`), `readonly hasMore: Signal<boolean>`, `showMore(): void` (adds `FEED_DIRECTORY_BATCH` to the limit).
- Reset rule: `limit` is a `linkedSignal` whose source is a computed over `loaded()`, `criteria()`, `country()` and `sort()`, and **not** over `marked()`/`lookup()`. Its computation returns `FEED_DIRECTORY_BATCH`.

- [ ] **Step 1: Write the failing tests** in `feed-directory.store.spec.ts`. Add a fixture `MANY: FeedDirectoryCountryFile`, a country `'DE'` with 250 domains generated as `[`site${index}.de`, index + 1, 1000, [0], [[0, 0, [`https://site${index}.de/feed`]]], null]` (categories `['News']`, languages `['de']`). Load it by setting the `country` mock to return `MANY` and the scope to `{ countries: ['DE'], languages: ['de'] }`. In `describe('batches')`:
  - `it('shows the first batch and adds one on demand')`: `shownRows().length` is 100 and `hasMore()` is true. After `showMore()` it's 200, and after a second `showMore()` it's 250 with `hasMore()` false.
  - `it('starts over at the first batch when a filter or the sort changes')`: `showMore()`, then `store.sort.set('az')` makes `shownRows().length` 100. Same after `showMore()` then `store.query.set('site1')`. That query matches fewer than 100 rows, so `hasMore()` is false.
  - `it('keeps the batches shown when only the subscriptions change')`: `showMore()`, then `subscriptions.set([{ id: 9, feedUrl: 'https://site150.de/feed' }])`. `shownRows().length` stays 200, and the row for `site150.de` is marked (`feeds[0].subscribed` is true).
  - `it('starts over at the first batch when the directory loads again')`: `showMore()`, then `store.load()` gives `shownRows().length` 100.

- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory.store.spec.ts`. Expected: FAIL, because `shownRows` is undefined.

- [ ] **Step 3: Implement** `FEED_DIRECTORY_BATCH`, a private `resultSet = computed(() => ({ loaded: this.loaded(), criteria: this.criteria(), country: this.country(), sort: this.sort() }))`, a private `limit = linkedSignal({ source: this.resultSet, computation: () => FEED_DIRECTORY_BATCH })`, then `shownRows`, `hasMore` and `showMore()`.

- [ ] **Step 4: Run** the same command. Expected: PASS, all tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/feed-directory/feed-directory.store.ts frontend/src/app/settings/feed-directory/feed-directory.store.spec.ts
git commit -m "feat(#2200): the directory store shows its rows a batch at a time"
```

### Task 2: Natural-height rows, rendered in batches

**Files:**
- Modify: `feed-directory-section.component.ts`, `.html` and `.scss`
- Modify: `row/feed-directory-row.component.ts`, `.scss`
- Test: `feed-directory-section.component.spec.ts`

**Interfaces:**
- Consumes: `store.shownRows()`, `store.hasMore()`, `store.showMore()` from Task 1.
- Removes: `FEED_DIRECTORY_ROW_HEIGHT`, `rowHeight()`, `byHost`, the four CDK scrolling imports, `--row-h` and the `.viewport` rule. The separator rule becomes `.list app-feed-directory-row + app-feed-directory-row`.

- [ ] **Step 1: Write the failing section tests.** Use `stubIntersectionObserver` / `restoreIntersectionObserver` from `src/testing/intersection-observer-testing.ts`: call the stub before `render`, and restore in `afterEach`. Delete the test `'scrolls by one row height, and sizes the rows by it, for each breakpoint'` and its `CdkFixedSizeVirtualScroll`, `By` and `FEED_DIRECTORY_ROW_HEIGHT` imports if nothing else uses them.
  - `it('adds the next batch when the end of the list comes into view')`: render with a scope whose country file holds 150 domains. Reuse the store spec's generator shape: build `MANY` locally and have `country` return it. `hosts().length` is 100, and `[data-test="more"]` exists and is observed. Then `report(sentinel, true)` and `settle()`, after which `hosts().length` is 150 and `[data-test="more"]` is gone.
  - `it('watches the end of the list again after each batch, so a sentinel left in view keeps loading')`: render 250 domains, `report(sentinel, true)`, `settle()`. `observed` holds the sentinel a second time.
  - `it('stops watching when the page goes away')`: after render, `fixture.destroy()` and `disconnect` has been called.

- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory-section.component.spec.ts`. Expected: the new tests FAIL (100 rows render, but no `[data-test="more"]`).

- [ ] **Step 3: Implement the section.**
  - The template replaces the viewport with `<div class="list">@for (row of store.shownRows(); track row.host) { <app-feed-directory-row …same bindings… /> }</div>`. Inside the same `@default` branch, add `@if (store.hasMore()) { <div class="more" data-test="more" #more></div> }`.
  - In the component, `more = viewChild<ElementRef<HTMLElement>>('more')`, and an `effect` that reads `more()` and `store.shownRows()` (so it re-runs after each batch). Each run disconnects the previous observer and, when the sentinel exists, observes it with `new IntersectionObserver(callback, { rootMargin: prefetchMargin(window.innerHeight) })`. `prefetchMargin` comes from `../../reader/list/paging`. The callback calls `store.showMore()` when an entry is intersecting. Disconnect in `ngOnDestroy` (or with `DestroyRef.onDestroy`).

- [ ] **Step 4: Row and section styles.** In `row/feed-directory-row.component.scss`, remove `height: var(--row-h)` and `overflow: hidden` from `:host`, and remove `display: -webkit-box`, `overflow: hidden`, `-webkit-line-clamp` and `-webkit-box-orient` from `.summary`. Remove `FEED_DIRECTORY_ROW_HEIGHT` from the row's `.ts`. In the section SCSS, rename `.viewport` to `.list`.

- [ ] **Step 5: Run** `docker compose exec -T frontend npx jest src/app/settings/feed-directory`. Expected: all PASS.

- [ ] **Step 6: Live check** on `http://localhost:4200/settings/feed-directory`, at 375×812 and on desktop. Long descriptions (nytimes.com) show in full with no "…". Rows don't overlap. Scrolling to the end of the list keeps adding rows past 100 (check `document.querySelectorAll('app-feed-directory-row').length` before and after). Changing the sort goes back to 100. Subscribing from a row past 100 keeps the list where it is.

- [ ] **Step 7: Run** `docker compose exec -T frontend npm run check`. Expected: exit 0.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/settings/feed-directory
git commit -m "feat(#2200): directory rows grow with their description and render in batches"
```
