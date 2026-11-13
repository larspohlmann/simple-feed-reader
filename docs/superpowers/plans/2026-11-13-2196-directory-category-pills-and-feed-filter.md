# Directory category pills on phones and the narrowed feed list (#2196) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** With a category filter on, a directory row offers only the site's feeds in the chosen categories. Phone rows show the category pills.

**Architecture:** A pure helper `shownFeeds(row, mode, categories)` in `feed-directory-rows.ts`. Both the row component (count, single-feed shortcut, subscribed count) and `FeedDirectorySubscriber` (popover / action sheet) read through it. The pills keep reading `feedsIn`. The phone change is SCSS plus a new `FEED_DIRECTORY_ROW_HEIGHT.phone`.

**Tech Stack:** Angular 20 (standalone, signals), Jest (jsdom), SCSS + Stylelint.

**Spec:** `docs/superpowers/specs/2026-11-13-2196-directory-category-pills-and-feed-filter-design.md`

## Global Constraints

- Work in `frontend/src/app/settings/feed-directory/`. Branch `feature/2196-directory-category-pills-and-feed-filter`.
- Commit format `type(#2196): …`, with no attribution lines.
- Run Jest inside the container: `docker compose exec -T frontend npx jest <path>` from the repo root. The full gate is `docker compose exec -T frontend npm run check`.
- SCSS uses spacing tokens only, no hex colours and no `px` spacing literals (Stylelint).
- No comments unless a future reader would get the code wrong without them (CLAUDE.md).

## Review Focus

1. **The filter changes while the popover is open.** The popover keeps the feed list it opened with. Changing the filter means closing the popover first (backdrop click), so no live update is needed. No test needed.
2. **A chosen category the site lacks in this mode.** It can't happen: `matcher` only lists rows that have at least one chosen category, so `shownFeeds` never returns empty for a listed row. A test still pins it: categories `{Sport}` on a row without Sport returns `[]`. The row then shows "0 feeds" rather than crashing.
3. **Two chosen categories.** The feeds of both are kept, in the order the site lists them (the helper test pins this).
4. **Exactly one matching feed among several.** The row switches to a direct Subscribe button, and the subscriber opens the add-feed dialog directly instead of the popover (the row and subscriber tests pin this).
5. **Podcasts mode with a channel category chosen.** Mode narrowing comes first, so channel feeds never leak into the podcasts list (the helper test pins this).

---

### Task 1: Narrow a row's feeds to the chosen categories

**Files:**
- Modify: `feed-directory-rows.ts` (add `shownFeeds` next to `feedsIn`)
- Modify: `row/feed-directory-row.component.ts`, `feed-directory-section.component.html` (pass `[categories]="store.categories()"`)
- Modify: `feed-directory-subscriber.ts` (`open` uses `shownFeeds`)
- Test: `feed-directory-rows.spec.ts`, `row/feed-directory-row.component.spec.ts`, `feed-directory-subscriber.spec.ts`

**Interfaces:**
- Produces: `export function shownFeeds(row: FeedDirectoryRow, mode: FeedDirectoryMode, categories: ReadonlySet<string>): FeedDirectoryFeed[]`. It returns `feedsIn(row, mode)` when `categories` is empty, and otherwise only those feeds whose `category` is in `categories`, in their original order.
- Produces: `FeedDirectoryRowComponent.categories = input<ReadonlySet<string>>(new Set())`.

- [ ] **Step 1: Write the failing helper tests** in `feed-directory-rows.spec.ts`, inside `describe('feed directory rows')`. Build one row with `prepareRows` or a literal (whichever the file's fixtures make shorter) holding channel feeds in `World`, `Sport` and `World`, plus a podcast in `Sport`.
  - `it('shows every feed of the mode while no category is chosen')`: `shownFeeds(row, 'channels', new Set())` equals `feedsIn(row, 'channels')`.
  - `it('shows only the feeds of the chosen categories, in the site's order')`: with `{World}` the result is the two World channel URLs in order. With `{World, Sport}` it's all three channels in their original order.
  - `it('keeps the mode before the categories')`: `shownFeeds(row, 'podcasts', new Set(['World']))` is `[]`, and `shownFeeds(row, 'podcasts', new Set(['Sport']))` is the one podcast URL.

- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory-rows.spec.ts`. Expected: FAIL, because `shownFeeds` isn't exported.

- [ ] **Step 3: Implement `shownFeeds`** in `feed-directory-rows.ts`, directly after `feedsIn`.

- [ ] **Step 4: Write the failing row tests** in `row/feed-directory-row.component.spec.ts`. Use the existing `ROW`, which has channels `World / International` (tagesschau index) and `Science & Technology` (klima), plus a podcast. Set `categories` with `fixture.componentRef.setInput('categories', new Set([...]))` and then `fixture.detectChanges()`.
  - `it('counts only the feeds of the chosen categories on the list button')`: add a third channel feed in `World / International` with `subscribed: true`. With categories `{World / International}`, `[data-test="feeds"]` reads `2 feeds` and the subscribed part reads `1 subscribed`. Without categories it reads `3 feeds`.
  - `it('offers Subscribe straight away when one feed of the chosen categories is left')`: on `ROW` with `{Science & Technology}`, `[data-test="subscribe"]` exists and `[data-test="feeds"]` doesn't.
  - `it('keeps every category of the mode as a pill while the filter narrows the feeds')`: with `{Science & Technology}`, the `.chip` texts are still `['World / International', 'Science & Technology']`.

- [ ] **Step 5: Run** `docker compose exec -T frontend npx jest src/app/settings/feed-directory/row`. Expected: FAIL on the first two tests.

- [ ] **Step 6: Implement the row change.** Add the `categories` input. `feeds()` becomes `shownFeeds(this.row(), this.mode(), this.categories())`. `categories()`, the pill list, is computed from `feedsIn(this.row(), this.mode())`. Rename that computed to `pillCategories` so it doesn't clash with the input, and update the template's `@for`. Pass `[categories]="store.categories()"` from `feed-directory-section.component.html`.

- [ ] **Step 7: Write the failing subscriber tests** in `feed-directory-subscriber.spec.ts`. Add `categories = signal<ReadonlySet<string>>(new Set())` beside `mode`, reset it in `beforeEach`, and put it into the `FeedDirectoryStore` stub.
  - `it('opens the add-feed dialog directly when the chosen category leaves one feed')`: set `categories` to `{World}`, call `subscriber.open({ row: SEVERAL, anchor })`, and expect `dialogOpen` to be called with `data: { url: 'https://www.spiegel.de/ausland/index.rss' }`, `popover()` to be null and `sheetOpen` not to be called.
  - In `describe('several feeds on a fine pointer')`: `it('lists only the feeds of the chosen categories')`. Set `categories` to `{Culture}` and call `openPopover()`. The popover's `.path` texts are `['/kultur/index.rss', '/kultur/kino/index.rss']`.
  - In `describe('several feeds on a coarse pointer')`: `it('offers only the feeds of the chosen categories on the sheet')`. Set `categories` to `{Culture}`. Expect `sheetOpen` with title `'spiegel.de · 2 feeds'` and only the `Culture · 2` note and its two actions.

- [ ] **Step 8: Run** `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory-subscriber.spec.ts`. Expected: FAIL on the three new tests.

- [ ] **Step 9: Implement:** `open` uses `shownFeeds(request.row, this.store.mode(), this.store.categories())` in place of `feedsIn`. Drop the now-unused `feedsIn` import.

- [ ] **Step 10: Run** `docker compose exec -T frontend npx jest src/app/settings/feed-directory`. Expected: all PASS.

- [ ] **Step 11: Commit**

```bash
git add frontend/src/app/settings/feed-directory
git commit -m "feat(#2196): a category filter narrows a directory site's feed list"
```

### Task 2: Category pills on phones

**Files:**
- Modify: `row/feed-directory-row.component.scss` (the `@media (width < bp.$bp-sm)` block)
- Modify: `row/feed-directory-row.component.ts` (`FEED_DIRECTORY_ROW_HEIGHT.phone: 114` → `140`)

**Interfaces:**
- Consumes: nothing from Task 1.

The 140 px is the row's natural height with the pills shown, measured on :4200 at 375 px wide with a two-line summary. The section spec's row-height test reads the constant, so it needs no edit.

- [ ] **Step 1: SCSS.** In the phone block, split `.chips, .subscribed-part { display: none; }`: keep `.subscribed-part` hidden, and give `.chips` `grid-column: 1 / -1;` so it spans the row under the summary.
- [ ] **Step 2: Set** `FEED_DIRECTORY_ROW_HEIGHT.phone` to `140`.
- [ ] **Step 3: Live check** on `http://localhost:4200/settings/feed-directory` at 375×812. Every row shows its pills inside the row (no clipping at the bottom border, no gap above the next row), and tapping a pill filters. Check a row without a summary as well: its pills sit higher, with spare space below, which is acceptable at a fixed row height.
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check`. Expected: exit 0.
- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/feed-directory/row
git commit -m "feat(#2196): show a directory site's category pills on phones"
```
