# Magazine Run-Collapse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In magazine view, collapse a long same-source run (including a dominant source like NDR) into 3 featured blocks plus one inline-expandable widget that owns the rest — but only when the view is genuinely mixed.

**Architecture:** All logic lives in the existing magazine planner (`magazine-planner.ts`), which turns a flat `EntryDto[]` into `MagazineBlock[]`. We reshape the `group` block to own a run's whole tail (`entries` + `previewCount`, dropping `moreCount`), replace the `DOMINANT_SHARE` exemption with a fixed-window *collapse-enable gate* plus a per-run *trailing-diversity* guard, add single-interloper bridging, and make `SourceGroupComponent` expand in place instead of linking away. Prefix-stability under infinite scroll is preserved by only resolving a run once its trailing window is loaded (else deferring, the planner's existing `break` pattern).

**Tech Stack:** Angular 20 (standalone components, signals), TypeScript, Jest (jsdom), Transloco i18n. Frontend only — no backend changes. GitHub issue: [#160](https://github.com/larspohlmann/simple-feed-reader/issues/160).

---

## Preamble: branch

Concurrent Claude sessions can share this checkout — **check `git status` before branching** (another session may be mid-edit). Then, from a clean `develop`:

```bash
git checkout develop && git pull
git checkout -b feature/160-magazine-run-collapse
```

Because the PR targets `develop`, GitHub won't auto-close #160 on merge unless the PR body says `Closes #160` and `develop` is the default branch (it is) — verify after merge regardless.

Work from `frontend/`. Run `npm ci` once if `node_modules` is stale. The CI gate is `npm run check` (ESLint + Prettier + Stylelint + Jest); the fast inner loop is `npm test`.

## Agreed constants

| Name | Value | Meaning |
|---|---|---|
| `RUN_MIN` | 8 | Same-source entries in a row to trigger a collapse (interlopers bridged). |
| `FEATURED_LEAD` | 3 | Newest run entries kept as full magazine blocks before the widget. |
| `WIDGET_PREVIEW` | 4 | Rows the collapsed widget shows before "Show more". |
| `TRAILING_FLANK` | 8 | Entries after a run scanned for other sources. |
| `MIN_OTHER_SOURCES` | 2 | Distinct other sources required in the trailing flank to collapse. |
| `LEADING_WINDOW` | 24 | Fixed leading window for the collapse-enable gate (was `DOMINANCE_SAMPLE`). |
| `MIN_VIEW_SOURCES` | 3 | Distinct sources in the leading window to enable collapse at all. |

## File structure

| File | Change |
|---|---|
| `frontend/src/app/reader/magazine/magazine-block.ts` | `group` block: `entries` becomes the full owned tail; add `previewCount`; drop `moreCount`. |
| `frontend/src/app/reader/magazine/magazine-planner.ts` | New constants; gate + `detectRun` + `trailingDiverse` + `emitPages`/`emitFeaturedLead`/`emitInterlopers` + `distinctSources`; new `digest`; delete `dominantSources`, `sameSourceRun`, `DOMINANT_SHARE`, `GROUP_MIN/SHOW/CONSUMES`, `digestedRunEnd`. |
| `frontend/src/app/reader/magazine/magazine-planner.spec.ts` | Fix `entryCount` helper; rework the grouping tests; add collapse / gate / bridge / defer / re-feature / prefix-stability tests. |
| `frontend/src/app/reader/magazine/source-group.component.ts` | Inputs `entries` (full tail) + `previewCount`; `expanded` signal, `visibleEntries`, `hiddenCount`, `toggle()`. |
| `frontend/src/app/reader/magazine/source-group.component.html` | Render `visibleEntries()`; replace the router-link "more" with an expand `<button>` (aria-expanded, chevron, "Show less"). |
| `frontend/src/app/reader/magazine/source-group.component.scss` | Style the toggle button (reuses `.more` look). |
| `frontend/src/app/reader/magazine/source-group.component.spec.ts` | New shape + expand interaction tests. |
| `frontend/src/app/reader/entry-list/entry-list.component.html` | Bind `[previewCount]` instead of `[moreCount]`. |
| `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` | Update the stale `DOMINANT_SHARE` comment on the magazine test. |
| `frontend/public/i18n/en.json`, `de.json` | Add `reader.showLess`. |

---

## Task 1: Reshape the group block and rewrite the planner

**Files:**
- Modify: `frontend/src/app/reader/magazine/magazine-block.ts:26-32`
- Modify: `frontend/src/app/reader/magazine/magazine-planner.ts` (constants, `planMagazine`, helpers, `digest`)
- Test: `frontend/src/app/reader/magazine/magazine-planner.spec.ts`
- Modify (compile-green): `frontend/src/app/reader/entry-list/entry-list.component.html:178`, `frontend/src/app/reader/magazine/source-group.component.ts`, `frontend/src/app/reader/magazine/source-group.component.html`, `frontend/src/app/reader/magazine/source-group.component.spec.ts`

### Block shape

- [ ] **Step 1: Change the `group` block shape**

In `magazine-block.ts`, replace the `group` variant (currently lines 26-32):

```typescript
  | {
      kind: 'group';
      subscriptionId: number;
      source: string;
      /** The run's whole owned tail — the widget previews some and expands the rest. */
      entries: EntryDto[];
      /** How many rows the widget shows before "Show more". */
      previewCount: number;
    };
```

### Planner spec first (TDD)

- [ ] **Step 2: Update the `entryCount` helper and rework the grouping tests**

In `magazine-planner.spec.ts`, the group block no longer carries `moreCount`. Replace the helper (line 43-44):

```typescript
const entryCount = (bs: MagazineBlock[]): number =>
  bs.reduce((n, b) => n + (b.kind === 'group' ? b.entries.length : 1), 0);
```

Replace the test `'does not group when one source dominates the view'` (keeps passing — now via the gate, not `DOMINANT_SHARE`) with the same body but an updated comment:

```typescript
  it('does not collapse when the leading window is effectively single-source', () => {
    // Fewer than MIN_VIEW_SOURCES distinct sources in the leading window ->
    // collapse is disabled entirely, so a mono view renders flat and smooth.
    const entries = many(40, (i) => big(i, { subscriptionId: 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('group');
    expect(entryCount(blocks)).toBe(40);
  });
```

Replace `'groups a minority source and bounds what the digest consumes'` with:

```typescript
  it('collapses a qualifying run into a featured lead plus a tail-owning widget', () => {
    const entries = [
      ...many(30, (i) => big(i, { subscriptionId: (i % 6) + 2 })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Burst' })),
      ...many(30, (i) => big(200 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    // Widget owns the whole tail: run of 8, minus 3 featured, = 5 entries.
    expect(group!.kind === 'group' && group!.entries.length).toBe(5);
    expect(group!.kind === 'group' && group!.previewCount).toBe(4);
    // The 3 newest of the run led as normal blocks, before the widget.
    const groupIndex = blocks.indexOf(group!);
    const featured = blocks
      .slice(0, groupIndex)
      .filter((b) => b.kind !== 'group' && b.entry.source === 'Burst');
    expect(featured.length).toBe(3);
    expect(entryCount(blocks)).toBe(68);
  });
```

- [ ] **Step 3: Add the new behavior tests**

Append these to the `describe('planMagazine', …)` block in `magazine-planner.spec.ts`:

```typescript
  it('collapses a dominant source while the view stays mixed', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(12, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(6, (i) => big(200 + i, { subscriptionId: i + 2, source: `s${i + 2}` })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    expect(group!.kind === 'group' && group!.entries.length).toBe(9); // 12 - 3 featured
    expect(group!.kind === 'group' && group!.previewCount).toBe(4);
    expect(entryCount(blocks)).toBe(24);
  });

  it('leaves a run flat when the trailing window lacks two other sources', () => {
    // Only ONE other source ever follows the run -> not enough to surface.
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(10, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(10, (i) => big(200 + i, { subscriptionId: 2, source: 'Solo' })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('group');
    expect(entryCount(blocks)).toBe(26);
  });

  it('merges two same-source segments across a single foreign post', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(6, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(150, { subscriptionId: 9, source: 'Interloper' }),
      ...many(6, (i) => big(160 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(8, (i) => big(200 + i, { subscriptionId: i + 2, source: `s${i + 2}` })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    // Both 6-entry segments merge into one run of 12, minus 3 featured = 9.
    expect(group!.kind === 'group' && group!.entries.length).toBe(9);
    // The bridged foreign post is surfaced as its own block AFTER the widget.
    const groupIndex = blocks.indexOf(group!);
    const surfaced = blocks
      .slice(groupIndex + 1)
      .find((b) => b.kind !== 'group' && b.entry.id === 150);
    expect(surfaced).toBeDefined();
    expect(entryCount(blocks)).toBe(27);
  });

  it('does not merge across a gap of two foreign posts', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(150, { subscriptionId: 9, source: 'A' }),
      big(151, { subscriptionId: 10, source: 'B' }),
      ...many(8, (i) => big(200 + i, { subscriptionId: i + 2, source: `s${i + 2}` })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    // Run stops at the 2-post gap: 8 - 3 featured = 5, NOT merged past it.
    expect(group!.kind === 'group' && group!.entries.length).toBe(5);
    expect(entryCount(blocks)).toBe(24);
  });

  it('re-features each separate run of the same source', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(150, { subscriptionId: 9, source: 'A' }),
      big(151, { subscriptionId: 10, source: 'B' }),
      big(152, { subscriptionId: 11, source: 'C' }),
      ...many(8, (i) => big(160 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(6, (i) => big(200 + i, { subscriptionId: i + 2, source: `s${i + 2}` })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const groups = blocks.filter((b) => b.kind === 'group');
    expect(groups.length).toBe(2);
    expect(entryCount(blocks)).toBe(37);
  });

  it('holds a qualifying run back until its trailing window loads', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(200, { subscriptionId: 3, source: 's3' }),
      big(201, { subscriptionId: 4, source: 's4' }),
    ];
    const held = planMagazine({ entries, grouping: true, complete: false });
    const done = planMagazine({ entries, grouping: true, complete: true });
    expect(held.some((b) => b.kind === 'group')).toBe(false);
    expect(done.some((b) => b.kind === 'group')).toBe(true);
  });

  it('is prefix-stable when a collapsing run’s page grows', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(12, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(102, (i) => big(200 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const first = planMagazine({ entries: entries.slice(0, 60), grouping: true, complete: false });
    const full = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(full).slice(0, first.length)).toEqual(kinds(first));
  });

  it('emits every entry exactly once even when runs collapse and bridge', () => {
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(5, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(150, { subscriptionId: 9, source: 'X' }),
      ...many(5, (i) => big(160 + i, { subscriptionId: 1, source: 'Dom' })),
      ...many(8, (i) => big(200 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(entryCount(blocks)).toBe(24);
  });
```

- [ ] **Step 4: Run the planner spec, expect failures**

Run: `cd frontend && npx jest magazine-planner`
Expected: FAIL — new tests reference the old algorithm; `entries.length` mismatches and missing collapse behavior.

### Planner implementation

- [ ] **Step 5: Replace the constants block**

In `magazine-planner.ts`, replace the constants (currently lines 15-51, from the `DOMINANT_SHARE` comment through `PAGE_HEIGHT_CAP`/`QUOTE_MIN_TEXT`) with:

```typescript
/** Collapse decisions are judged over a fixed leading window, never the whole
 *  loaded set: a source's share shifts as more pages load, which would flip a
 *  decision and reshuffle already-rendered blocks. The window is far smaller
 *  than one API page (PAGE_SIZE = 100), so every render past the first samples
 *  the identical leading entries — the plan stays a stable prefix. */
const LEADING_WINDOW = 24;
/** Run-collapse is enabled only when the leading window is genuinely mixed:
 *  collapsing one source must leave enough OTHER recent content to fill the
 *  space, and a near-mono view must render flat — and, crucially, without
 *  deferring an unterminated run page after page. Three distinct sources means
 *  at least two remain besides any one source that collapses. */
const MIN_VIEW_SOURCES = 3;
/** The text-forward family is chosen only when the leading window is image-poor
 *  AND text-rich (see isImageRich/isTextRich). Both shares are judged over the
 *  same fixed window as the gate, for the same prefix-stability. */
const IMAGE_RICH_SHARE = 0.35;
const TEXT_RICH_SHARE = 0.4;
/** A same-source run collapses once it reaches this many entries in a row.
 *  Single foreign posts embedded in the run are bridged — see `detectRun`. */
const RUN_MIN = 8;
/** The newest entries of a collapsing run kept as full magazine blocks, so the
 *  source still gets a visual moment before the rest folds into the widget. */
const FEATURED_LEAD = 3;
/** How many rows the collapsed widget previews before "Show more". */
const WIDGET_PREVIEW = 4;
/** A run collapses only if the entries immediately AFTER it carry enough other
 *  recent sources to surface once it folds up. Judged over this trailing window;
 *  an unterminated run whose flank isn't loaded yet is deferred, not resolved. */
const TRAILING_FLANK = 8;
const MIN_OTHER_SOURCES = 2;
/** The largest slot may reach this far ahead for an entry that fits it. */
const LOOK_AHEAD = 2;
/** How far back the opener may reach for an image to lead with when the newest
 *  entries have none. */
const LEAD_IMAGE_REACH = 6;
/** Per-page height ceiling, in BLOCK_HEIGHT units — about one and a half phone
 *  screens. Without it three heroes can land in one page. */
const PAGE_HEIGHT_CAP = 1100;
const QUOTE_MIN_TEXT = 300;

/** A same-source run, with any single foreign posts it bridges pulled aside. */
interface DetectedRun {
  source: number;
  sourceEntries: EntryDto[];
  interlopers: EntryDto[];
  /** Exclusive index in `ordered` where the run's span ends. */
  end: number;
}
```

- [ ] **Step 6: Replace `planMagazine`**

Replace the whole `planMagazine` function (currently lines 53-101) with:

```typescript
export function planMagazine(input: MagazinePlanInput): MagazineBlock[] {
  const { entries, grouping, complete } = input;
  const blocks: MagazineBlock[] = [];
  const sample = entries.slice(0, LEADING_WINDOW);
  const collapseEnabled = grouping && distinctSources(sample) >= MIN_VIEW_SOURCES;
  const useTextFamily = !isImageRich(sample) && isTextRich(sample);
  const templates = useTextFamily ? TEXT_TEMPLATES : IMAGE_TEMPLATES;
  // Land the reader on a picture: the image family pulls the nearest image entry
  // to the front when the newest are image-less. The text family opens on a
  // headline by design, so it keeps strict order.
  const ordered = useTextFamily ? entries : leadWithImage(entries);

  let index = 0;
  let page = 0;

  while (index < ordered.length) {
    if (collapseEnabled) {
      const run = detectRun(ordered, index);
      if (run.sourceEntries.length >= RUN_MIN) {
        // The trailing window must be loaded to judge diversity. Holding an
        // unterminated run back — rather than rendering it flat and regrouping
        // it on the next page — is what keeps the plan a stable prefix.
        if (!complete && ordered.length - run.end < TRAILING_FLANK) break;
        if (trailingDiverse(ordered, run.end, run.source)) {
          // Featured lead comes FIRST, so a group block never opens the list.
          page = emitFeaturedLead(blocks, run.sourceEntries, templates, page);
          blocks.push(digest(run.sourceEntries.slice(FEATURED_LEAD)));
          page = emitInterlopers(blocks, run.interlopers, templates, page);
          index = run.end;
          continue;
        }
      }
    }

    const template = templateFor(page, templates);
    const remaining = ordered.length - index;
    if (remaining < template.length && !complete) break;

    const slice = ordered.slice(index, index + Math.min(template.length, remaining));
    blocks.push(...layOutPage(template, slice, page));
    index += slice.length;
    page += 1;
  }

  return blocks;
}
```

- [ ] **Step 7: Delete `dominantSources` and `sameSourceRun`; add the new helpers**

Delete `dominantSources` (currently lines 103-114) and `sameSourceRun` (currently lines 303-313). Add these helpers (place `detectRun`/`trailingDiverse`/`distinctSources` after `planMagazine`, and `emitFeaturedLead`/`emitInterlopers`/`emitPages` near `layOutPage`):

```typescript
/** Walk a same-source run from `start`, bridging any single foreign post that
 *  the same source resumes right after (`Dom…X…Dom`). A gap of two or more
 *  foreign posts in a row ends the run. Every entry in `[start, end)` is either
 *  a same-source entry or a bridged interloper. */
function detectRun(ordered: EntryDto[], start: number): DetectedRun {
  const source = ordered[start].subscriptionId;
  const sourceEntries: EntryDto[] = [];
  const interlopers: EntryDto[] = [];
  let index = start;
  while (index < ordered.length) {
    if (ordered[index].subscriptionId === source) {
      sourceEntries.push(ordered[index]);
      index += 1;
      continue;
    }
    const bridges = index + 1 < ordered.length && ordered[index + 1].subscriptionId === source;
    if (!bridges) break;
    interlopers.push(ordered[index]);
    index += 1;
  }
  return { source, sourceEntries, interlopers, end: index };
}

/** Whether the entries just after a run carry at least MIN_OTHER_SOURCES
 *  distinct sources other than the run's own — the recent content that would
 *  surface once the run folds up. */
function trailingDiverse(ordered: EntryDto[], runEnd: number, source: number): boolean {
  const others = new Set<number>();
  const limit = Math.min(ordered.length, runEnd + TRAILING_FLANK);
  for (let position = runEnd; position < limit; position++) {
    if (ordered[position].subscriptionId !== source) others.add(ordered[position].subscriptionId);
  }
  return others.size >= MIN_OTHER_SOURCES;
}

function distinctSources(entries: EntryDto[]): number {
  const sources = new Set<number>();
  for (const entry of entries) sources.add(entry.subscriptionId);
  return sources.size;
}

/** The run's newest entries, laid out as ordinary magazine blocks. */
function emitFeaturedLead(
  blocks: MagazineBlock[],
  sourceEntries: EntryDto[],
  templates: readonly (readonly Slot[])[],
  page: number,
): number {
  return emitPages(blocks, sourceEntries.slice(0, FEATURED_LEAD), templates, page);
}

/** The foreign posts a run bridged, surfaced after its widget as ordinary
 *  blocks — collapsing the run reveals them rather than re-hiding them. */
function emitInterlopers(
  blocks: MagazineBlock[],
  interlopers: EntryDto[],
  templates: readonly (readonly Slot[])[],
  page: number,
): number {
  return emitPages(blocks, interlopers, templates, page);
}

/** Lay a short list of entries out through the template machinery, in
 *  template-sized chunks so a list longer than one template is never truncated. */
function emitPages(
  blocks: MagazineBlock[],
  items: EntryDto[],
  templates: readonly (readonly Slot[])[],
  page: number,
): number {
  let index = 0;
  while (index < items.length) {
    const template = templateFor(page, templates);
    const slice = items.slice(index, index + template.length);
    blocks.push(...layOutPage(template, slice, page));
    index += slice.length;
    page += 1;
  }
  return page;
}
```

- [ ] **Step 8: Replace `digest`**

Replace the `digest` function (currently lines 292-301) with:

```typescript
/** A widget owning a run's whole tail; the component previews `previewCount`
 *  rows and expands the rest in place. */
function digest(tail: EntryDto[]): MagazineBlock {
  return {
    kind: 'group',
    subscriptionId: tail[0].subscriptionId,
    source: tail[0].source,
    entries: tail,
    previewCount: Math.min(WIDGET_PREVIEW, tail.length),
  };
}
```

Leave `isImageRich`, `isTextRich`, `leadWithImage`, `templateFor`, `seed`, `resolveSlot`, `layOutPage`, `withinBudget`, `assign`, `settle`, `fits`, `isPortrait`, and `toBlock` unchanged.

### Keep the consumers compiling (still non-interactive)

- [ ] **Step 9: Point the widget at the new inputs (preview only, no expand yet)**

In `source-group.component.ts`, replace `moreCount` with `previewCount` and derive the hidden count:

```typescript
import { Component, computed, input, output } from '@angular/core';
// …unchanged imports…

export class SourceGroupComponent {
  readonly source = input.required<string>();
  readonly subscriptionId = input.required<number>();
  /** The run's whole owned tail. */
  readonly entries = input.required<EntryDto[]>();
  /** How many rows to show before the tail is expanded. */
  readonly previewCount = input.required<number>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();

  readonly visibleEntries = computed(() => this.entries().slice(0, this.previewCount()));
  readonly hiddenCount = computed(() => this.entries().length - this.previewCount());
}
```

In `source-group.component.html`, render `visibleEntries()` and show a static count for now (the expand button lands in Task 2). Replace the `@for` (line 15) target and the `@if (moreCount() > 0)` block (lines 21-37):

```html
  <div class="items">
    @for (item of visibleEntries(); track item.id) {
      <div class="item">
        <app-entry-compact [entry]="item" [showSource]="false" (open)="open.emit($event)" />
      </div>
    }
  </div>
  @if (hiddenCount() > 0) {
    <p class="more">
      {{ 'reader.moreFromCount' | transloco: { count: hiddenCount(), source: source() } }}
    </p>
  }
```

In `entry-list.component.html:178`, change the binding:

```html
            [previewCount]="grp(b).previewCount"
```

- [ ] **Step 10: Update the widget spec to the new shape**

Replace `source-group.component.spec.ts` `mount` and the first two tests so it drives `entries` (full tail) + `previewCount`:

```typescript
  function mount(entries: EntryDto[], previewCount: number) {
    TestBed.configureTestingModule({
      imports: [SourceGroupComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(SourceGroupComponent);
    f.componentRef.setInput('source', 'heise');
    f.componentRef.setInput('subscriptionId', 7);
    f.componentRef.setInput('entries', entries);
    f.componentRef.setInput('previewCount', previewCount);
    f.componentRef.setInput('tags', []);
    f.detectChanges();
    return f;
  }

  it('previews previewCount rows and counts the hidden tail', () => {
    const el = mount([e(1), e(2), e(3), e(4), e(5), e(6), e(7)], 4).nativeElement as HTMLElement;
    expect(el.textContent).toContain('heise');
    expect(el.querySelectorAll('app-entry-compact').length).toBe(4);
    expect(el.querySelector('.more')!.textContent).toContain('3 more from heise');
  });

  it('renders no more indicator when the tail fits the preview', () => {
    const el = mount([e(1), e(2), e(3)], 3).nativeElement as HTMLElement;
    expect(el.querySelector('.more')).toBeNull();
  });
```

Update the tags test to call `mount([e(1), e(2), e(3), e(4), e(5)], 4)` and set tags via `f.componentRef.setInput('tags', [tag(2, 'Tech')])` before `detectChanges`, and the open test to `mount([e(1), e(2), e(3), e(4), e(5)], 4)`.

- [ ] **Step 11: Run the full Jest suite**

Run: `cd frontend && npm test`
Expected: PASS — planner and widget specs green.

- [ ] **Step 12: Commit**

```bash
git add frontend/src/app/reader/magazine frontend/src/app/reader/entry-list/entry-list.component.html
git commit -m "feat(reader): collapse long same-source magazine runs into a tail-owning widget (#160)"
```

---

## Task 2: Expand the collapsed widget in place

**Files:**
- Modify: `frontend/src/app/reader/magazine/source-group.component.ts`
- Modify: `frontend/src/app/reader/magazine/source-group.component.html`
- Modify: `frontend/src/app/reader/magazine/source-group.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/magazine/source-group.component.spec.ts`

- [ ] **Step 1: Write the expand test**

Append to `source-group.component.spec.ts`:

```typescript
  it('expands to reveal the whole tail and collapses again', () => {
    const f = mount([e(1), e(2), e(3), e(4), e(5), e(6), e(7)], 4);
    const el = f.nativeElement as HTMLElement;
    const button = el.querySelector('button.more') as HTMLButtonElement;
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(el.querySelectorAll('app-entry-compact').length).toBe(4);

    button.click();
    f.detectChanges();
    expect(el.querySelectorAll('app-entry-compact').length).toBe(7);
    expect(button.getAttribute('aria-expanded')).toBe('true');
    expect(button.textContent).toContain('Show less');

    button.click();
    f.detectChanges();
    expect(el.querySelectorAll('app-entry-compact').length).toBe(4);
  });
```

The transloco testing harness echoes keys, so `Show less` must be the literal fallback; assert on the key text your harness returns — if it returns the key, assert `reader.showLess` instead of `Show less`. Check the first-run output and adjust the expectation to match the harness (the existing tests assert on rendered English like `'4 more from heise'`, so this harness renders real strings — keep `Show less`).

- [ ] **Step 2: Run it, expect failure**

Run: `cd frontend && npx jest source-group`
Expected: FAIL — no `button.more`, no `expanded` state.

- [ ] **Step 3: Add the expand state to the component**

In `source-group.component.ts`, add a signal and make `visibleEntries` react to it:

```typescript
import { Component, computed, input, output, signal } from '@angular/core';
// …unchanged imports…

export class SourceGroupComponent {
  readonly source = input.required<string>();
  readonly subscriptionId = input.required<number>();
  readonly entries = input.required<EntryDto[]>();
  readonly previewCount = input.required<number>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();

  /** Ephemeral: the widget starts collapsed on every fresh render. Survives an
   *  article open/close (the list stays mounted), resets on reload/reselect. */
  readonly expanded = signal(false);
  readonly visibleEntries = computed(() =>
    this.expanded() ? this.entries() : this.entries().slice(0, this.previewCount()),
  );
  readonly hiddenCount = computed(() => this.entries().length - this.previewCount());

  toggle(): void {
    this.expanded.update((open) => !open);
  }
}
```

- [ ] **Step 4: Replace the static count with a toggle button**

In `source-group.component.html`, replace the `@if (hiddenCount() > 0)` block from Task 1 with:

```html
  @if (hiddenCount() > 0) {
    <button
      class="more"
      type="button"
      [attr.aria-expanded]="expanded()"
      (click)="toggle()"
    >
      @if (expanded()) {
        {{ 'reader.showLess' | transloco }}
        <app-icon name="expand_less" size="sm" />
      } @else {
        {{ 'reader.moreFromCount' | transloco: { count: hiddenCount(), source: source() } }}
        <app-icon name="expand_more" size="sm" />
      }
    </button>
  }
```

`IconComponent` is already imported in this component. Confirm `expand_more` / `expand_less` exist in the icon set (grep `frontend/src/app/shared/icon`); if not, use the icons already used for chevrons in the sidebar (grep `name="expand`), matching whatever the codebase ships.

- [ ] **Step 5: Style the button as the old link read**

In `source-group.component.scss`, the `.more` rules already exist for the `<a>`. Ensure they apply to the `<button>` too — replace the `.more` selector block (lines 52-65) so it resets button chrome:

```scss
.more {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  width: 100%;
  padding: var(--space-3) var(--space-4);
  border: none;
  border-top: 1px solid var(--border);
  background: none;
  color: var(--accent);
  font: inherit;
  font-size: var(--fs-sm);
  text-align: left;
  cursor: pointer;
}

.more:hover {
  background: var(--surface-0);
}
```

- [ ] **Step 6: Add the i18n string**

In `frontend/public/i18n/en.json`, in the `reader` object next to `moreFromCount` (line 237), add:

```json
    "showLess": "Show less",
```

In `frontend/public/i18n/de.json` at the matching spot:

```json
    "showLess": "Weniger anzeigen",
```

- [ ] **Step 7: Run the widget spec**

Run: `cd frontend && npx jest source-group`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/magazine/source-group.component.ts frontend/src/app/reader/magazine/source-group.component.html frontend/src/app/reader/magazine/source-group.component.scss frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(reader): expand a collapsed magazine source run in place (#160)"
```

---

## Task 3: Fix the stale test comment and pass the CI gate

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts:298-319`

- [ ] **Step 1: Update the stale dominance comment**

The magazine test still passes (a `app-source-group` is rendered), but its comment cites the removed `DOMINANT_SHARE`. Replace the comment (lines 299-303) with:

```typescript
    // The grouped run must not sit at the very start — the planner leads with
    // featured blocks, never a group. Lead with distinct sources so the
    // collapse-enable gate (>= MIN_VIEW_SOURCES) is on, keep the run >= RUN_MIN,
    // and give it a diverse tail so the trailing-diversity guard fires.
```

If the run length in the fixture is below `RUN_MIN` (8) after any edit, top it up; the current fixture already uses 8, which qualifies.

- [ ] **Step 2: Run the full gate**

Run: `cd frontend && npm run check`
Expected: PASS. If Prettier complains, run `npx prettier --write` on the touched files (100-col; break long lines). Stylelint bans hex and raw `px` outside `theme/` — the scss above uses only tokens. ESLint must be clean.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "test(reader): refresh magazine grouping comment for the run-collapse gate (#160)"
```

---

## Task 4: Manual verification in the browser

- [ ] **Step 1: Start the dev server and Docker stack**

The SPA talks to `https://localhost:8443`, so the Docker stack must be up (`docker compose up -d` from repo root). Then `cd frontend && npm start` and open `http://localhost:4200/`.

- [ ] **Step 2: Verify the collapse**

- Switch layout to **magazine** (it's the default). Open **All Items** with a feed like NDR that posts in bursts.
- Confirm a long NDR run now shows **3 featured blocks then one widget** with 4 rows, instead of 20+ blocks.
- Confirm the "N more from NDR" button **expands in place** to the full tail and "Show less" collapses it — no navigation.
- Confirm a single foreign post embedded in an NDR run is **surfaced** as its own block after the widget.
- Confirm a **tag view** that mixes NDR with ≥2 other recent sources collapses the same way, and a single-feed (subscription) view is **unchanged** (flat).
- Scan `backend/var/log/dev.log` for anything new (this is a frontend change, but the habit catches surprises).

- [ ] **Step 3: Finish the branch**

Use superpowers:finishing-a-development-branch to open the PR into `develop` with `Closes #160` in the body. Verify the issue closes on merge (develop is the default branch).

---

## Self-review notes

- **Spec coverage:** trigger (`RUN_MIN` + `trailingDiverse`) ✓; dominance exemption removed ✓; collapse-enable gate for mono/near-mono ✓; featured lead + tail-owning widget ✓; inline expand replaces navigate-away ✓; single-interloper bridging + surfacing ✓; ≥2-gap breaks ✓; re-featuring per run ✓; prefix-stability via deferral ✓; scope (grouping-on views, self-suppressing) ✓.
- **Type consistency:** `group` block uses `entries` + `previewCount` everywhere (block, planner `digest`, component inputs, entry-list binding, specs); `moreCount` fully removed.
- **Known limitation (acceptable):** a genuinely near-mono aggregated view fails the collapse-enable gate and renders flat — by design; its runs were never going to have a diverse trailing flank. A diverse view whose run happens to reach the loaded page boundary defers ~one page before resolving, matching the planner's existing partial-page hold-back.
