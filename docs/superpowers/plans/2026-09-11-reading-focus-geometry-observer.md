# Reading-focus Geometry Observer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hand-enumerated reading-focus dim triggers with one shared, geometry-observing `ReadingFocusApplier`, so the dim stays correct across viewport reflows (orientation included) without ever naming the cause.

**Architecture:** A framework-agnostic `ReadingFocusApplier` class owns the dim recompute for one scroller. It recomputes on its own scroll listener plus a multi-target `ResizeObserver` (the scroller + each current block). The list and the article components each construct one, feed it their scroller / block source / curve, and push the two non-geometric inputs (enable gate, block-set change) in via `refresh()` / `clear()`.

**Tech Stack:** Angular 20 (standalone + signals), TypeScript, Jest (jsdom), Playwright.

**Spec:** `docs/superpowers/specs/2026-09-11-reading-focus-geometry-observer-spec.md` (issue #982)

## Global Constraints

- Angular 20 standalone + signals; no NgModules. Node 22.
- `ReadingFocusApplier` has **no Angular imports** — a plain class, unit-tested without TestBed.
- `npm run check` (ESLint + Prettier 100-col + Stylelint + Jest) is the gate; run frontend tests in the container: `docker compose exec -T frontend npm test`.
- jsdom has **no** `ResizeObserver` and **no** layout. Tests install a mock `ResizeObserver`, drive its callback by hand, and assert on the inline `style.opacity` string each pass writes (`""` = untouched, the #462 sentinel; a touched block scores `"1"` because `clientHeight` is `0`).
- Component styles stay in sibling `.scss`. No behaviour change to Mechanism B (scroll restore) or to `reading-focus.ts` math.
- Branch `feature/982-reading-focus-geometry-observer` off `develop`. Commit format `type(#982): …`.

---

### Task 1: `ReadingFocusApplier` class

**Files:**
- Create: `frontend/src/app/reader/reading-focus-applier.ts`
- Test: `frontend/src/app/reader/reading-focus-applier.spec.ts`

**Interfaces:**
- Consumes: `focusOpacityForSpan`, `FocusCurve` from `./reading-focus`.
- Produces:
  - `interface ReadingFocusConfig { scroller: HTMLElement; blocks: () => HTMLElement[]; curve: FocusCurve; isActive: () => boolean; runOutsideZone?: <T>(run: () => T) => T; }`
  - `class ReadingFocusApplier { constructor(config: ReadingFocusConfig); refresh(): void; clear(): void; destroy(): void; }`

- [ ] **Step 1: Write the test scaffolding + mock `ResizeObserver`**

Create `reading-focus-applier.spec.ts`:

```ts
import { ReadingFocusApplier } from './reading-focus-applier';
import { LIST_FOCUS_CURVE } from './reading-focus';

class MockResizeObserver {
  static instances: MockResizeObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(readonly callback: ResizeObserverCallback) {
    MockResizeObserver.instances.push(this);
  }
  observe(t: Element): void {
    this.targets.add(t);
  }
  unobserve(t: Element): void {
    this.targets.delete(t);
  }
  disconnect(): void {
    this.targets.clear();
  }
  fire(): void {
    this.callback([], this as unknown as ResizeObserver);
  }
}

const frames = (): Promise<void> =>
  new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r())));

function scrollerWith(count: number): { scroller: HTMLElement; blocks: () => HTMLElement[] } {
  const scroller = document.createElement('div');
  for (let i = 0; i < count; i++) scroller.appendChild(document.createElement('article'));
  const blocks = (): HTMLElement[] => Array.from(scroller.children) as HTMLElement[];
  return { scroller, blocks };
}

const opacities = (blocks: () => HTMLElement[]): string[] => blocks().map((b) => b.style.opacity);
const blank = (blocks: () => HTMLElement[]): void => blocks().forEach((b) => (b.style.opacity = ''));

let observer: () => MockResizeObserver;

beforeEach(() => {
  MockResizeObserver.instances = [];
  (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = MockResizeObserver;
  observer = () => MockResizeObserver.instances[MockResizeObserver.instances.length - 1];
});
```

- [ ] **Step 2: Write the failing "initial pass" test**

```ts
it('runs an initial pass on construction when active', async () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({ scroller, blocks, curve: LIST_FOCUS_CURVE, isActive: () => true });
  await frames();
  expect(opacities(blocks)).not.toContain('');
  applier.destroy();
});
```

- [ ] **Step 3: Run it, verify it fails**

Run: `docker compose exec -T frontend npx jest reading-focus-applier -t "initial pass"`
Expected: FAIL — module not found / `ReadingFocusApplier is not a constructor`.

- [ ] **Step 4: Implement `ReadingFocusApplier`**

Create `reading-focus-applier.ts`:

```ts
import { type FocusCurve, focusOpacityForSpan } from './reading-focus';

export interface ReadingFocusConfig {
  readonly scroller: HTMLElement;
  readonly blocks: () => HTMLElement[];
  readonly curve: FocusCurve;
  /** enabled && !isWide && !reduceMotion — read live, off the reactive graph. */
  readonly isActive: () => boolean;
  readonly runOutsideZone?: <T>(run: () => T) => T;
}

/**
 * Keeps each block's inline opacity in step with its distance from the scroll
 * centre. It observes the geometry it reads — the scroller (viewport) and every
 * block (reflow) — plus scroll, so it never enumerates the causes of a layout
 * change. The two inputs with no geometric signature (the enable gate and the
 * block set changing) are pushed in by the owner via refresh()/clear().
 */
export class ReadingFocusApplier {
  private readonly runOutsideZone: <T>(run: () => T) => T;
  private readonly observer?: ResizeObserver;
  private readonly onScroll = (): void => this.schedule();
  private frame = 0;

  constructor(private readonly config: ReadingFocusConfig) {
    this.runOutsideZone = config.runOutsideZone ?? ((run) => run());
    config.scroller.addEventListener('scroll', this.onScroll, { passive: true });
    if (typeof ResizeObserver !== 'undefined') {
      this.observer = new ResizeObserver(() => this.schedule());
    }
    this.observe();
    this.schedule();
  }

  /** Re-sync the observed targets to the current blocks, then schedule a pass. */
  refresh(): void {
    this.observe();
    this.schedule();
  }

  /** Blank every block now and drop a pending pass — a disable must clear the
   *  same tick, not a frame later. */
  clear(): void {
    this.cancel();
    for (const block of this.config.blocks()) block.style.opacity = '';
  }

  destroy(): void {
    this.cancel();
    this.observer?.disconnect();
    this.config.scroller.removeEventListener('scroll', this.onScroll);
  }

  private observe(): void {
    if (!this.observer) return;
    this.observer.disconnect();
    this.observer.observe(this.config.scroller);
    for (const block of this.config.blocks()) this.observer.observe(block);
  }

  private schedule(): void {
    if (this.frame || typeof requestAnimationFrame === 'undefined') return;
    this.frame = this.runOutsideZone(() =>
      requestAnimationFrame(() => {
        this.frame = 0;
        this.recompute();
      }),
    );
  }

  private cancel(): void {
    if (this.frame && typeof cancelAnimationFrame !== 'undefined') cancelAnimationFrame(this.frame);
    this.frame = 0;
  }

  private recompute(): void {
    const { scroller, blocks, curve, isActive } = this.config;
    if (!isActive()) {
      for (const block of blocks()) block.style.opacity = '';
      return;
    }
    const viewport = scroller.clientHeight;
    const scrollerTop = scroller.getBoundingClientRect().top;
    for (const block of blocks()) {
      const rect = block.getBoundingClientRect();
      const top = rect.top - scrollerTop;
      block.style.opacity = String(focusOpacityForSpan(top, top + rect.height, viewport, curve));
    }
  }
}
```

- [ ] **Step 5: Run the initial-pass test, verify it passes**

Run: `docker compose exec -T frontend npx jest reading-focus-applier -t "initial pass"`
Expected: PASS.

- [ ] **Step 6: Add the remaining behaviour tests**

Append to the spec:

```ts
it('clears every block when inactive', async () => {
  const { scroller, blocks } = scrollerWith(3);
  blocks().forEach((b) => (b.style.opacity = '0.5'));
  const applier = new ReadingFocusApplier({ scroller, blocks, curve: LIST_FOCUS_CURVE, isActive: () => false });
  await frames();
  expect(opacities(blocks)).toEqual(['', '', '']);
  applier.destroy();
});

it('clear() blanks synchronously', () => {
  const { scroller, blocks } = scrollerWith(2);
  const applier = new ReadingFocusApplier({ scroller, blocks, curve: LIST_FOCUS_CURVE, isActive: () => true });
  blocks().forEach((b) => (b.style.opacity = '1'));
  applier.clear();
  expect(opacities(blocks)).toEqual(['', '']);
  applier.destroy();
});

it('recomputes on a scroll event', async () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({ scroller, blocks, curve: LIST_FOCUS_CURVE, isActive: () => true });
  await frames();
  blank(blocks);
  scroller.dispatchEvent(new Event('scroll'));
  await frames();
  expect(opacities(blocks)).not.toContain('');
  applier.destroy();
});

it('recomputes when the ResizeObserver fires (orientation/reflow)', async () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({ scroller, blocks, curve: LIST_FOCUS_CURVE, isActive: () => true });
  await frames();
  blank(blocks);
  observer().fire();
  await frames();
  expect(opacities(blocks)).not.toContain('');
  applier.destroy();
});

it('observes the scroller and every block', () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({ scroller, blocks, curve: LIST_FOCUS_CURVE, isActive: () => true });
  expect(observer().targets.has(scroller)).toBe(true);
  for (const b of blocks()) expect(observer().targets.has(b)).toBe(true);
  applier.destroy();
});

it('refresh() re-observes newly added blocks', async () => {
  const { scroller, blocks } = scrollerWith(2);
  const applier = new ReadingFocusApplier({ scroller, blocks, curve: LIST_FOCUS_CURVE, isActive: () => true });
  const added = document.createElement('article');
  scroller.appendChild(added);
  applier.refresh();
  await frames();
  expect(observer().targets.has(added)).toBe(true);
  expect(added.style.opacity).not.toBe('');
  applier.destroy();
});

it('stops recomputing after destroy()', async () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({ scroller, blocks, curve: LIST_FOCUS_CURVE, isActive: () => true });
  await frames();
  applier.destroy();
  blank(blocks);
  scroller.dispatchEvent(new Event('scroll'));
  observer().fire();
  await frames();
  expect(opacities(blocks)).toEqual(['', '', '']);
});
```

- [ ] **Step 7: Run the full applier spec, verify all pass**

Run: `docker compose exec -T frontend npx jest reading-focus-applier`
Expected: PASS (8 tests).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/reading-focus-applier.ts frontend/src/app/reader/reading-focus-applier.spec.ts
git commit -m "feat(#982): add geometry-observing ReadingFocusApplier"
```

---

### Task 2: Wire the applier into the entry list

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html` (remove the `animationend` binding)
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `ReadingFocusApplier`, `ReadingFocusConfig` (Task 1); `LIST_FOCUS_CURVE`.

- [ ] **Step 1: Install the mock `ResizeObserver` in the component spec**

At the top of `entry-list.component.spec.ts`, add the same `MockResizeObserver` class as Task 1, and in the outer `describe('EntryListComponent')` `beforeEach` (next to `localStorage.clear()`):

```ts
beforeEach(() => {
  localStorage.clear();
  MockResizeObserver.instances = [];
  (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = MockResizeObserver;
});
```

Add a helper to reach the list applier's observer (the last instance whose targets include the `.rows` element) for the reflow tests:

```ts
function fireRowsResize(f: ComponentFixture<EntryListComponent>): void {
  const rows = (f.nativeElement as HTMLElement).querySelector('.rows')!;
  const obs = MockResizeObserver.instances.find((o) => o.targets.has(rows));
  obs?.fire();
}
```

- [ ] **Step 2: Run the reading-focus describe to see the baseline pass**

Run: `docker compose exec -T frontend npx jest entry-list.component -t "reading focus"`
Expected: PASS (still on the old implementation — confirms the mock RO didn't break anything).

- [ ] **Step 3: Replace the focus internals in the component**

In `entry-list.component.ts`:

1. Remove the window-`resize` block in the constructor (`:419-425`).
2. Delete `private focusRaf = 0;` (`:433`).
3. Replace the `_readingFocus` effect (`:493-526`) with a bind effect and a refresh effect:

```ts
private applier?: ReadingFocusApplier;

// (Re)build the applier when the scroller element appears or swaps (skeleton ->
// list, list <-> magazine). Its constructor runs the first pass and starts
// observing; nothing here enumerates the causes of a later geometry change.
private readonly _bindReadingFocus = effect(() => {
  const scroller = this.rows()?.nativeElement;
  this.applier?.destroy();
  this.applier = undefined;
  if (!scroller) return;
  this.applier = new ReadingFocusApplier({
    scroller,
    blocks: () =>
      (Array.from(scroller.children) as HTMLElement[]).filter(
        (child) => !child.classList.contains('foot'),
      ),
    curve: LIST_FOCUS_CURVE,
    isActive: () => this.readingFocus.enabled() && !this.screen.isWide() && !this.reduceMotion,
    runOutsideZone: (run) => this.zone.runOutsideAngular(run),
  });
});

// The two inputs with no geometric signature: the enable gate, and the block
// set changing (a finished load, a load-more append, a view switch whose
// retained outgoing rows (#254) must re-fade before the new page lands (#462)).
private readonly _pushReadingFocus = effect(() => {
  const enabled = this.readingFocus.enabled();
  this.entries();
  this.selection();
  const applier = this.applier;
  if (!applier) return;
  if (enabled) applier.refresh();
  else applier.clear();
});
```

4. Delete `scheduleFocus` (`:551-562`), `applyFocus` (`:571-592`), `clearFocus` (`:594-598`), and `onContentSettled` (`:564-569`).
5. In `onRowsScroll`, remove the `this.scheduleFocus();` line (`:537`).
6. In `scrollToTop`, the `cancelSettle()` and scroll stay; no focus call was there — leave as is.
7. Add teardown next to the other `destroyRef.onDestroy` calls:

```ts
this.destroyRef.onDestroy(() => this.applier?.destroy());
```

8. Add the import: `import { ReadingFocusApplier } from '../reading-focus-applier';` (adjust the relative path to the reader root).

In `entry-list.component.html`: remove the `(animationend)="onContentSettled($event)"` binding on the `.rows` element (it is the only caller of the deleted method).

- [ ] **Step 4: Update the two geometry-driven tests to fire the observer**

In `entry-list.component.spec.ts`, the density and collapse cases are now geometry, caught by the observer. Replace their triggers:

`recomputes focus when a row-collapse animation settles` (`:1700`) — replace the `rows.dispatchEvent(animationEnd('row-leave'))` line with `fireRowsResize(f);` and drop the now-unused `animationEnd`/`blankOpacities` wiring only if no other test uses them (the #462 test still uses `blankOpacities`, so keep it; `animationEnd` and the `ignores an unrelated animation` test are deleted — the applier has no animation path).

`recomputes focus when the magazine density switches boxed <-> airy` (`:1735`) — after `TestBed.inject(MagazineStyleService).set('airy'); f.detectChanges();`, replace the implicit signal trigger with an explicit reflow: add `fireRowsResize(f);` before `await frames();`.

Delete `ignores an unrelated animation ending in the scroller` (`:1710-1719`) — there is no longer an animation code path to guard.

- [ ] **Step 5: Run the full entry-list spec**

Run: `docker compose exec -T frontend npx jest entry-list.component`
Expected: PASS. Fix any test that still reaches for a deleted method.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/entry-list/entry-list.component.ts frontend/src/app/reader/entry-list/entry-list.component.html frontend/src/app/reader/entry-list/entry-list.component.spec.ts
git commit -m "refactor(#982): drive list reading focus from the shared applier"
```

---

### Task 3: Wire the applier into the article view

**Files:**
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts`
- Test: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`

**Interfaces:**
- Consumes: `ReadingFocusApplier` (Task 1); `ARTICLE_FOCUS_CURVE`, `readingBlocks`.

- [ ] **Step 1: Install the mock `ResizeObserver` in the reader-view spec**

Add the `MockResizeObserver` class and set it on `globalThis.ResizeObserver` in the spec's `beforeEach`, mirroring Task 2 Step 1. Note the article already creates a `ResizeObserver` for `measureScrollRange` (`contentObs`); the mock covers both — neither auto-fires, matching jsdom today.

- [ ] **Step 2: Replace the focus internals**

In `reader-view.component.ts`:

1. Replace the enable effect (`:351-359`) and add a bind effect. Bind on `content()` (the scroller is the always-present host; blocks come from `.content`, whose identity is stable across `innerHTML` renders):

```ts
private applier?: ReadingFocusApplier;

effect(() => {
  const content = this.content()?.nativeElement;
  this.applier?.destroy();
  this.applier = undefined;
  if (!content) return;
  this.applier = new ReadingFocusApplier({
    scroller: this.host.nativeElement,
    blocks: () => readingBlocks(content),
    curve: ARTICLE_FOCUS_CURVE,
    isActive: () => this.readingFocus.enabled() && !this.screen.isWide() && !this.reduceMotion,
    // No runOutsideZone: the article view historically ran its focus rAF in-zone.
  });
});

effect(() => {
  if (this.readingFocus.enabled()) this.applier?.refresh();
  else this.applier?.clear();
});
```

2. In the render effect (`:363-400`), remove `this.scheduleFocus();` (`:394`) and add `this.applier?.refresh();` in its place — a new render is a block-set change.
3. In the resize handler (`:405-408`), remove `this.scheduleFocus();`, keep `this.measureScrollRange();`.
4. In `onScroll` (`:559-580`), remove `this.scheduleFocus();` (`:561`) — the applier owns a scroll listener on the host.
5. Delete `scheduleFocus` (`:648-655`), `applyFocus` (`:660-680`), `clearFocus` (`:682-686`), and the `focusRaf` field. Keep `contentObs`, `measureScrollRange`, `startRestore`, and the rest untouched.
6. Add teardown: `this.destroyRef.onDestroy(() => this.applier?.destroy());`
7. Add the import for `ReadingFocusApplier`.

- [ ] **Step 3: Update the reader-view focus spec**

The `describe('reading focus setting')` (`:88`) seeds `block.style.opacity` then asserts it clears on disable and returns on enable. Keep the assertions; where a test relied on the old `scheduleFocus` firing from a specific trigger, drive the applier the same way the component now does: enable/disable via `ReadingFocusService`, a render via the existing render path, and a reflow via the mock observer's `fire()`. Assert on `readingBlocks(content)` opacities as before.

- [ ] **Step 4: Run the reader-view spec**

Run: `docker compose exec -T frontend npx jest reader-view.component`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-view/reader-view.component.ts frontend/src/app/reader/reader-view/reader-view.component.spec.ts
git commit -m "refactor(#982): drive article reading focus from the shared applier"
```

---

### Task 4: Playwright smoke — dim survives a rotation

**Files:**
- Create: `frontend/e2e/reading-focus-orientation.spec.ts`

**Interfaces:** none — black-box against the stubbed list route.

- [ ] **Step 1: Write the smoke**

The spec owns its data (stub the entries route; see `frontend/e2e/magazine-kicker-one-line.spec.ts` for the stubbing pattern). Load the narrow list with reading focus on, read a mid-list row's opacity, resize the viewport from portrait to landscape, and assert the row's opacity is re-applied (a real number, and consistent with the new centre — not the stale portrait value).

```ts
import { test, expect } from '@playwright/test';

test('reading-focus dim re-applies after an orientation change', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 }); // portrait phone
  // …stub the list route with enough entries to overflow, sign in, open the list…
  const rows = page.locator('.rows > *:not(.foot)');
  await expect(rows.first()).toHaveJSProperty('style.opacity', /.+/); // touched
  const before = await rows.nth(3).evaluate((el) => (el as HTMLElement).style.opacity);

  await page.setViewportSize({ width: 844, height: 390 }); // landscape
  await expect
    .poll(async () => rows.nth(3).evaluate((el) => (el as HTMLElement).style.opacity))
    .not.toBe(before); // the centre moved; the dim recomputed against the new viewport
});
```

Follow `docs/` guidance on the e2e harness; do not resize mid-test in ways the memory notes forbid for *component* Playwright — this is a top-level viewport change between assertions, which the smoke explicitly needs.

- [ ] **Step 2: Run it against the Docker stack**

Run: `cd frontend && npm run e2e -- reading-focus-orientation`
Expected: PASS. (Needs the stack up.)

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/reading-focus-orientation.spec.ts
git commit -m "test(#982): prove the reading-focus dim survives a rotation"
```

---

### Task 5: Verify and open the PR

- [ ] **Step 1: Full frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all green. Fix anything.

- [ ] **Step 2: Grep for orphans**

Run: `git grep -nE "scheduleFocus|applyFocus|clearFocus|onContentSettled|focusRaf" -- frontend/src/app/reader`
Expected: no matches outside the applier. Any hit is a missed deletion.

- [ ] **Step 3: Manual check in the running app**

Open the list on the narrow layout with reading focus on. Scroll to mid-list, rotate portrait↔landscape (or resize), confirm the dim tracks the new centre with no stale band and no first-scroll "repair". Repeat in the article view.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin feature/982-reading-focus-geometry-observer
```

Open a PR into `develop` whose body says `Closes #982`. After merge, verify the issue closed.

---

## Self-Review

**Spec coverage:**
- Observe geometry (scroller + blocks) → Task 1 (`observe()`, RO on scroller + blocks) + tests.
- Scroll listener retained → Task 1 (`onScroll`) + "recomputes on a scroll event".
- Two non-geometric inputs stay explicit → Task 2 `_pushReadingFocus`, Task 3 enable effect + render refresh.
- Deletes (resize listener, density trigger, collapse `animationend`, per-component focus methods) → Task 2 Step 3, Task 3 Step 2, verified by Task 5 Step 2 grep.
- Synchronous clear on disable → Task 1 `clear()` + "clear() blanks synchronously"; wired in Task 2/3 enable effects.
- jsdom mock RO + `style.opacity` sentinel → Task 1 mock, Task 2/3 Step 1.
- Playwright orientation proof → Task 4.
- Mechanism B / fade math untouched → not modified in any task.

**Placeholder scan:** every code step carries real code; the only prose-only steps are the manual check (Task 5 Step 3) and the reader-view spec adjustment (Task 3 Step 3), which name the exact triggers to drive.

**Type consistency:** `ReadingFocusApplier` / `ReadingFocusConfig` / `refresh()` / `clear()` / `destroy()` are used identically in Tasks 1–3. `isActive` reads `enabled && !isWide && !reduceMotion` everywhere. `blocks()` returns `HTMLElement[]` in both call sites.
