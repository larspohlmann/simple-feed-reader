# #2038 Phase 1 — the `ScrollHost` seam — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every reader scroll consumer reads its scroller through a `ScrollHost` instead of a raw `HTMLElement`, with **no behaviour change** — the seam phase 2 plugs a page host into.

**Architecture:** A `ScrollHost` interface mirrors the Element scroll API (`scrollTop`, `clientHeight`, `scrollHeight`, `scrollTo`) so geometry helpers such as `atBottom()` take it unchanged, and adds what consumers otherwise read off the element: `viewportTop()`, `scrollPaddingTop()`, `content`, `intersectionRoot`, `listen()`, `watchResize()`. `ElementScrollHost` wraps today's element; a root `ScrollHosts` service builds it with the zone wiring (#501). The list and the article layer each expose one memoised host; consumers take `() => ScrollHost | undefined`.

**Tech Stack:** Angular 20 (standalone, signals, zone.js with `provideZoneChangeDetection`), Jest (jsdom), TypeScript strict.

**Spec:** `docs/superpowers/specs/2026-11-05-2038-page-scroll-on-narrow-design.md` (§1 is this phase; read §2–§5 for why the interface has the members it has).

## Global Constraints

- No behaviour change in this phase: every existing spec keeps its meaning; edits to specs are type adaptations only (wrapping an element in a host, a changed handler signature).
- Scroll listeners stay outside the Angular zone (#501) — `ScrollHost.listen()` guarantees it.
- Frontend tests in this worktree run with the **local** jest binary: `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" <paths>` from `frontend/` (never bare `npx jest`; Docker's frontend container serves the main checkout, not this worktree).
- Commit format `type(#2038): …`; no Claude attribution anywhere.
- Comments: default to none; one line when a reader would otherwise get the code wrong.
- Before the PR: run `/simplify`, then `npm run check` from `frontend/`.

## File map

| File | Change |
|---|---|
| `frontend/src/app/shared/scroll-host/scroll-host.ts` | **new** — `ScrollHost` interface, `ElementScrollHost` |
| `frontend/src/app/shared/scroll-host/scroll-hosts.service.ts` | **new** — root service building hosts with the zone |
| `frontend/src/app/shared/scroll-host/scroll-host.spec.ts` | **new** |
| `reader/article/reading/reading-focus-applier.ts` (+spec) | `scroller: ScrollHost`; listens and watches resize through it |
| `reader/list/entry-list/list-reading-focus.ts` | passes the list host |
| `reader/article/article-layer/article-layer.component.{ts,html}` (+spec unchanged) | `scroller` is a host; listens through it |
| `reader/article/article-layer/article-gestures.service.ts` | host type |
| `reader/article/reading/article-scroll-restore.service.ts` | host type |
| `reader/article/reading/reading-scope.service.ts` (+spec) | host type; `bottomWithin` via `viewportTop()` |
| `reader/article/article-page/article-page.component.ts` | `scrollToElement` via `viewportTop()`/`scrollPaddingTop()` |
| `reader/list/entry-list/entry-list.component.{ts,html}` (+spec) | `scrollHost` computed; IO roots; drops `appScrollOutsideZone` |
| `reader/list/entry-list/list-scroll-state.ts` | host; listens itself; `onScroll(top)` |
| `reader/list/entry-list/pull-to-refresh.ts` | host |
| `reader/list/entry-list/list-above-fold.ts` | host |
| `reader/media/near-viewport-media.ts` | `scrollWithin(root: Element \| null)` |
| `shared/progress-rail/scroll-progress-rail.ts` (+ `progress-rail.component.spec.ts`) | takes `scroller` option; listens/watches through it |
| `shared/progress-rail/scroll-progress.directive.ts` | **deleted** (templates bind `.scrollbar-hidden` directly) |
| `reader/list/entry-list/estimated-list-bottom.ts` | `measure(host)` |

All `reader/…`/`shared/…` paths are under `frontend/src/app/`.

---

### Task 1: `ScrollHost`, `ElementScrollHost`, `ScrollHosts`

**Files:**
- Create: `frontend/src/app/shared/scroll-host/scroll-host.ts`
- Create: `frontend/src/app/shared/scroll-host/scroll-hosts.service.ts`
- Test: `frontend/src/app/shared/scroll-host/scroll-host.spec.ts`

**Interfaces:**
- Produces: `interface ScrollHost`, `class ElementScrollHost implements ScrollHost` (`constructor(content: HTMLElement, outsideZone: <T>(run: () => T) => T)`), `@Injectable({providedIn:'root'}) class ScrollHosts { forElement(element: HTMLElement): ScrollHost }`.

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/app/shared/scroll-host/scroll-host.spec.ts
import { NgZone } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ElementScrollHost } from './scroll-host';
import { ScrollHosts } from './scroll-hosts.service';
import { trackScrollTop } from '../../../testing/scroll-testing';
import {
  MockResizeObserver,
  installMockResizeObserver,
} from '../../../testing/resize-observer-testing';

const inZone = <T>(run: () => T): T => run();

describe('ElementScrollHost', () => {
  it('reads and writes the element scroll offset', () => {
    const element = document.createElement('div');
    trackScrollTop(element);
    const host = new ElementScrollHost(element, inZone);
    host.scrollTop = 120;
    expect(element.scrollTop).toBe(120);
    expect(host.scrollTop).toBe(120);
  });

  it('reports the element as content and as the intersection root', () => {
    const element = document.createElement('div');
    const host = new ElementScrollHost(element, inZone);
    expect(host.content).toBe(element);
    expect(host.intersectionRoot).toBe(element);
  });

  it('measures from the element box', () => {
    const element = document.createElement('div');
    Object.defineProperty(element, 'clientHeight', { value: 600 });
    Object.defineProperty(element, 'scrollHeight', { value: 2400 });
    element.getBoundingClientRect = () => ({ top: 56 }) as DOMRect;
    element.style.scrollPaddingTop = '44px';
    const host = new ElementScrollHost(element, inZone);
    expect(host.clientHeight).toBe(600);
    expect(host.scrollHeight).toBe(2400);
    expect(host.viewportTop()).toBe(56);
    expect(host.scrollPaddingTop()).toBe(44);
  });

  it('delegates scrollTo to the element at call time', () => {
    const element = document.createElement('div');
    const host = new ElementScrollHost(element, inZone);
    const scrollTo = jest.fn();
    element.scrollTo = scrollTo as unknown as typeof element.scrollTo;
    host.scrollTo({ top: 0, behavior: 'auto' });
    expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
  });

  it('listens to element scroll events until removed', () => {
    const element = document.createElement('div');
    const listener = jest.fn();
    const stop = new ElementScrollHost(element, inZone).listen(listener);
    element.dispatchEvent(new Event('scroll'));
    stop();
    element.dispatchEvent(new Event('scroll'));
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('watches the element size until removed', () => {
    installMockResizeObserver();
    const element = document.createElement('div');
    const listener = jest.fn();
    const stop = new ElementScrollHost(element, inZone).watchResize(listener);
    const observer = MockResizeObserver.instances.at(-1)!;
    expect(observer.targets.has(element)).toBe(true);
    observer.fire();
    stop();
    expect(listener).toHaveBeenCalledTimes(1);
    expect(observer.targets.size).toBe(0);
  });
});

describe('ScrollHosts', () => {
  it('registers scroll listeners outside the Angular zone (#501)', () => {
    const zone = TestBed.inject(NgZone);
    const element = document.createElement('div');
    const zones: boolean[] = [];
    zone.run(() =>
      TestBed.inject(ScrollHosts)
        .forElement(element)
        .listen(() => zones.push(NgZone.isInAngularZone())),
    );
    element.dispatchEvent(new Event('scroll'));
    expect(zones).toEqual([false]);
  });
});
```

- [ ] **Step 2: Run it to see it fail**

Run (from `frontend/`): `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/shared/scroll-host`
Expected: FAIL — `Cannot find module './scroll-host'`.

- [ ] **Step 3: Implement**

```ts
// frontend/src/app/shared/scroll-host/scroll-host.ts
/** Where a pane scrolls: its own element, or the page (#2038). Mirrors the Element
 *  scroll API so geometry helpers such as `atBottom` take either. */
export interface ScrollHost {
  scrollTop: number;
  readonly clientHeight: number;
  readonly scrollHeight: number;
  scrollTo(options: ScrollToOptions): void;
  /** The viewport y at which the scroll area begins. */
  viewportTop(): number;
  /** The band at the top that chrome covers, from `scroll-padding-top`. */
  scrollPaddingTop(): number;
  /** The scrolled content: rows are queried and touches heard here. */
  readonly content: HTMLElement;
  /** The IntersectionObserver root: the scroller, or null for the viewport. */
  readonly intersectionRoot: HTMLElement | null;
  /** A passive listener, outside the Angular zone (#501); returns its removal. */
  listen(listener: () => void): () => void;
  /** Calls back when the visible box changes size; returns its removal. */
  watchResize(listener: () => void): () => void;
}

export class ElementScrollHost implements ScrollHost {
  constructor(
    readonly content: HTMLElement,
    private readonly outsideZone: <T>(run: () => T) => T,
  ) {}

  get intersectionRoot(): HTMLElement {
    return this.content;
  }

  get scrollTop(): number {
    return this.content.scrollTop;
  }

  set scrollTop(top: number) {
    this.content.scrollTop = top;
  }

  get clientHeight(): number {
    return this.content.clientHeight;
  }

  get scrollHeight(): number {
    return this.content.scrollHeight;
  }

  scrollTo(options: ScrollToOptions): void {
    this.content.scrollTo(options);
  }

  viewportTop(): number {
    return this.content.getBoundingClientRect().top;
  }

  scrollPaddingTop(): number {
    return parseFloat(getComputedStyle(this.content).scrollPaddingTop) || 0;
  }

  listen(listener: () => void): () => void {
    const content = this.content;
    this.outsideZone(() => content.addEventListener('scroll', listener, { passive: true }));
    return () => content.removeEventListener('scroll', listener);
  }

  watchResize(listener: () => void): () => void {
    if (typeof ResizeObserver === 'undefined') return () => undefined;
    const observer = new ResizeObserver(() => listener());
    observer.observe(this.content);
    return () => observer.disconnect();
  }
}
```

```ts
// frontend/src/app/shared/scroll-host/scroll-hosts.service.ts
import { Injectable, NgZone, inject } from '@angular/core';
import { ElementScrollHost, ScrollHost } from './scroll-host';

@Injectable({ providedIn: 'root' })
export class ScrollHosts {
  private readonly zone = inject(NgZone);

  forElement(element: HTMLElement): ScrollHost {
    return new ElementScrollHost(element, (run) => this.zone.runOutsideAngular(run));
  }
}
```

- [ ] **Step 4: Run the test to see it pass**

Run: `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/shared/scroll-host`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/scroll-host
git commit -m "feat(#2038): a ScrollHost seam over a pane's scroller"
```

---

### Task 2: The article pane and the focus applier read a `ScrollHost`

The article layer's `scroller` becomes a host, which moves every article consumer and the shared `ReadingFocusApplier` at once (the type flows through all of them). The list side of the applier comes along through `ListReadingFocus`, which gets the list host from a new `scrollHost` computed on `EntryListComponent`; the list's other consumers keep the element accessor until Task 3.

**Files:**
- Modify: `reader/article/reading/reading-focus-applier.ts`, `reading-focus-applier.spec.ts`
- Modify: `reader/list/entry-list/list-reading-focus.ts`
- Modify: `reader/list/entry-list/entry-list.component.ts` (add `scrollHost`, feed `ListReadingFocus`)
- Modify: `reader/article/article-layer/article-layer.component.ts`, `.html`
- Modify: `reader/article/article-layer/article-gestures.service.ts`
- Modify: `reader/article/reading/article-scroll-restore.service.ts`
- Modify: `reader/article/reading/reading-scope.service.ts`, `reading-scope.service.spec.ts`
- Modify: `reader/article/article-page/article-page.component.ts`

**Interfaces:**
- Consumes: `ScrollHost`, `ElementScrollHost`, `ScrollHosts.forElement` (Task 1).
- Produces: `ReadingFocusConfig.scroller: ScrollHost`; `ArticleLayerComponent.scroller: Signal<ScrollHost | undefined>`; `EntryListComponent`'s private `scrollHost: Signal<ScrollHost | undefined>` (Task 3 and 4 use it); `ReadingScopeParts.scroller: Signal<ScrollHost | undefined>`; `ArticleGestureHost.scroller: Signal<ScrollHost | undefined>`; `ArticleScrollRestore.connect(scroller: Signal<ScrollHost | undefined>)`.

- [ ] **Step 1: Adapt the applier spec to a host (it now fails to compile)**

In `reading-focus-applier.spec.ts`, import the host and wrap the element in `scrollerWith`; every test then passes `scroller: host` while still dispatching events on the element:

```ts
import { ElementScrollHost, ScrollHost } from '../../../shared/scroll-host/scroll-host';

function scrollerWith(count: number): {
  element: HTMLElement;
  scroller: ScrollHost;
  blocks: () => HTMLElement[];
} {
  const element = document.createElement('div');
  for (let index = 0; index < count; index++)
    element.appendChild(document.createElement('article'));
  const blocks = (): HTMLElement[] => Array.from(element.children) as HTMLElement[];
  return { element, scroller: new ElementScrollHost(element, (run) => run()), blocks };
}
```

Then, in the tests:
- `'recomputes on a scroll event'`: destructure `element` too and dispatch on it: `element.dispatchEvent(new Event('scroll'));`.
- `'observes the scroller and every block'`: the scroller is now watched by the host's own observer, created first; the applier's observer (the last instance) holds the blocks:

```ts
it('watches the scroller and observes every block', () => {
  const { element, scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  expect(MockResizeObserver.instances[0].targets.has(element)).toBe(true);
  for (const block of blocks()) expect(observer().targets.has(block)).toBe(true);
  applier.destroy();
});
```
- `'refresh() re-observes newly added blocks'`: append to `element`, not `scroller`.
- Any other test that touches the element directly (`scroller.appendChild`, `scroller.getBoundingClientRect = …`, `Object.defineProperty(scroller, …)`): use `element`. Grep the file for `scroller.` after the edit; only `scroller,` in option objects may remain.

Run: `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/reader/article/reading/reading-focus-applier`
Expected: FAIL (type errors: `scroller` is `HTMLElement` in `ReadingFocusConfig`).

- [ ] **Step 2: Move the applier onto the host**

In `reading-focus-applier.ts`:

```ts
import type { ScrollHost } from '../../../shared/scroll-host/scroll-host';

export interface ReadingFocusConfig {
  readonly scroller: ScrollHost;
  // …blocks, curve, isActive, runOutsideZone, units unchanged
}
```

Replace the fields/constructor/destroy/observe section with (the host's listener is already outside the zone; `runOutsideZone` stays for the rAF):

```ts
  private readonly observer?: ResizeObserver;
  private readonly stopListening: () => void;
  private readonly stopWatchingResize: () => void;
  private frame = 0;
  private destroyed = false;
  private units: FocusUnit[] = [];
  private regroupPending = true;

  constructor(private readonly config: ReadingFocusConfig) {
    this.runOutsideZone = config.runOutsideZone ?? ((run) => run());
    this.stopListening = config.scroller.listen(() => this.schedule());
    this.stopWatchingResize = config.scroller.watchResize(() => this.scheduleRegroup());
    if (typeof ResizeObserver !== 'undefined') {
      this.observer = new ResizeObserver(() => this.scheduleRegroup());
    }
    this.observe();
    this.schedule();
  }
```

```ts
  destroy(): void {
    this.destroyed = true;
    this.cancel();
    this.observer?.disconnect();
    this.stopWatchingResize();
    this.stopListening();
  }

  private observe(): void {
    if (!this.observer) return;
    this.observer.disconnect();
    for (const block of this.config.blocks()) this.observer.observe(block);
  }
```

Delete the old `private readonly onScroll = …` field. In `measureOpacities`, replace `scroller.getBoundingClientRect().top` with `scroller.viewportTop()`. `regroup()` and `measureOpacities()` keep `scroller.clientHeight` (the host has it).

- [ ] **Step 3: Give the list a host and feed `ListReadingFocus`**

In `entry-list.component.ts`, next to `rows`:

```ts
import { ScrollHosts } from '../../../shared/scroll-host/scroll-hosts.service';
import type { ScrollHost } from '../../../shared/scroll-host/scroll-host';
// …
  private readonly scrollHosts = inject(ScrollHosts);
  private readonly scrollHost = computed((): ScrollHost | undefined => {
    const rows = this.rows()?.nativeElement;
    return rows ? this.scrollHosts.forElement(rows) : undefined;
  });
```

Declare it **above** `readingFocus` (field initializers run in order). Change `readingFocus` to `scroller: this.scrollHost`.

In `list-reading-focus.ts`: `readonly scroller: () => ScrollHost | undefined;` (import the type), and in `_bindReadingFocus` build blocks from the host's content:

```ts
      blocks: () =>
        (Array.from(scroller.content.children) as HTMLElement[]).filter(
          (child) => !child.classList.contains('foot'),
        ),
```

- [ ] **Step 4: The article layer exposes a host and listens through it**

In `article-layer.component.ts`:

```ts
import { ScrollHosts } from '../../../shared/scroll-host/scroll-hosts.service';
import type { ScrollHost } from '../../../shared/scroll-host/scroll-host';
// …
  private readonly scrollHosts = inject(ScrollHosts);
  private readonly scrollerRef = viewChild<ElementRef<HTMLElement>>('scroller');
  readonly scroller = computed((): ScrollHost | undefined => {
    const element = this.scrollerRef()?.nativeElement;
    return element ? this.scrollHosts.forElement(element) : undefined;
  });
```

In the constructor, after `this.gestures.connect(…)`:

```ts
    effect((onCleanup) => {
      const scroller = this.scroller();
      if (scroller) onCleanup(scroller.listen(() => this.onScroll(scroller.scrollTop)));
    });
```

`onScroll` becomes `private`. In `article-layer.component.html`, drop `(scroll)="onScroll(scroller.scrollTop)"` from the `.scroller` div (keep `#scroller` and `[appScrollProgress]` — Task 4 handles the latter). `reset()` and `scrollToTop()` compile unchanged (`scrollTop`, `scrollTo` exist on the host).

The listener now runs outside the zone, like the list's (#501). It only writes signals and emits `scrolled`, whose one consumer (`ArticlePageComponent.onScroll` → `restore.remember`) touches no template. `article-layer.component.spec.ts` dispatches `scroll` on the `.scroller` element and stays as is — it is this step's regression check.

- [ ] **Step 5: Retype the article consumers**

- `article-gestures.service.ts`: `readonly scroller: Signal<ScrollHost | undefined>;` in `ArticleGestureHost` (import the type). `atBottom(scroller)` compiles unchanged — `ScrollHost` satisfies `ScrollMetrics`.
- `article-scroll-restore.service.ts`: `private scroller: Signal<ScrollHost | undefined> = signal(undefined);` and `connect(scroller: Signal<ScrollHost | undefined>)`. The body compiles unchanged.
- `reading-scope.service.ts`: `readonly scroller: Signal<ScrollHost | undefined>;` in `ReadingScopeParts`, the private field likewise, and:

```ts
function bottomWithin(scroller: ScrollHost, element: HTMLElement): number {
  return element.getBoundingClientRect().bottom - scroller.viewportTop() + scroller.scrollTop;
}
```

- `article-page.component.ts`, `scrollToElement`:

```ts
    const top = target.getBoundingClientRect().top - scroller.viewportTop() + scroller.scrollTop;
    scroller.scrollTo({
      top: Math.max(0, top - scroller.scrollPaddingTop()),
      behavior: this.reduceMotion ? 'auto' : 'smooth',
    });
```

`private readonly scroller = computed(() => this.layer()?.scroller());` is now `Signal<ScrollHost | undefined>` by inference.

- [ ] **Step 6: Adapt the reading-scope spec**

In `reading-scope.service.spec.ts`, `scrollerOfViewport()` returns a host:

```ts
import { ElementScrollHost, ScrollHost } from '../../../shared/scroll-host/scroll-host';

function scrollerOfViewport(): ScrollHost {
  const scroller = document.createElement('div');
  Object.defineProperty(scroller, 'clientHeight', { configurable: true, value: VIEWPORT });
  scroller.getBoundingClientRect = () => ({ top: 0, bottom: VIEWPORT }) as DOMRect;
  return new ElementScrollHost(scroller, (run) => run());
}
```

Grep the spec for other uses of the returned scroller (`scroller.`); any that reach into the element go through `.content`.

- [ ] **Step 7: Typecheck and run the affected suites**

Run (from `frontend/`): `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`
Expected: no errors. If `entry-list.component.ts` reports a type error elsewhere, it is a list consumer Task 3 converts — none should, since only `readingFocus` switched to `scrollHost`.

Run: `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/reader/article src/app/reader/list src/app/shared/scroll-host`
Expected: PASS, same test count as on `develop` for these paths plus Task 1's 7.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app
git commit -m "refactor(#2038): the article pane and the focus applier read a ScrollHost"
```

---

### Task 3: The list pane reads its `ScrollHost`

**Files:**
- Modify: `reader/list/entry-list/list-scroll-state.ts`
- Modify: `reader/list/entry-list/pull-to-refresh.ts`
- Modify: `reader/list/entry-list/list-above-fold.ts`
- Modify: `reader/media/near-viewport-media.ts`
- Modify: `reader/list/entry-list/entry-list.component.ts`, `.html`, `entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `EntryListComponent.scrollHost` (Task 2).
- Produces: `ListScrollStateOptions.scroller: () => ScrollHost | undefined`, `aboveFold: (scroller: ScrollHost) => boolean`, `ListScrollState.onScroll(top: number)`, `applyScroll(scroller: ScrollHost, top: number)`; `PullToRefreshOptions.scroller: () => ScrollHost | undefined`; `ListAboveFoldOptions.scroller: () => ScrollHost | undefined`, `hasEntryAbove(scroller: ScrollHost)`; `NearViewportMedia.scrollWithin(root: Element | null)`.

- [ ] **Step 1: Retarget the spec's direct `onScroll` calls (they fail to compile next)**

`entry-list.component.spec.ts` calls `scrolling.onScroll({ target: { scrollTop: N } } as unknown as Event)` 16 times; the handler now takes the offset. From `frontend/`:

```bash
perl -0pi -e 's/scrolling\.onScroll\(\{\s*target: \{ scrollTop: (\d+) \},\s*\} as unknown as Event\)/scrolling.onScroll($1)/g' src/app/reader/list/entry-list/entry-list.component.spec.ts
grep -n "onScroll({" src/app/reader/list/entry-list/entry-list.component.spec.ts
```

Expected: the grep prints nothing. If it prints lines, rewrite those by hand to `scrolling.onScroll(<number>)`.

The `#501` block (`'scroll events from the rows element'`, around line 1558) dispatches real `scroll` events on `.rows` and asserts the handler ran outside the zone — keep it unchanged; it is the regression check for the new listener wiring.

- [ ] **Step 2: `ListScrollState` takes the host and listens itself**

In `list-scroll-state.ts` (import `ScrollHost` as a type):

```ts
export interface ListScrollStateOptions {
  readonly scroller: () => ScrollHost | undefined;
  // …selection, loading, layout, reduceMotion unchanged
  /** Whether an entry is fully scrolled past the fold of this scroller. */
  readonly aboveFold: (scroller: ScrollHost) => boolean;
  readonly onReloaded: () => void;
}
```

Add the listener effect after `_scrollOnSelectionChange`:

```ts
  private readonly _listenToScroll = effect((onCleanup) => {
    const scroller = this.options.scroller();
    if (scroller) onCleanup(scroller.listen(() => this.onScroll(scroller.scrollTop)));
  });
```

Replace the head of `onScroll`:

```ts
  readonly onScroll = (top: number): void => {
    this.collapsed.set(
```

(the old `scrollEvent.target` guard and `const top = element.scrollTop;` go; the rest of the body is unchanged). Rename the `element` locals and parameters in `_restoreScroll`, `_scrollOnSelectionChange`, `scrollToTop`, `landAtTop`, `applyScroll` and `settleTo` to `scroller` and type them `ScrollHost`; their bodies use only `scrollTop`, `scrollTo` and `scrollHeight`, which the host has.

- [ ] **Step 3: Pull-to-refresh and above-fold take the host**

`pull-to-refresh.ts`: `readonly scroller: () => ScrollHost | undefined;`. In `_wirePull`:

```ts
    const scroller = this.options.scroller();
    this.pullCleanup?.();
    this.pullCleanup = undefined;
    if (!scroller) return;
    const element = scroller.content;
    const start = (touchEvent: TouchEvent): void => this.onPullStart(touchEvent, scroller);
    const move = (touchEvent: TouchEvent): void => this.onPullMove(touchEvent, scroller);
```

(the four `addEventListener`/`removeEventListener` lines keep using `element`). `onPullStart`/`onPullMove` take `scroller: ScrollHost` and read `scroller.scrollTop`.

`list-above-fold.ts`:

```ts
export interface ListAboveFoldOptions {
  readonly scroller: () => ScrollHost | undefined;
  readonly header: () => HTMLElement | undefined;
  readonly groups: () => readonly FoldedGroup[];
}

export class ListAboveFold {
  constructor(private readonly options: ListAboveFoldOptions) {}

  hasEntryAbove(scroller: ScrollHost): boolean {
    const first = scroller.content.querySelector('[data-entry-id]');
    return !!first && first.getBoundingClientRect().bottom <= this.foldTop(scroller);
  }

  entryIds(): number[] {
    const scroller = this.options.scroller();
    if (!scroller) return [];
    const measured = measureEntries(scroller.content);
    const above = entriesAboveFold(measured, this.foldTop(scroller));
    const rendered = new Set(measured.map((measurement) => measurement.id));
    return [...above, ...foldedGroupTailsAbove(new Set(above), rendered, this.options.groups())];
  }

  private foldTop(scroller: ScrollHost): number {
    const header = this.options.header()?.getBoundingClientRect().bottom ?? 0;
    return Math.max(scroller.viewportTop(), header);
  }
}
```

- [ ] **Step 4: Intersection roots come from the host**

`near-viewport-media.ts`: `scrollWithin(root: Element | null): void` (body unchanged).

In `entry-list.component.ts`:

```ts
  private readonly _nearViewportRoot = effect(() => {
    const scroller = this.scrollHost();
    if (scroller) this.nearViewport.scrollWithin(scroller.intersectionRoot);
  });
```

and in `_wire`:

```ts
    const node = this.sentinel()?.nativeElement;
    const scroller = this.scrollHost();
    this.observer?.disconnect();
    if (node && scroller && typeof IntersectionObserver !== 'undefined') {
      this.observer = new IntersectionObserver(
        (es) => { /* unchanged callback */ },
        {
          root: scroller.intersectionRoot,
          rootMargin: prefetchMargin(scroller.clientHeight),
        },
      );
      this.observer.observe(node);
    }
```

`node && scroller` replaces `node` alone: the sentinel lives inside `.rows`, so it never exists without the host — no case loses its observer. Move `_nearViewportRoot` below `scrollHost` if it sits above it.

- [ ] **Step 5: Wire the remaining list consumers to the host; drop the element accessor and the template listener**

In `entry-list.component.ts`: `pull`, `aboveFold` and `scrolling` take `scroller: this.scrollHost`. Delete `private readonly scroller = (): HTMLElement | undefined => …`. Remove `ScrollOutsideZoneDirective` from the component's `imports` (the sidebar still uses it; do not delete the directive).

In `entry-list.component.html`, delete `[appScrollOutsideZone]="scrolling.onScroll"` from both `.rows` elements (around lines 104 and 152).

- [ ] **Step 6: Typecheck and run the list suites**

Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`
Expected: no errors.

Run: `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/reader/list src/app/reader/media src/app/reader/scroll`
Expected: PASS, test count unchanged from Task 2's run of these paths.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app
git commit -m "refactor(#2038): the list pane reads its ScrollHost"
```

---

### Task 4: The progress rail reads a `ScrollHost`; its directive goes

The rail is told its scroller in its options instead of by a directive on the element; templates bind the scrollbar-hiding class themselves.

**Files:**
- Modify: `shared/progress-rail/scroll-progress-rail.ts`
- Delete: `shared/progress-rail/scroll-progress.directive.ts`
- Modify: `shared/progress-rail/progress-rail.component.spec.ts`
- Modify: `reader/list/entry-list/estimated-list-bottom.ts`
- Modify: `reader/list/entry-list/entry-list.component.ts`, `.html`
- Modify: `reader/article/article-page/article-page.component.ts`
- Modify: `reader/article/article-layer/article-layer.component.ts`, `.html`

**Interfaces:**
- Consumes: `EntryListComponent.scrollHost`, `ArticlePageComponent.scroller` (Tasks 2–3).
- Produces: `ScrollProgressRailOptions.scroller: () => ScrollHost | undefined`; `contentBottom: (scroller: ScrollHost) => number | null`; `ScrollProgressRail.replacesScrollbar` unchanged; `attachScroller`/`detachScroller` removed.

- [ ] **Step 1: Adapt the rail component spec (fails to compile next)**

In `progress-rail.component.spec.ts`, every `new ScrollProgressRail({ … })` gains `scroller: () => undefined,`. Grep the file for `attachScroller`; if a test attaches an element, pass that element as a host instead (`scroller: () => new ElementScrollHost(element, (run) => run())`) and delete the attach call.

- [ ] **Step 2: Move the rail onto the host**

In `scroll-progress-rail.ts`:

```ts
import type { ScrollHost } from '../scroll-host/scroll-host';

export interface ScrollProgressRailOptions {
  readonly isWide: Signal<boolean>;
  /** The pane's scroller; the rail listens to it and repaints on its scroll and resize. */
  readonly scroller: () => ScrollHost | undefined;
  /** Where the content ends in the scroller's scroll coordinates, or null when unknown. */
  readonly contentBottom: (scroller: ScrollHost) => number | null;
  /** Changes whenever the content's end may have moved; the rail repaints once rendered. */
  readonly layout: Signal<unknown>;
}
```

Delete the `private readonly scroller = signal<HTMLElement | undefined>(undefined);` field, `attachScroller`, `detachScroller` and `_paintOnResize`; add:

```ts
  private readonly _followScroller = effect((onCleanup) => {
    const scroller = this.options.scroller();
    if (!scroller) {
      this.overflows.set(false);
      this.knowsLength.set(false);
      return;
    }
    const stopListening = scroller.listen(this.paint);
    const stopWatching = scroller.watchResize(this.paint);
    onCleanup(() => {
      stopListening();
      stopWatching();
    });
  });
```

In `paint`, `const scroller = untracked(this.options.scroller);` replaces `untracked(this.scroller)`; the rest of `paint` is unchanged. Keep the class comment's first sentence, and change "the scroller (`appScrollProgress`) and the rail (`app-progress-rail`) attach themselves" to "the rail (`app-progress-rail`) attaches itself".

- [ ] **Step 3: Delete the directive and bind the class in the templates**

```bash
git rm frontend/src/app/shared/progress-rail/scroll-progress.directive.ts
```

`entry-list.component.html`, both `.rows` elements: replace `[appScrollProgress]="progressRail"` with `[class.scrollbar-hidden]="progressRail.replacesScrollbar()"`.
`article-layer.component.html`, `.scroller`: replace `[appScrollProgress]="progressRail()"` with `[class.scrollbar-hidden]="progressRail().replacesScrollbar()"`.
Remove `ScrollProgressDirective` from both components' `imports` and import lines.

- [ ] **Step 4: Hand each rail its host**

`estimated-list-bottom.ts`, `measure`:

```ts
  readonly measure = (scroller: ScrollHost): number | null => {
    const slots = scroller.content.querySelectorAll<HTMLElement>('.row-slot');
    if (slots.length === 0) return null;
    const layout = this.layout();
    const origin = scroller.viewportTop() - scroller.scrollTop;
```

(rest unchanged; import the type).

`entry-list.component.ts`: `progressRail` gains `scroller: this.scrollHost,`.
`article-page.component.ts`: `progressRail` gains `scroller: this.scroller,` (`scroller` is declared above it already).

- [ ] **Step 5: Typecheck, run the suites, grep for leftovers**

Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`
Expected: no errors.

Run: `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/shared src/app/reader`
Expected: PASS.

Run: `grep -rn "appScrollProgress\|ScrollProgressDirective\|attachScroller" src/app`
Expected: no output.

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src/app
git commit -m "refactor(#2038): the progress rail reads a ScrollHost; its directive goes"
```

---

### Task 5: Prove no behaviour changed, then the PR

**Files:** none new.

- [ ] **Step 1: No consumer reads a raw scroller element any more**

Run (from `frontend/`): `grep -rnE "scroller: \(\) => HTMLElement|Signal<HTMLElement \| undefined>" src/app/reader src/app/shared`
Expected: no output.

- [ ] **Step 2: The full gate**

Run: `npm run check -- 2>&1 | tail -20` — if jest inside it picks up Playwright specs or a global babel jest, rerun jest alone as `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/"`; lint, Prettier, Stylelint and tsc run before jest, so a jest-only failure there is environmental.
Expected: all green; jest's suite count = 468 + 1 (the new `scroll-host.spec.ts`).

Run: `npm run build`
Expected: success.

- [ ] **Step 3: Live check from this worktree**

Docker's `:4200` serves the main checkout, and the repo's `proxy.conf.json` targets `https://nginx` (Docker-internal). Serve this worktree against the running Docker backend with a scratch proxy file (not committed):

```bash
cat > /tmp/proxy-2038.json <<'EOF'
{ "/api": { "target": "https://localhost:8443", "secure": false, "headers": { "Host": "localhost" } },
  "/state": { "target": "https://localhost:8443", "secure": false, "headers": { "Host": "localhost" } } }
EOF
npx ng serve --port 4210 --proxy-config /tmp/proxy-2038.json
```

Check at a phone viewport (375×812) and at desktop width, logged in with the demo account: the list scrolls; the app bar and list header hide on scroll-down and return on scroll-up; the green rail fills on the right (phone) / hairline (desktop) and no native scrollbar shows under it; back-to-top appears past a screen and works; pull-to-refresh fires at the top; reading-focus dimming follows the scroll when enabled; open an article: its toolbar hides/returns, its rail fills, to-top works, a TOC jump lands below the toolbar, swipe-back and end-pull return to the list at the same offset. Scan the browser console for errors.

- [ ] **Step 4: `/simplify`, then push and open the PR**

Run `/simplify` over the branch diff and apply what it finds (re-run Step 2 if it changed code). Then:

```bash
git push -u origin feature/2038-page-scroll-on-narrow
gh pr create --base develop --title "refactor(#2038): a ScrollHost seam under every reader scroller" --body "$(cat <<'EOF'
Phase 1 of #2038 (spec: docs/superpowers/specs/2026-11-05-2038-page-scroll-on-narrow-design.md).

Every reader scroll consumer — list scroll state, pull-to-refresh, above-fold, reading focus,
the progress rail, article scroll restore, reading scope, article gestures, TOC jumps — now
reads its scroller through a `ScrollHost` instead of a raw element. `ElementScrollHost` wraps
today's element, so nothing changes on screen; phase 2 adds a page host for the narrow layout.

- `ScrollProgressDirective` is gone: the rail is handed its host, templates bind
  `.scrollbar-hidden` directly.
- The article layer's scroll listener now runs outside the Angular zone, like the list's (#501).

Refs #2038
EOF
)"
```

The body says `Refs`, not `Closes`: #2038 stays open for phases 2–4.
