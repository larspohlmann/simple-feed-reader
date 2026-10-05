# List progress rail (#1392) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a phone, the list view shows the article view's green progress rail on its right edge. The list is paged, so its full length is estimated.

**Architecture:** The article's rail markup and colours move into a shared `ProgressRailComponent`. Its fill height is a CSS custom property, `--rail-fill`, which is a percentage. The article binds that property from its existing signal. The list writes it straight onto the element from its outside-zone scroll handler, so scrolling never triggers a change-detection tick (#501). A pure function estimates where the list would end if every entry were loaded: the loaded rows' height plus the unloaded entries times the average height per shown entry. The existing `readingProgress()` then turns the scroll position into a fraction.

**Tech Stack:** Angular 20 (standalone, signals), Jest (jsdom), SCSS.

**Spec:** the design approved in chat on 2026-10-05, recorded in issue #1392. It decides the following:
- *Estimated end* = loaded rows' bottom + (total − loaded entries) × (loaded rows' height ÷ shown entries).
- Once no page remains (`hasMore` false), the real end is used and nothing is estimated.
- The total is the list header's count (`titleCount`: unread with the unread filter on, all items with it off). It is snapshotted for the life of a list, so reading doesn't shrink it.
- A search has no total, so its rail stays hidden while more pages remain. It appears once all results are loaded.
- The rail shows only below the wide breakpoint (`!LayoutService.isWide()`), and only while the list overflows its scroller.
- It looks the same as the article rail: same width and colours, right edge, full height.

## Global Constraints

- Component styles live in a sibling `.scss` file; no hex colours, no ad-hoc `px` spacing (Stylelint).
- Prettier 100 columns. The gate is `docker compose exec -T frontend npm run check` (ESLint, Prettier, Stylelint and Jest), and it must run inside the Docker frontend container.
- Never run two Jest processes in the container at once; concurrent runs exhaust its memory.
- Comments: default to none, one line, and only where a reader would get the code wrong without one.
- The list's scroll handler stays outside Angular's zone. Per scroll event the rail costs one DOM write and sets no signal unless the value changes.
- Keep the `.progress-rail` class and the `<i>` fill child on the article's rail: `e2e/article-reading-progress.spec.ts` selects them.

---

### Task 1: Shared `ProgressRailComponent`, adopted by the article

**Files:**
- Create: `frontend/src/app/shared/progress-rail/progress-rail.component.ts`
- Create: `frontend/src/app/shared/progress-rail/progress-rail.component.scss`
- Create: `frontend/src/app/shared/progress-rail/progress-rail.component.spec.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.html:96-102`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.scss:96-124`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts` (add the component to `imports`)

**Interfaces:**
- Produces: `<app-progress-rail>`, selector `app-progress-rail`, class `ProgressRailComponent`. It takes no inputs. It reads `--rail-fill`, a number from 0 to 100, from its host; when the property is unset the fill is 0.

- [ ] **Step 1: Write the failing spec**

```ts
import { TestBed } from '@angular/core/testing';
import { ProgressRailComponent } from './progress-rail.component';

describe('ProgressRailComponent', () => {
  it('is decorative and renders the fill the rail styles size', () => {
    const fixture = TestBed.createComponent(ProgressRailComponent);
    fixture.detectChanges();
    const host: HTMLElement = fixture.nativeElement;
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.querySelector('i')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker compose exec -T frontend npx jest src/app/shared/progress-rail`
Expected: FAIL, because the module `./progress-rail.component` cannot be found.

- [ ] **Step 3: Implement**

`progress-rail.component.ts`:

```ts
import { ChangeDetectionStrategy, Component } from '@angular/core';

/** The phone's length cue (#238): a track on the right edge whose fill is the
 *  host's `--rail-fill` percentage. The caller positions the host. */
@Component({
  selector: 'app-progress-rail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { 'aria-hidden': 'true' },
  template: '<i></i>',
  styleUrl: './progress-rail.component.scss',
})
export class ProgressRailComponent {}
```

`progress-rail.component.scss`:

```scss
:host {
  display: block;
  width: var(--space-1);
  background: var(--border);
}

/* Untransitioned: the fill reports the scroll position and has to track it. */
i {
  display: block;
  width: 100%;
  height: calc(var(--rail-fill, 0) * 1%);
  background: var(--accent);
}
```

In `reader-view.component.html`, replace the rail block (keep its leading comment) with:

```html
          <app-progress-rail class="progress-rail" [style.--rail-fill]="scope.progressPercent()" />
```

In `reader-view.component.scss`:
- Delete `width` and `background` from `.progress-rail`; they now live in the component.
- Delete the whole `.progress-rail i` rule and its comment.
- Keep `position: sticky`, `top`, `z-index`, `margin-left: auto`, `height: 100dvh` and `margin-bottom: -100dvh`, with their comments.

Add `ProgressRailComponent` to `ReaderViewComponent`'s `imports`.

- [ ] **Step 4: Run the specs**

Run: `docker compose exec -T frontend npx jest src/app/shared/progress-rail src/app/reader/article/reader-view`
Expected: PASS. The existing check that `.progress-rail` is absent at `reader-view.component.spec.ts:503` still holds.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/progress-rail frontend/src/app/reader/article/reader-view
git commit -m "refactor(#1392): the article's progress rail becomes a shared component"
```

---

### Task 2: The list-length estimate

**Files:**
- Create: `frontend/src/app/reader/list/entry-list/list-length.ts`
- Create: `frontend/src/app/reader/list/entry-list/list-length.spec.ts`

**Interfaces:**
- Produces:

```ts
export interface LoadedRows {
  /** The first row's top, in scroll coordinates. */
  readonly rowsTop: number;
  /** The last row's bottom, in scroll coordinates. */
  readonly rowsBottom: number;
  /** Entries rendered between those edges (hidden rows excluded). */
  readonly shownEntries: number;
  /** Entries fetched so far. */
  readonly loadedEntries: number;
  /** Entries in the whole list, or null when nothing says. */
  readonly totalEntries: number | null;
  readonly hasMore: boolean;
}
export function estimatedListBottom(rows: LoadedRows): number | null;
```

- [ ] **Step 1: Write the failing spec**

```ts
import { estimatedListBottom, LoadedRows } from './list-length';

const rows = (overrides: Partial<LoadedRows>): LoadedRows => ({
  rowsTop: 100,
  rowsBottom: 1100,
  shownEntries: 10,
  loadedEntries: 10,
  totalEntries: 30,
  hasMore: true,
  ...overrides,
});

describe('estimatedListBottom', () => {
  it('extends the loaded rows by the unloaded entries at the average height', () => {
    expect(estimatedListBottom(rows({}))).toBe(1100 + 20 * 100);
  });

  it('is the real bottom once no page remains, whatever the total says', () => {
    expect(estimatedListBottom(rows({ hasMore: false, totalEntries: 500 }))).toBe(1100);
    expect(estimatedListBottom(rows({ hasMore: false, totalEntries: null }))).toBe(1100);
  });

  it('is unknown while more pages remain and no total exists', () => {
    expect(estimatedListBottom(rows({ totalEntries: null }))).toBeNull();
  });

  it('averages over the shown entries but counts the remainder from the loaded ones', () => {
    // 5 hidden above (mark-above-read): 10 loaded, 5 shown over the same 1000px.
    expect(estimatedListBottom(rows({ shownEntries: 5 }))).toBe(1100 + 20 * 200);
  });

  it('never estimates fewer rows than are loaded', () => {
    expect(estimatedListBottom(rows({ totalEntries: 4 }))).toBe(1100);
  });

  it('is unknown with nothing shown to average over', () => {
    expect(estimatedListBottom(rows({ shownEntries: 0 }))).toBeNull();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/entry-list/list-length`
Expected: FAIL, because the module cannot be found.

- [ ] **Step 3: Implement** `list-length.ts`. Write the interface exactly as listed under Interfaces, followed by:

```ts
/** Where a paged list would end with every entry loaded, in scroll coordinates. */
export function estimatedListBottom(rows: LoadedRows): number | null {
  if (!rows.hasMore) return rows.rowsBottom;
  if (rows.totalEntries === null || rows.shownEntries === 0) return null;
  const unloaded = Math.max(0, rows.totalEntries - rows.loadedEntries);
  const perEntry = (rows.rowsBottom - rows.rowsTop) / rows.shownEntries;
  return rows.rowsBottom + unloaded * perEntry;
}
```

- [ ] **Step 4: Run the spec**

Run the same command as in Step 2. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list/entry-list/list-length.ts frontend/src/app/reader/list/entry-list/list-length.spec.ts
git commit -m "feat(#1392): estimate where a paged list ends"
```

---

### Task 3: The rail on the list

**Files:**
- Create: `frontend/src/app/reader/list/entry-list/list-progress-rail.ts`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.html`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.scss`
- Test: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes:
  - `ProgressRailComponent` (Task 1).
  - `estimatedListBottom` and `LoadedRows` (Task 2).
  - `readingProgress(scrollTop, viewportHeight, contentBottom)` and `articleOverflowsViewport(contentBottom, viewportHeight)` from `reader/article/reading/reading-progress.ts`.
- Produces: `ListProgressRail`, which offers `overflows: Signal<boolean>` and `paint(): void`.

**How it behaves (the binding rules):**
- Rows are measured by the `.row-slot` wrappers. Both layouts wrap every list row or magazine block in one, so a magazine group card counts as one box that holds several entries.
- The scroll coordinate origin is `scroller.getBoundingClientRect().top - scroller.scrollTop`.
  - `rowsTop` is the first `.row-slot`'s top minus the origin.
  - `rowsBottom` is the last `.row-slot`'s bottom minus the origin.
- `shownEntries` is `content.visibleEntryCount()`, and `loadedEntries` is `entries().length`.
- The total for the list:
  - It comes from an input-derived `computed`. It is `null` for `selection().kind === 'search'` or when `titleCount().value === 0`; otherwise it is `titleCount().value`.
  - `ListProgressRail` keeps the highest total it has seen since the last load began. When `loading()` turns true it resets to 0.
  - Reading lowers the live unread count while the read rows stay on screen. The kept highest value holds the estimate steady, and still lets a later, larger count raise it.
  - A highest value of 0 means unknown (`null`).
- `paint()`:
  1. Measures and calls `estimatedListBottom`.
  2. Sets `overflows` to `bottom !== null && articleOverflowsViewport(bottom, scroller.clientHeight)`. A signal set to its current value schedules nothing.
  3. If the list overflows and the rail element exists, writes `rail.style.setProperty('--rail-fill', String(readingProgress(scroller.scrollTop, scroller.clientHeight, bottom) * 100))`.
- When `paint()` runs:
  - From the scroll handler, which stays outside the zone.
  - After render, whenever `entries()`, `content.visibleEntryCount()`, `hasMore()`, the total or `overflows()` change: an `effect` that reads them and calls `afterNextRender(() => this.paint(), { injector })`. The injector comes from `inject(Injector)` in the class's field initialiser.
  - The `overflows()` dependency is what paints the rail on the frame it first renders.
- The template renders the rail only when `!screen.isWide() && progressRail.overflows()`.

- [ ] **Step 1: Write the failing specs** in `entry-list.component.spec.ts`.
  - Follow the file's existing harness for inputs, `LayoutService` and narrow/wide set-up; read how its current scroll and layout tests do it.
  - jsdom has no layout, so stub `getBoundingClientRect`, `clientHeight` and `scrollTop` on the scroller and its `.row-slot` elements. Dispatch a `scroll` event on `.rows` to drive the handler, then call `fixture.detectChanges()`.

  Cases:
  1. Narrow layout with `hasMore` true and `titleCount` `{ value: 30, counts: 'items' }`:
     - Given 10 entries whose rows span 100→1100 and a scroller 500 tall, the rail (`app-progress-rail.list-rail`) renders.
     - After scrolling to `scrollTop` 1300, its `--rail-fill` is `'50'`. The estimated bottom is 3100, so 1300 / (3100 − 500) = 0.5.
  2. Same set-up on a wide layout: no rail.
  3. A search selection with `hasMore` true: no rail. With `hasMore` false and rows that overflow, the rail renders.
  4. Rows that fit the scroller (span 100→400, height 500, `hasMore` false): no rail.
  5. The total holds:
     - After the first paint with `titleCount` 30, lower `titleCount` to 25 (rows read), with no reload.
     - At the same `scrollTop` 1300, `--rail-fill` is still `'50'`.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/entry-list/entry-list.component`
Expected: the five new cases fail, because no `app-progress-rail` element exists.

- [ ] **Step 3: Implement**

`list-progress-rail.ts`:

```ts
import { Injector, Signal, afterNextRender, effect, inject, signal } from '@angular/core';
import { EntryDto } from '../../models';
import { articleOverflowsViewport, readingProgress } from '../../article/reading/reading-progress';
import { estimatedListBottom } from './list-length';

export interface ListProgressRailOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly rail: () => HTMLElement | undefined;
  readonly entries: Signal<EntryDto[]>;
  readonly shownEntries: Signal<number>;
  readonly hasMore: Signal<boolean>;
  /** The list's entry count from its header, or null when it has none. */
  readonly total: Signal<number | null>;
  readonly loading: Signal<boolean>;
}

/** The list's length cue on a phone (#1392). Painted straight onto the rail
 *  from the outside-zone scroll handler, so scrolling costs no change detection.
 *  Built in a field initializer, so its effects are created there. */
export class ListProgressRail {
  private readonly injector = inject(Injector);
  readonly overflows = signal(false);
  private highestTotal = 0;

  private readonly _resetOnLoad = effect(() => {
    if (this.options.loading()) this.highestTotal = 0;
  });

  private readonly _paintAfterRender = effect(() => {
    this.options.entries();
    this.options.shownEntries();
    this.options.hasMore();
    this.options.total();
    this.overflows();
    afterNextRender(() => this.paint(), { injector: this.injector });
  });

  constructor(private readonly options: ListProgressRailOptions) {}

  readonly paint = (): void => {
    const scroller = this.options.scroller();
    if (!scroller) return;
    const bottom = this.estimatedBottom(scroller);
    const overflows = bottom !== null && articleOverflowsViewport(bottom, scroller.clientHeight);
    this.overflows.set(overflows);
    const rail = this.options.rail();
    if (!overflows || !rail) return;
    const fraction = readingProgress(scroller.scrollTop, scroller.clientHeight, bottom);
    rail.style.setProperty('--rail-fill', String(fraction * 100));
  };

  private estimatedBottom(scroller: HTMLElement): number | null {
    const slots = scroller.querySelectorAll<HTMLElement>('.row-slot');
    if (slots.length === 0) return null;
    const origin = scroller.getBoundingClientRect().top - scroller.scrollTop;
    return estimatedListBottom({
      rowsTop: slots[0].getBoundingClientRect().top - origin,
      rowsBottom: slots[slots.length - 1].getBoundingClientRect().bottom - origin,
      shownEntries: this.options.shownEntries(),
      loadedEntries: this.options.entries().length,
      totalEntries: this.knownTotal(),
      hasMore: this.options.hasMore(),
    });
  }

  private knownTotal(): number | null {
    this.highestTotal = Math.max(this.highestTotal, this.options.total() ?? 0);
    return this.highestTotal > 0 ? this.highestTotal : null;
  }
}
```

`paint()` reads signals outside a reactive context, which is fine because it is called from the handler and from `afterNextRender`. The `_paintAfterRender` effect alone tracks the dependencies. ESLint may object to a `readonly paint` arrow next to methods; if it does, follow whatever pattern `ListScrollState.onScroll` uses.

In `entry-list.component.ts`:
- Import `ProgressRailComponent` and add it to `imports`.
- Add `private readonly railRef = viewChild(ProgressRailComponent, { read: ElementRef });`.
- Expose the layout for the template, either `protected readonly screen` (it is currently `private`) or a `computed` `showRail`.
- Prefer:

```ts
  private readonly listTotal = computed(() => {
    const count = this.titleCount().value;
    return this.selection().kind === 'search' || count === 0 ? null : count;
  });

  readonly progressRail = new ListProgressRail({
    scroller: this.scroller,
    rail: () => this.railRef()?.nativeElement,
    entries: this.entries,
    shownEntries: this.content.visibleEntryCount,
    hasMore: this.hasMore,
    total: this.listTotal,
    loading: this.loading,
  });

  readonly showRail = computed(() => !this.screen.isWide() && this.progressRail.overflows());

  readonly onRowsScroll = (event: Event): void => {
    this.scrolling.onScroll(event);
    this.progressRail.paint();
  };
```

Declare `progressRail` after `content`, because field initialisers run in order. Check that `content.visibleEntryCount` is a `Signal<number>`; if it is not, wrap it in a `computed`.

In `entry-list.component.html`:
- Change both `[appScrollOutsideZone]="scrolling.onScroll"` to `[appScrollOutsideZone]="onRowsScroll"`.
- Add `[class.has-rail]="showRail()"` to both `.rows` divs.
- Add, as a host-level sibling right after the two `.rows` branches (outside the scroller, so no sticky trick and no magazine-column sizing applies):

```html
@if (showRail()) {
  <app-progress-rail class="list-rail" />
}
```

In `entry-list.component.scss`:

```scss
/* The rail is the phone's scrollbar (#238), as in the article. Under the list
   header (z-index 3), over the rows. */
.list-rail {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 2;
}

@media (width < bp.$bp-lg) {
  .rows.has-rail {
    scrollbar-width: none;
  }
}
```

- [ ] **Step 4: Run the list specs, then the whole gate**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/entry-list`
Expected: PASS.

Run: `docker compose exec -T frontend npm run check`
Expected: PASS (ESLint, Prettier, Stylelint and Jest).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list/entry-list
git commit -m "feat(#1392): the list shows the progress rail on a phone"
```

---

### Task 4: Check it on a real render (controller, not a subagent)

- [ ] Serve a production build and open the list at the mobile viewport, both in a feed with many unread entries and in magazine layout. The serve command:

```bash
docker compose run -d --rm -p 4300:4300 --name sfr-prodserve frontend node_modules/.bin/ng serve --configuration production --host 0.0.0.0 --port 4300 --allowed-hosts --proxy-config proxy.conf.json
```

- [ ] Check four things:
  - The rail fills as you scroll.
  - Loading the next page moves the fill only slightly.
  - The rail reaches the bottom at the end once all pages are in.
  - It is absent on a direct search with more pages, and absent at the wide layout.
- [ ] Check that the article rail looks exactly as it did before.
- [ ] Stop `sfr-prodserve` afterwards.
