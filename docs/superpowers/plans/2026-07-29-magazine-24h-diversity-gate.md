# Magazine 24h-Diversity Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate magazine run-collapse on *time-based* source diversity — distinct sources active within 24h of the newest entry — and drop the positional trailing-diversity guard, so back-to-back bursts (NDR→Deutschlandfunk on a news tag) both collapse instead of monopolizing the fixed leading window and disabling collapse entirely.

**Architecture:** All logic is in `magazine-planner.ts`. Replace the count-based gate (`distinctSources(first 24 entries) >= 3`) with `activeSourceCount(entries) >= 3`, where `activeSourceCount` counts distinct sources whose effective time (`publishedAt ?? createdAt`) falls within 24h of the view's newest entry. Remove `trailingDiverse` and its constants; a qualifying run (`>= RUN_MIN`) now collapses whenever the gate is on. Narrow the infinite-scroll deferral from "trailing flank not loaded" to "the run's own membership is still undetermined at the loaded boundary". The template-family choice keeps sampling the fixed leading window (visual density, not diversity).

**Tech Stack:** Angular 20 (standalone, signals), TypeScript, Jest (jsdom). Frontend only. GitHub issue: [#168](https://github.com/larspohlmann/simple-feed-reader/issues/168). Follow-up to #160.

---

## Preamble: branch

Concurrent Claude sessions can share this checkout — **check `git status` before switching branches** (another session may be mid-edit). The local checkout may still be on `feature/160-magazine-run-collapse`; `develop` now contains that work via the merged PR #161, so branch from an updated `develop`:

```bash
git status                       # must be clean before checkout
git checkout develop && git pull # pulls the #160 merge
git checkout -b feature/168-magazine-24h-diversity-gate
```

Work from `frontend/`. The CI gate is `npm run check`; the fast inner loop is `npx jest magazine-planner`.

## Agreed constants

| Name | Value | Meaning |
|---|---|---|
| `ACTIVE_WINDOW_MS` | `24 * 60 * 60 * 1000` | The diversity window: sources active within this span of the newest entry. |
| `MIN_VIEW_SOURCES` | 3 (unchanged) | Distinct *active* sources required to enable collapse. |
| `RUN_MIN` | 8 (unchanged) | Same-source entries in a row to collapse (interlopers bridged). |
| `FEATURED_LEAD` | 3 (unchanged) | Newest run entries kept as full blocks before the widget. |
| `WIDGET_PREVIEW` | 4 (unchanged) | Rows the collapsed widget shows before "Show more". |
| `LEADING_WINDOW` | 24 (unchanged) | Fixed sample for the *template-family* choice only (no longer the gate). |

Removed: `TRAILING_FLANK`, `MIN_OTHER_SOURCES`.

## File structure

| File | Change |
|---|---|
| `frontend/src/app/reader/magazine/magazine-planner.ts` | Add `ACTIVE_WINDOW_MS`, `effectiveTime`, `activeSourceCount`; swap the gate; remove `trailingDiverse`, `TRAILING_FLANK`, `MIN_OTHER_SOURCES`; narrow the deferral; collapse unconditionally on `>= RUN_MIN`. |
| `frontend/src/app/reader/magazine/magazine-planner.spec.ts` | Give `e()` a parseable date + a `at()` timestamp helper; replace the two trailing-guard tests; retitle/re-comment the boundary + front-loaded prefix tests; add four time-gate tests. |
| `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` | Update the stale "trailing-diversity guard fires" comment (Task 2). |

---

## Task 1: Time-based gate + planner spec rework

**Files:**
- Modify: `frontend/src/app/reader/magazine/magazine-planner.ts`
- Test: `frontend/src/app/reader/magazine/magazine-planner.spec.ts`

### Spec first (TDD)

- [ ] **Step 1: Give fixtures parseable timestamps and a `at()` helper**

In `magazine-planner.spec.ts`, add a constant + helper above `const e =` (line 5) and change `e()`'s `createdAt` default from `'x'` to the shared instant so undated fixtures all fall inside one 24h window (→ `activeSourceCount` = distinct sources of the whole set, matching every existing test's intent):

```typescript
const NOW = '2026-07-29T12:00:00.000Z';
/** An ISO instant `hoursAgo` before NOW, for exercising the 24h activity window. */
const at = (hoursAgo: number): string =>
  new Date(Date.parse(NOW) - hoursAgo * 3600_000).toISOString();
```

Then in `e()` (line 16) replace `createdAt: 'x',` with `createdAt: NOW,`. Leave `publishedAt: null` — effective time falls back to `createdAt`.

- [ ] **Step 2: Replace the two trailing-guard tests**

Delete `'leaves a run flat when the trailing window lacks two other sources'` (lines 267-277) and replace with a two-source-view test:

```typescript
  it('does not collapse a two-source view', () => {
    // Only two sources active -> collapsing one surfaces just the other, which
    // isn't enough to be worth it. The gate needs >= MIN_VIEW_SOURCES.
    const entries = [
      ...many(10, (i) => big(i, { subscriptionId: 1, source: 'A' })),
      ...many(10, (i) => big(100 + i, { subscriptionId: 2, source: 'B' })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('group');
    expect(entryCount(blocks)).toBe(20);
  });
```

Delete `'holds a qualifying run back until its trailing window loads'` (lines 334-345) and replace with two tests covering the new boundary-only deferral:

```typescript
  it('holds a run back only until its own tail is loaded', () => {
    // The run reaches the loaded boundary, so its membership might still grow ->
    // defer. Once complete, it collapses.
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
    ];
    const held = planMagazine({ entries, grouping: true, complete: false });
    const done = planMagazine({ entries, grouping: true, complete: true });
    expect(held.some((b) => b.kind === 'group')).toBe(false);
    expect(done.some((b) => b.kind === 'group')).toBe(true);
  });

  it('collapses a terminated run even before the feed is complete', () => {
    // A foreign entry after the run proves it terminated, so it can collapse in a
    // partial render — no need to wait for completion.
    const entries = [
      ...many(6, (i) => big(i, { subscriptionId: i + 2, source: `s${i + 2}` })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Dom' })),
      big(200, { subscriptionId: 3, source: 's3' }),
      big(201, { subscriptionId: 4, source: 's4' }),
    ];
    const held = planMagazine({ entries, grouping: true, complete: false });
    expect(held.some((b) => b.kind === 'group')).toBe(true);
  });
```

- [ ] **Step 3: Retitle/re-comment the two now-mislabelled prefix tests**

The test `'is prefix-stable even when a front-loaded source crosses the dominance threshold'` (line 216) still asserts prefix-stability, but the front-loaded 30-run now *collapses* (the gate sees the whole view's diversity). Replace its title and comment (lines 216-218) with:

```typescript
  it('is prefix-stable when a front-loaded source collapses', () => {
    // 30 of source 1 up front (a collapsing run), then 90 mixed. The collapse
    // resolves identically in the partial prefix and the full render.
```

The test `'is prefix-stable when a non-diverse long run straddles the load boundary'` (line 371) — the "non-diverse" premise is gone (no trailing guard). Replace its title and comment (lines 371-375) with:

```typescript
  it('is prefix-stable when a long run straddles the load boundary', () => {
    // The run's head is loaded but its tail is not, so its membership could still
    // grow -> the partial render defers it while the full render collapses it. The
    // page ending at the run head must be identical in both.
```

- [ ] **Step 4: Add the time-gate tests**

Append to the `describe('planMagazine', …)` block:

```typescript
  it('collapses when a third source is active within 24h, though the newest entries are two bursts', () => {
    // The tag-27 shape: 14 of source A then 12 of source B fill the leading
    // entries, but three more sources posted within the last day. Time-based
    // diversity sees them; the old leading-count gate did not.
    const entries = [
      ...many(14, (i) => big(i, { subscriptionId: 1, source: 'A', publishedAt: at(1) })),
      ...many(12, (i) => big(100 + i, { subscriptionId: 2, source: 'B', publishedAt: at(2) })),
      ...many(3, (i) => big(200 + i, { subscriptionId: i + 2, source: `c${i + 2}`, publishedAt: at(10) })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    // Both bursts collapse into their own widgets.
    expect(blocks.filter((b) => b.kind === 'group').length).toBe(2);
  });

  it('stays flat when the only other sources fall outside the 24h window', () => {
    // Two sources burst recently; every other source last posted days ago. Stale
    // sources aren't "recent other content", so the view isn't diverse enough.
    const entries = [
      ...many(14, (i) => big(i, { subscriptionId: 1, source: 'A', publishedAt: at(1) })),
      ...many(12, (i) => big(100 + i, { subscriptionId: 2, source: 'B', publishedAt: at(3) })),
      ...many(5, (i) => big(200 + i, { subscriptionId: i + 2, source: `c${i + 2}`, publishedAt: at(50) })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('group');
  });

  it('measures the 24h window from the newest entry, not the wall clock', () => {
    // The whole tag is days old, but its most recent 24h of activity decides
    // diversity: three sources active within a day of the newest entry -> group.
    const entries = [
      ...many(10, (i) => big(i, { subscriptionId: 1, source: 'A', publishedAt: at(72) })),
      big(100, { subscriptionId: 2, source: 'B', publishedAt: at(80) }),
      big(101, { subscriptionId: 3, source: 'C', publishedAt: at(90) }),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(blocks.some((b) => b.kind === 'group')).toBe(true);
  });

  it('collapses back-to-back bursts that a positional trailing guard would have blocked', () => {
    // A (14) is immediately followed by B (12): A's next entries are a single
    // other source. The old >=2-others-in-the-next-8 guard blocked A; the 24h
    // gate collapses both.
    const entries = [
      ...many(14, (i) => big(i, { subscriptionId: 1, source: 'A', publishedAt: at(1) })),
      ...many(12, (i) => big(100 + i, { subscriptionId: 2, source: 'B', publishedAt: at(2) })),
      ...many(3, (i) => big(200 + i, { subscriptionId: i + 2, source: `c${i + 2}`, publishedAt: at(4) })),
    ];
    const groups = planMagazine({ entries, grouping: true, complete: true }).filter(
      (b) => b.kind === 'group',
    );
    expect(groups.length).toBe(2);
    expect(groups.every((b) => b.kind === 'group' && b.entries.length >= 1)).toBe(true);
  });
```

- [ ] **Step 5: Run the planner spec, expect failures**

Run: `cd frontend && npx jest magazine-planner`
Expected: FAIL — the new time-gate tests reference behavior not yet built; the two replaced tests now assert the new semantics.

### Planner implementation

- [ ] **Step 6: Swap the constants**

In `magazine-planner.ts`, delete the `TRAILING_FLANK` and `MIN_OTHER_SOURCES` constants (the `const TRAILING_FLANK = 8;` and `const MIN_OTHER_SOURCES = 2;` lines and their shared doc-comment). Keep `LEADING_WINDOW`, `MIN_VIEW_SOURCES`, `RUN_MIN`, `FEATURED_LEAD`, `WIDGET_PREVIEW`. Add near the other collapse constants:

```typescript
/** The diversity window. Collapse is judged over the sources ACTIVE in the last
 *  day of the view's content — not a fixed count of leading entries, which two
 *  high-frequency sources bursting back-to-back can monopolize, disabling
 *  collapse on exactly the mixed views it exists for (see #168). */
const ACTIVE_WINDOW_MS = 24 * 60 * 60 * 1000;
```

- [ ] **Step 7: Update the gate in `planMagazine`**

Keep `const sample = entries.slice(0, LEADING_WINDOW);` (still feeds `isImageRich`/`isTextRich`). Change the gate line from:

```typescript
  const collapseEnabled = grouping && distinctSources(sample) >= MIN_VIEW_SOURCES;
```

to:

```typescript
  const collapseEnabled = grouping && activeSourceCount(entries) >= MIN_VIEW_SOURCES;
```

- [ ] **Step 8: Narrow the deferral and drop the trailing-diversity check**

In the collapse branch of the `while` loop, replace:

```typescript
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
```

with:

```typescript
      if (run.sourceEntries.length >= RUN_MIN) {
        // Defer only while the run's OWN membership is still undetermined: a run
        // reaching the loaded boundary might grow, and a lone trailing foreign
        // entry might turn out to be a bridged interloper once the next entry
        // loads. Once it has terminated (a real gap or a foreign entry with a
        // successor), it collapses — no diversity window to wait for. This keeps
        // the plan a stable prefix.
        if (!complete && run.end >= ordered.length - 1) break;
        // Featured lead comes FIRST, so a group block never opens the list.
        page = emitFeaturedLead(blocks, run.sourceEntries, templates, page);
        blocks.push(digest(run.sourceEntries.slice(FEATURED_LEAD)));
        page = emitInterlopers(blocks, run.interlopers, templates, page);
        index = run.end;
        continue;
      }
```

- [ ] **Step 9: Remove `trailingDiverse`, add `effectiveTime` + `activeSourceCount`**

Delete the whole `trailingDiverse` function. Keep `distinctSources` (reused below). Add, next to `distinctSources`:

```typescript
/** An entry's effective instant, matching the API's ordering key: its published
 *  time, or its fetch time when the feed supplied none. NaN when unparseable,
 *  which the window comparison below treats as "outside every window". */
function effectiveTime(entry: EntryDto): number {
  return Date.parse(entry.publishedAt ?? entry.createdAt);
}

/** Distinct sources whose effective time falls within ACTIVE_WINDOW_MS of the
 *  newest entry in the view. Measured from the newest entry, not the wall clock:
 *  it is deterministic — prefix-stable as older pages load, since an older entry
 *  can only ADD a source, never remove one — and a long-untouched tag still
 *  groups by its last active day. */
function activeSourceCount(entries: EntryDto[]): number {
  let newest = -Infinity;
  for (const entry of entries) {
    const time = effectiveTime(entry);
    if (time > newest) newest = time;
  }
  const cutoff = newest - ACTIVE_WINDOW_MS;
  return distinctSources(entries.filter((entry) => effectiveTime(entry) >= cutoff));
}
```

Leave `detectRun`, `distinctSources`, `startsLongRun`, `cappedBeforeLongRun`, and every layout helper unchanged.

- [ ] **Step 10: Run the full Jest suite**

Run: `cd frontend && npm test`
Expected: PASS — planner spec green, no regressions elsewhere.

- [ ] **Step 11: Commit**

```bash
git add frontend/src/app/reader/magazine/magazine-planner.ts frontend/src/app/reader/magazine/magazine-planner.spec.ts
git commit -m "feat(reader): gate magazine collapse on 24h source activity, drop the positional trailing guard (#168)"
```

---

## Task 2: Refresh the stale grouping comment and pass the CI gate

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

- [ ] **Step 1: Update the comment**

The magazine test's comment still claims the run collapses because "the trailing-diversity guard fires" — that guard is gone. Replace the comment block (the four `// …` lines before the `const lead = …` fixture) with:

```typescript
    // The grouped run must not sit at the very start — the planner leads with
    // featured blocks, never a group. Lead with distinct sources so the
    // collapse-enable gate (>= MIN_VIEW_SOURCES active within 24h) is on, and
    // keep the run >= RUN_MIN so it collapses.
```

The fixture already gives every entry a parseable `publishedAt` sharing one day, so `activeSourceCount` counts all its sources and the gate stays on — no fixture change needed.

- [ ] **Step 2: Run the full gate**

Run: `cd frontend && npm run check`
Expected: PASS. If Prettier complains, `npx prettier --write` the touched files (100-col). ESLint/Stylelint clean.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "test(reader): refresh magazine grouping comment for the 24h gate (#168)"
```

---

## Task 3: Verify and open the PR

- [ ] **Step 1: Production build**

Run: `cd frontend && npm run build`
Expected: bundle generation completes (the two pre-existing `entry-list`/`reader-view` SCSS-budget warnings are unrelated).

- [ ] **Step 2: Optional browser check**

With the Docker stack up and `npm start`, open `http://localhost:4200/?tag=27` in magazine view: the 14-NDR and 18-DLF runs should each render as 3 featured blocks + one widget, with the diverse tail flat. (Needs the real account; the planner behavior is fully covered by unit tests, so this is confirmation, not the gate.)

- [ ] **Step 3: Finish the branch**

Use superpowers:finishing-a-development-branch to open the PR into `develop` with `Closes #168` in the body. Verify #168 closes on merge (develop is the default branch).

---

## Self-review notes

- **Spec coverage:** time-based gate replaces the count gate ✓; reference = newest entry ✓; threshold stays 3 ✓; trailing guard removed so back-to-back bursts collapse ✓; deferral narrowed to boundary-undetermined runs ✓; family-choice sample untouched ✓; scope = all grouping views (the gate is global) ✓.
- **Type consistency:** `effectiveTime`/`activeSourceCount` take `EntryDto[]`, return `number`; `distinctSources` retained and reused; removed symbols (`trailingDiverse`, `TRAILING_FLANK`, `MIN_OTHER_SOURCES`) have no remaining references.
- **Known limitation (accepted, unchanged class from #160):** the gate is prefix-stable except when the *first loaded page* (~100 entries) is near-mono within 24h and a later page adds the 3rd active source — then collapse can switch on and reflow. This requires <3 sources across an entire 100-entry recent page, i.e. a genuinely near-mono burst, and matches the boundary-reflow limitation already accepted in #160. Documented in the `activeSourceCount` comment.
- **Timestamp trust:** `effectiveTime` uses `publishedAt ?? createdAt` (the API's own ordering key). Production entries always carry a real `createdAt`; an unparseable value yields NaN and is treated as outside the window (conservative — it can only *reduce* the active count, never spuriously enable collapse).
