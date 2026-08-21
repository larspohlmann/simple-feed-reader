# Single Refresh Reload Authority Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make one user-initiated refresh reload the entry list exactly once, while the onboarding sweep keeps its progressive per-slice fill.

**Architecture:** Replace the reader shell's slice effect with a single reload authority that distinguishes the onboarding sweep (per-slice fill) from a user-initiated refresh (one reload at finish) using the existing `sweeping()` flag, and delete the now-redundant `onDone` reloads at the shell's three refresh call sites. `RefreshService` is unchanged — its `onDone` parameter stays because the settings backup/OPML pages still use it.

**Tech Stack:** Angular 20 standalone components + signals, Jest + jsdom, Angular `HttpTestingController`.

**Spec:** `docs/superpowers/specs/2026-08-21-502-single-refresh-reload-authority-design.md`

## Global Constraints

- Frontend gate is `npm run check` (ESLint + Prettier + Stylelint + Jest), run from `frontend/`.
- Prettier: 100-column width.
- Standalone components and signals; no NgModules.
- `RefreshService.run(onDone, scope)` keeps its `onDone` parameter — `settings/backup-section.component.ts` and `settings/opml-section.component.ts` depend on it.
- `EntriesStore.load()` fires one real GET per call (a `loadSeq` token guards stale responses only); "one reload" means one `/api/entries` request.
- All work happens on branch `fix/502-pull-to-refresh-double-reload` (already created off `develop`).

---

### Task 1: Prove the double reload with a failing test

Add a component test that pins the target behaviour — one scoped refresh causes exactly one `/api/entries` reload (plus one `/api/tags`). It fails against today's double-reload code, which is the point.

**Files:**
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts` (add a `describe` block near the existing scoped-refresh tests, after the `refreshDone` constant at line ~734)

**Interfaces:**
- Consumes: existing spec helpers `boot()` (drains the initial subscriptions/tags/entries/recommendations requests and returns a `ComponentFixture`), the `refreshDone` constant (a `completed` `RefreshReport` with `remaining: 0`), and the module-level `ctrl: HttpTestingController`.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

Add this block immediately after the existing `it('scopes an all-items refresh ...')` test (around line 743) in `reader-shell.component.spec.ts`:

```ts
describe('one scoped refresh reloads the list once (#502)', () => {
  it('fires exactly one entries reload and one tags reload after the run finishes', () => {
    const f = boot();

    f.componentInstance.onScopedRefresh();

    // The refresh sweep itself.
    ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh').flush(refreshDone);
    f.detectChanges();

    // Exactly one reload of each list-backing resource — the double reload
    // (slice effect + onDone) would make entries match twice here.
    expect(ctrl.match((r) => r.url === 'https://api.test/api/entries').length).toBe(1);
    expect(ctrl.match((r) => r.url === 'https://api.test/api/tags').length).toBe(1);
    expect(ctrl.match((r) => r.url === 'https://api.test/api/subscriptions').length).toBe(1);

    // Drain the reload requests so verify() is clean.
    ctrl.match((r) => r.url === 'https://api.test/api/entries')[0].flush({ entries: [], nextCursor: null });
    ctrl.match((r) => r.url === 'https://api.test/api/tags')[0].flush({ tags: [] });
    ctrl.match((r) => r.url === 'https://api.test/api/subscriptions')[0].flush(subsBody);
    ctrl.verify();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx jest --silent src/app/reader/reader-shell.component.spec.ts -t "one scoped refresh reloads the list once"`
Expected: FAIL — the entries match length is `2` (slice effect + `onDone`), so the first `expect(...).toBe(1)` fails. (Depending on flush timing the failure may instead be an unexpected extra request at `ctrl.verify()`; either way it is red.)

- [ ] **Step 3: Commit the failing test**

```bash
git add frontend/src/app/reader/reader-shell.component.spec.ts
git commit -m "test(reader): pin one-reload-per-scoped-refresh, currently failing (#502)"
```

---

### Task 2: Make the slice effect the single reload authority

Rewrite the slice effect so it reloads per-slice only during the onboarding sweep and once-at-finish for every user-initiated refresh, and add the `tags` reload it previously lacked.

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts:443-452` (the slice effect)

**Interfaces:**
- Consumes: `this.refreshSvc.slice()` (monotonic counter, increments per landing report), `this.refreshSvc.running()` (true while a run is in flight), `this.sweeping()` (true only for the span of the post-onboarding sweep), `this.subs.load()`, `this.tags.load()`, `this.entries.load(query)`, `queryFromSelection(this.selection())`.
- Produces: the sole list-reload-after-refresh authority in the shell. Tasks 3–5 rely on it covering the manual-refresh, global-refresh, and add-feed paths.

- [ ] **Step 1: Replace the slice effect**

Replace the existing block (currently lines 443–452):

```ts
    // Repopulate as slices land, not only when the sweep ends. Landing in a
    // reader that stays empty for two minutes is the bad first impression this
    // whole feature exists to remove.
    effect(() => {
      if (this.refreshSvc.slice() === 0) return;
      untracked(() => {
        this.subs.load();
        this.entries.load(queryFromSelection(this.selection()));
      });
    });
```

with:

```ts
    // The single authority that reloads the list after a refresh (#502). Two
    // intents, one place:
    //   - the onboarding sweep (sweeping()) fills progressively, so each
    //     landing slice reloads — a new user must not stare at an empty list
    //     for the whole sweep (#127);
    //   - every user-initiated refresh (mobile pull, header/sidebar Refresh,
    //     add-feed) reloads once, when the run finishes, so a scoped refresh
    //     never flickers or reorders mid-sweep.
    // A second reload used to live in each run()'s onDone callback (#61), so one
    // scoped refresh loaded the list twice. That reload now lives here alone.
    effect(() => {
      const slice = this.refreshSvc.slice();
      const running = this.refreshSvc.running();
      untracked(() => {
        if (slice === 0) return; // nothing has reported yet
        if (!this.sweeping() && running) return; // manual refresh: wait for finish
        this.subs.load();
        this.tags.load(); // onDone reloaded tags; the old slice effect did not
        this.entries.load(queryFromSelection(this.selection()));
      });
    });
```

- [ ] **Step 2: Run the Task 1 test to verify it now passes**

Run: `cd frontend && npx jest --silent src/app/reader/reader-shell.component.spec.ts -t "one scoped refresh reloads the list once"`
Expected: PASS — one entries reload, one tags reload, one subscriptions reload.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts
git commit -m "fix(reader): single reload authority for refresh (#502)"
```

---

### Task 3: Remove the redundant onDone reload from onScopedRefresh

The authority now covers the scoped-refresh reload; drop the `onDone` body so the list is not loaded twice.

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts:817-825` (`onScopedRefresh`)

**Interfaces:**
- Consumes: the single authority from Task 2; `this.refreshScope()` (returns a `RefreshScope` or `null`); `this.refreshSvc.run(onDone?, scope?)`.
- Produces: `onScopedRefresh()` that runs a scoped refresh and leaves the reload to the authority.

- [ ] **Step 1: Rewrite onScopedRefresh**

Replace (currently lines 815–825):

```ts
  /** The list-scoped refresh (header button + mobile pull): sweep only the feeds
   *  behind the current selection, then reload the list once it lands. */
  onScopedRefresh(): void {
    const scope = this.refreshScope();
    if (!scope) return;
    this.refreshSvc.run(() => {
      this.subs.load();
      this.tags.load();
      this.entries.load(queryFromSelection(this.selection()));
    }, scope);
  }
```

with:

```ts
  /** The list-scoped refresh (header button + mobile pull): sweep only the feeds
   *  behind the current selection. The single reload authority (#502) reloads the
   *  list once the run finishes — this path no longer reloads it itself. */
  onScopedRefresh(): void {
    const scope = this.refreshScope();
    if (!scope) return;
    this.refreshSvc.run(undefined, scope);
  }
```

- [ ] **Step 2: Run the scoped-refresh tests to verify they stay green**

Run: `cd frontend && npx jest --silent src/app/reader/reader-shell.component.spec.ts -t "refresh"`
Expected: PASS — including the Task 1 test, the three scope-mapping tests (all-items, tag, subscription), and "does not refresh from the cross-feed saved views".

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts
git commit -m "fix(reader): drop redundant onDone reload from onScopedRefresh (#502)"
```

---

### Task 4: Remove the redundant onDone reload from onRefresh

`onRefresh` (the global, unscoped Refresh path) has the same double; drop its `onDone` body too.

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts:788-794` (`onRefresh`)

**Interfaces:**
- Consumes: the single authority from Task 2; `this.refreshSvc.run(onDone?, scope?)`.
- Produces: `onRefresh()` that runs a global refresh and leaves the reload to the authority.

- [ ] **Step 1: Rewrite onRefresh**

Replace (currently lines 788–794):

```ts
  onRefresh(): void {
    this.refreshSvc.run(() => {
      this.subs.load();
      this.tags.load();
      this.entries.load(queryFromSelection(this.selection()));
    });
  }
```

with:

```ts
  /** The global refresh: sweep every due feed. The single reload authority
   *  (#502) reloads the list once the run finishes. */
  onRefresh(): void {
    this.refreshSvc.run();
  }
```

- [ ] **Step 2: Run the full reader-shell spec to verify nothing regressed**

Run: `cd frontend && npx jest --silent src/app/reader/reader-shell.component.spec.ts`
Expected: PASS — whole file green.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts
git commit -m "fix(reader): drop redundant onDone reload from onRefresh (#502)"
```

---

### Task 5: Remove the redundant onDone reload from onAddFeed

The authority fires for the add-feed run too, so its `onDone` reload must go — otherwise add-feed inherits the double this change removes everywhere else.

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts:877-906` (`onAddFeed`, the unfetched-feed branch at ~898)

**Interfaces:**
- Consumes: the single authority from Task 2; `this.refreshSvc.run(onDone?, scope?)`; `sub.feedId`.
- Produces: `onAddFeed()` whose unfetched-feed branch runs a feed-scoped refresh and leaves the reload to the authority. The immediate `this.subs.load()` and the fetched-feed branch's `this.entries.load(...)` before the run are unchanged.

- [ ] **Step 1: Rewrite the unfetched-feed run in onAddFeed**

Replace (currently lines 898–904):

```ts
      this.refreshSvc.run(
        () => {
          this.subs.load();
          this.entries.load(queryFromSelection(this.selection()));
        },
        { feedId: sub.feedId },
      );
```

with:

```ts
      // The single reload authority (#502) reloads the list once the feed's
      // first fetch finishes — this path no longer reloads it itself.
      this.refreshSvc.run(undefined, { feedId: sub.feedId });
```

- [ ] **Step 2: Run the add-feed test to verify it stays green**

Run: `cd frontend && npx jest --silent src/app/reader/reader-shell.component.spec.ts -t "adding a feed"`
Expected: PASS — "clears q along with the rest when adding a feed selects its subscription (#408)" stays green (it flushes every trailing request with `ctrl.match(() => true)`).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts
git commit -m "fix(reader): drop redundant onDone reload from onAddFeed (#502)"
```

---

### Task 6: Regression-test the onboarding sweep's progressive fill

Prove the second intent still holds: during the post-onboarding sweep the list reloads on each landing slice, not only at the end.

**Files:**
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts` (add a test using the existing `bootWith` helper and `SUBSCRIPTION_FIXTURE`)

**Interfaces:**
- Consumes: `bootWith(subscriptions)` (boots the shell against a custom subscriptions list and drains the initial four requests), `SUBSCRIPTION_FIXTURE` (a subscription row with `lastFetchedAt: null` — a never-fetched feed, which is what makes `awaitingFirstFetch()` true and fires the sweep).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the test**

Add after the Task 1 `describe` block:

```ts
describe('onboarding sweep still fills progressively (#502)', () => {
  it('reloads the list on each landing slice, not only at the end', () => {
    // All subscriptions never fetched → awaitingFirstFetch() is true → the shell
    // fires the post-onboarding sweep itself (sweeping() is true for its span).
    const f = bootWith([{ ...SUBSCRIPTION_FIXTURE, lastFetchedAt: null }]);

    // The sweep's own refresh request.
    const refresh = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');

    // First slice: partial, more feeds still due → the list must reload now.
    refresh.flush({
      status: 'partial',
      total: 2,
      fetched: 1,
      notModified: 0,
      failed: 0,
      skippedForBudget: 0,
      remaining: 1,
      pruned: 0,
    });
    f.detectChanges();
    expect(ctrl.match((r) => r.url === 'https://api.test/api/entries').length).toBe(1);
    ctrl.match((r) => r.url === 'https://api.test/api/entries')[0].flush({ entries: [], nextCursor: null });
    ctrl.match((r) => r.url === 'https://api.test/api/subscriptions').forEach((req) =>
      req.flush({ subscriptions: [{ ...SUBSCRIPTION_FIXTURE, lastFetchedAt: null }] }),
    );
    ctrl.match((r) => r.url === 'https://api.test/api/tags').forEach((req) => req.flush({ tags: [] }));

    // Second slice: another reload lands before the sweep ends (progressive).
    const next = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    next.flush({
      status: 'completed',
      total: 2,
      fetched: 2,
      notModified: 0,
      failed: 0,
      skippedForBudget: 0,
      remaining: 0,
      pruned: 0,
    });
    f.detectChanges();
    expect(ctrl.match((r) => r.url === 'https://api.test/api/entries').length).toBeGreaterThanOrEqual(1);

    // Drain whatever the completing slice queued.
    ctrl.match(() => true).forEach((req) => {
      if (req.request.url.endsWith('/api/entries')) req.flush({ entries: [], nextCursor: null });
      else if (req.request.url.endsWith('/api/subscriptions')) req.flush({ subscriptions: [{ ...SUBSCRIPTION_FIXTURE, lastFetchedAt: '2026-08-21T00:00:00Z' }] });
      else if (req.request.url.endsWith('/api/tags')) req.flush({ tags: [] });
      else req.flush({});
    });
  });
});
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `cd frontend && npx jest --silent src/app/reader/reader-shell.component.spec.ts -t "onboarding sweep still fills progressively"`
Expected: PASS — the list reloads on the first (partial) slice, before the sweep completes.

Note: if the second slice's `/api/refresh` does not appear (the sweep's poll loop is driven by the report `status`/`remaining`), the first-slice assertion is the load-bearing one; keep the test focused on proving the partial slice reloads. Adjust the drain to match the requests actually queued rather than forcing a second refresh.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.spec.ts
git commit -m "test(reader): onboarding sweep still fills progressively (#502)"
```

---

### Task 7: Run the full frontend gate

Confirm the whole suite plus lint/format are green before opening a PR.

**Files:** none (verification only).

**Interfaces:** none.

- [ ] **Step 1: Run the reader specs and the whole Jest suite**

Run: `cd frontend && npx jest --silent src/app/reader/reader-shell.component.spec.ts src/app/reader/refresh.service.spec.ts src/app/reader/entries.store.spec.ts`
Expected: PASS — all three green.

- [ ] **Step 2: Run the full CI gate**

Run: `cd frontend && npm run check`
Expected: PASS — ESLint, Prettier (100-col), Stylelint, and the full Jest suite all green.

- [ ] **Step 3: If Prettier reports formatting, fix and re-run**

Run: `cd frontend && npx prettier --write src/app/reader/reader-shell.component.ts src/app/reader/reader-shell.component.spec.ts && npm run check`
Expected: PASS.

- [ ] **Step 4: Commit any formatting fixup**

```bash
git add -A
git commit -m "style(reader): prettier fixup (#502)" || echo "nothing to format"
```

---

## Self-Review

**Spec coverage:**
- "One user-initiated scoped refresh causes exactly one `entries.load`, verified by a component test" → Task 1 (test) + Task 2 (fix) + Task 3.
- "The onboarding sweep still repopulates progressively as slices land" → Task 2 (preserves per-slice under `sweeping()`) + Task 6 (test).
- "Tags still refresh after a scoped refresh" → Task 2 (`this.tags.load()` in authority) + Task 1 (asserts one `/api/tags`).
- "No second reload authority remains for the manual-refresh path" → Tasks 3, 4, 5 (remove `onDone` reloads at all three shell call sites).
- Constraint "`onDone` stays on `RefreshService`" → no task touches `refresh.service.ts`; the settings paths are untouched.

**Placeholder scan:** no TBD/TODO; every code step shows the exact before/after. Task 6 Step 2 carries a documented fallback rather than a placeholder — the assertion of record (partial slice reloads) is concrete.

**Type consistency:** the authority reads `refreshSvc.slice()`, `refreshSvc.running()`, `sweeping()`, and calls `subs.load()`, `tags.load()`, `entries.load(queryFromSelection(selection()))` — all names verified against the current source. `run(undefined, scope)` matches `run(onDone?, scope?)`. Report shapes in tests match the `refreshDone` fixture (`status`, `total`, `fetched`, `notModified`, `failed`, `skippedForBudget`, `remaining`, `pruned`).
