# #2038 Phase 2 — the list on the page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** At every width the entry list scrolls the document, so mobile browsers collapse their bar; the shell's and the list's chrome is `position: fixed`, the sidebar column, pane divider and split reader pane are pinned, the progress rail is vertical everywhere, and a full-screen article stays an inner scroller over a pinned list.

**Architecture:** A `PageScrollHost` implements phase 1's `ScrollHost` on the document (window scroll, `innerHeight`, CDK-block aware) and the entry list takes it instead of `forElement(.rows)`. The shell publishes the list column's box (`--list-column-left`, `--list-column-width`) and the audio bar's height (`--audio-bar-h`); the list's chrome moves into two fixed `.list-frame`s placed by those properties, so every existing `absolute` rule inside them keeps working. Pinned columns are sticky flex children with their own `contain` scrollers; the drawer and a full-screen article pin the page with the CDK's own `BlockScrollStrategy`, which `PageScrollHost` already has to understand for dialogs.

**Tech Stack:** Angular 20.2 (standalone, signals, zone.js), `@angular/cdk` 20.2.14 overlay (`createBlockScrollStrategy`), Jest (jsdom), Playwright, SCSS + Stylelint.

**Spec:** `docs/superpowers/specs/2026-11-05-2038-page-scroll-on-narrow-design.md` — this plan is Delivery item 2. §2's `PageScrollOwner` handoff is phase 3 and **not** in this plan.

**Branch:** `feature/2038-page-scroll-phase-2`, cut from `develop` after PR #2039 (phase 1) merges. If #2039 is still open when work starts, cut it from `feature/2038-page-scroll-on-narrow` instead, open the PR against that branch, and retarget it to `develop` once #2039 merges.

## Open questions the spec does not settle — decided here

1. **How fixed list chrome follows the `.list` column.** Decision: two `position: fixed` wrappers (`.list-frame.lead` with the header, pull tray and error; `.list-frame.tail` with the rail, wait overlay and corner discs) sized from `--list-column-left`/`--list-column-width`/`--audio-bar-h`. Every child keeps its `absolute` rule, the `@container entry-list` squeeze rule moves onto the tail frame, and DOM/tab order stays as it is. Cost: two wrapper elements; the frames need `pointer-events: none` with `auto` on their controls.
2. **The audio bar.** The spec lists it as fixed chrome but not the space it held in the flow. Decision: it becomes `fixed` at the bottom; the shell publishes its height as `--audio-bar-h`, and the rows' tail padding, the pinned columns' heights, the drawer, the full-screen overlay and the list frames leave it free.
3. **How the list beneath a full-screen article is kept still** (spec item: "decide how"). Decision: the same CDK `BlockScrollStrategy` dialogs use, enabled while `(sidebarOpen() && screen.isNarrow()) || articleFullscreen()`. Why: `overscroll-behavior: contain` on the article's scroller does not stop a wheel over the toolbar/nameplate (not scrollers) or iOS touch chaining from a non-overflowing article, both of which would scroll the list and flip its hide-on-scroll state; the block pins `<html>` so nothing can, restores the offset exactly on release, and `PageScrollHost` must handle that class for dialogs anyway. Cost: while a full-screen article is open the page is pinned, so the browser bar cannot collapse while reading a full-screen article until phase 3's handoff.
4. **A write to the page while a block holds it** (a list reload restoring its offset under an open article, `landAtTop()` after a confirm dialog). The spec says only "re-baseline silently on release". Decision: `PageScrollHost` reports the pinned offset (`-html.style.top`) while blocked, stays silent, moves `html.style.top` on a write and lands the write after the release (a `MutationObserver` on `<html>`'s class, created in `listen()`). Cost: one observer per listener.
5. **Paged searches and the scroll cue.** The rail needs a known length (#1392). Decision (Lars, 2026-11-05): the page scrollbar is hidden only while the list's rail replaces it — today's phone rule, now on `<html>`: the entry list toggles `page-scrollbar-hidden` on `<html>` from `progressRail.replacesScrollbar()` and removes it on destroy. A paged search shows the native page scrollbar until its length is known. Cost: in split view that scrollbar sits at the window's right edge meanwhile.
6. **Leaving the reader.** The document offset would otherwise carry into `/settings`. Decision: on destroy the shell releases the block, removes `.reader-page`, restores the previous `history.scrollRestoration`, and sets the page to the top.
7. **The rail's `horizontal` mode** must stay (spec §5 fallback) while nothing selects it. Decision: `isWide` becomes an optional `horizontal?: Signal<boolean>`; no caller passes it, so it is `false` everywhere, and phase 4 can pass a coarse-pointer signal.
8. **The entry-list spec's 50-odd element-scroller tests.** Decision: `mount()` provides `ElementBackedScrollHosts` (a test `ScrollHosts` whose `forPage` returns an element host), keeping those consumer tests meaningful; one new test proves the real wiring hears the page, and `PageScrollHost` has its own spec.
9. **Wheel over fixed chrome during a scroll-restore settle.** `ListScrollState` cancels its ≤30-frame settle on wheel/touchmove heard on the list host; a wheel over the app bar now scrolls the page without cancelling it. Decision: unchanged — the settle re-asserts for at most 30 frames.

## Global Constraints

- The list scrolls the document at **every width**; split view's reader pane is the one inner scroller (spec Goal). Full-screen articles still inner-scroll this phase.
- `html { overscroll-behavior-y: none }` on the reader route only, keyed to the class `reader-page` on `<html>`; `html { scrollbar-width: none }` only while `<html>` also has `page-scrollbar-hidden`, which the entry list sets from `progressRail.replacesScrollbar()`; `history.scrollRestoration = 'manual'` while the reader is mounted.
- The CDK block class is `cdk-global-scrollblock` (`overlay-prebuilt.css`: `position:fixed; width:100%; overflow-y:scroll`); CDK `enable()` writes `html.style.top = -offset px`, `disable()` removes the class and calls `window.scroll(left, top)`.
- Scroll and resize listeners and observers are created outside the Angular zone (#501).
- Published custom properties, on the shell host, in `px`: `--list-column-left`, `--list-column-width`, `--audio-bar-h`. Fallbacks: `var(--list-column-left, 0)`, `var(--list-column-width, 100%)`, `var(--audio-bar-h, 0)`, and inside `calc()` `var(--audio-bar-h, 0rem)` (unitless `0` is invalid in a length `calc`, and Stylelint forbids `px` in `padding`/`height`/`min-height`).
- z-index: app bar `10`, `.under-header` `4`, `.article-overlay` `5` (wide) / `12` (below `bp.$bp-lg`), drawer `20`, backdrop `15`, audio bar `9` (new), `.list-frame.lead` `2`, `.list-frame.tail` `1` (new).
- No hex colours, no `px` in padding/margin/gap/width/height/min-height in component SCSS; styles in sibling `.scss` files. Every new `fixed` element is checked for a transformed/filtered/contained ancestor (spec §3, #100).
- Comments: none by default, one line when a reader would otherwise get the code wrong.
- Jest from `frontend/`, paths before the ignore flag: `./node_modules/.bin/jest <paths> --testPathIgnorePatterns "/node_modules/" "/e2e/"`. Native jest skips typechecking: also run `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit` (and `npm run typecheck:e2e` for e2e edits).
- Commits `type(#2038): …`; no Claude attribution anywhere; the PR body says `Refs #2038`, never `Closes`.
- Before the PR: `/simplify`, then `npm run check` and `npm run build` from `frontend/`.
- **Serving this worktree** (Docker's `:4200` serves the main checkout): from `frontend/`, `npx ng serve --port 4210 --proxy-config /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/384082d0-e8fb-43c8-9349-56301e4c76a1/scratchpad/proxy-2038.json`, where that scratch file (already present; recreate it with Task 7 Step 2's `cat` if not) proxies `/api` and `/state` to `https://localhost:8443`. Never commit a proxy file.

## File map

All `shared/…`, `reader/…`, `core/…` paths are under `frontend/src/app/`.

| File | Change |
|---|---|
| `shared/scroll-host/page-scroll-host.ts` | **new** — `PageScrollHost`, `PAGE_SCROLL_BLOCK_CLASS` |
| `shared/scroll-host/page-scroll-host.spec.ts` | **new** |
| `shared/scroll-host/scroll-hosts.service.ts` | `forPage(content)` |
| `frontend/src/testing/scroll-testing.ts` | `ElementBackedScrollHosts` |
| `shared/progress-rail/scroll-progress-rail.ts` (+ `progress-rail.component.spec.ts`) | `isWide` → optional `horizontal` |
| `reader/article/article-page/article-page.component.ts`, `reader/article/article-layer/article-layer.component.spec.ts` | drop `isWide` |
| `reader/shell/reader-page.ts` (+ spec) | **new** — `holdReaderPage(locked)`, `READER_PAGE_CLASS` |
| `frontend/src/styles/_base.scss` | `html.reader-page` rules |
| `core/errors/blank-screen-probe.ts` (+ spec) | the scrolling element joins `scrolled` |
| `reader/shell/page-chrome-geometry.ts` (+ spec) | **new** — publishes the three custom properties |
| `reader/shell/reader-shell.component.{ts,html,scss}` (+ spec) | wiring; fixed chrome; pinned columns |
| `reader/list/entry-list/entry-list.component.{ts,html,scss}` (+ spec) | page host; the two frames |
| `reader/shell/sidebar/sidebar.component.scss` | `.sidebar-body` contains its overscroll |
| `reader/article/article-layer/article-layer.component.scss` | `.scroller` contains its overscroll |
| `frontend/e2e/page-scroll.spec.ts` | **new** |
| `frontend/e2e/{header-scroll-mobile,list-scroll-reset,list-order,reading-focus-orientation,article-reading-progress}.spec.ts` | scroll the page, not `.rows`; vertical pane rail |
| `docs/design-language.md` | "Sticky and scroll": the reader page |

Commit order keeps the app working after every commit: Tasks 1–4 add pieces the old layout ignores (the page is not scrollable until Task 5, so the lock and `html.reader-page` rules are inert); Task 5 is the one switch and must land whole.

---

### Task 1: `PageScrollHost` and `ScrollHosts.forPage`

**Files:**
- Create: `frontend/src/app/shared/scroll-host/page-scroll-host.ts`
- Create: `frontend/src/app/shared/scroll-host/page-scroll-host.spec.ts`
- Modify: `frontend/src/app/shared/scroll-host/scroll-hosts.service.ts`

**Interfaces:**
- Consumes: `interface ScrollHost` (`shared/scroll-host/scroll-host.ts`), `trackScrollTop(element): { top: number }` (`src/testing/scroll-testing.ts`).
- Produces: `export const PAGE_SCROLL_BLOCK_CLASS = 'cdk-global-scrollblock'`; `export class PageScrollHost implements ScrollHost` with `constructor(content: HTMLElement, view: Window, outsideZone: <T>(run: () => T) => T)`; `ScrollHosts.forPage(content: HTMLElement): ScrollHost`.

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/app/shared/scroll-host/page-scroll-host.spec.ts
import { NgZone } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PAGE_SCROLL_BLOCK_CLASS, PageScrollHost } from './page-scroll-host';
import { ScrollHosts } from './scroll-hosts.service';
import { trackScrollTop } from '../../../testing/scroll-testing';

const inZone = <T>(run: () => T): T => run();

describe('PageScrollHost', () => {
  const root = document.documentElement;
  const content = document.createElement('div');
  let page: { top: number };

  beforeEach(() => {
    Object.defineProperty(document, 'scrollingElement', { value: root, configurable: true });
    page = trackScrollTop(root);
  });

  afterEach(() => {
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    root.style.top = '';
    root.style.scrollPaddingTop = '';
    jest.restoreAllMocks();
  });

  const pageHost = (): PageScrollHost => new PageScrollHost(content, window, inZone);
  const scrollEvent = (): boolean => window.dispatchEvent(new Event('scroll'));
  const mutationsDelivered = (): Promise<void> => new Promise((resolve) => setTimeout(resolve));

  /** What a CDK BlockScrollStrategy does to `<html>` on enable, minus its viewport ruler. */
  function blockAt(top: number): void {
    root.style.top = `${-top}px`;
    root.classList.add(PAGE_SCROLL_BLOCK_CLASS);
    page.top = 0;
  }

  /** …and on disable: the class goes, then the window is scrolled back. */
  function releaseTo(top: number): void {
    root.style.top = '';
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    page.top = top;
  }

  it('reads and writes the document offset', () => {
    const host = pageHost();
    host.scrollTop = 640;
    expect(page.top).toBe(640);
    expect(host.scrollTop).toBe(640);
  });

  it('measures from the viewport', () => {
    Object.defineProperty(window, 'innerHeight', { value: 812, configurable: true });
    Object.defineProperty(root, 'scrollHeight', { value: 5000, configurable: true });
    page.top = 300;
    const host = pageHost();
    expect(host.clientHeight).toBe(812);
    expect(host.scrollHeight).toBe(5000);
    expect(host.viewportTop()).toBe(0);
    expect(host.toContentY(100)).toBe(400);
    expect(host.content).toBe(content);
    expect(host.intersectionRoot).toBeNull();
  });

  it('reads the band chrome covers from the root element', () => {
    root.style.scrollPaddingTop = '96px';
    expect(pageHost().scrollPaddingTop()).toBe(96);
  });

  it('scrolls the window', () => {
    const scrollTo = jest.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    pageHost().scrollTo({ top: 0, behavior: 'smooth' });
    expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
  });

  it('listens to window scrolls until removed', () => {
    const listener = jest.fn();
    const stop = pageHost().listen(listener);
    scrollEvent();
    stop();
    scrollEvent();
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('watches the window and the visual viewport until removed', () => {
    const visualViewport = new EventTarget();
    Object.defineProperty(window, 'visualViewport', { value: visualViewport, configurable: true });
    const listener = jest.fn();
    const stop = pageHost().watchResize(listener);
    window.dispatchEvent(new Event('resize'));
    visualViewport.dispatchEvent(new Event('resize'));
    stop();
    window.dispatchEvent(new Event('resize'));
    visualViewport.dispatchEvent(new Event('resize'));
    expect(listener).toHaveBeenCalledTimes(2);
  });

  describe('under a CDK block', () => {
    it('reports the pinned offset, not the 0 the pinned page scrolls to', () => {
      page.top = 2538;
      const host = pageHost();
      blockAt(2538);
      expect(host.scrollTop).toBe(2538);
      expect(host.toContentY(10)).toBe(2548);
    });

    it('hears no scroll while blocked; the restore lands where it left', () => {
      page.top = 2538;
      const host = pageHost();
      const heard: number[] = [];
      host.listen(() => heard.push(host.scrollTop));
      blockAt(2538);
      scrollEvent();
      releaseTo(2538);
      scrollEvent();
      expect(heard).toEqual([2538]);
    });

    it('holds a write made while blocked and lands it after the release', async () => {
      page.top = 2538;
      const host = pageHost();
      const stop = host.listen(() => undefined);
      blockAt(2538);
      host.scrollTop = 900;
      expect(host.scrollTop).toBe(900);
      expect(root.style.top).toBe('-900px');

      releaseTo(2538);
      await mutationsDelivered();

      expect(page.top).toBe(900);
      stop();
    });

    it('holds a scrollTo the same way instead of scrolling the pinned window', () => {
      const scrollTo = jest.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
      page.top = 2538;
      const host = pageHost();
      blockAt(2538);
      host.scrollTo({ top: 0, behavior: 'smooth' });
      expect(scrollTo).not.toHaveBeenCalled();
      expect(host.scrollTop).toBe(0);
    });
  });
});

describe('ScrollHosts.forPage', () => {
  it('reads the page with the given content, listening outside the Angular zone (#501)', () => {
    const zone = TestBed.inject(NgZone);
    const content = document.createElement('div');
    const zones: boolean[] = [];
    const host = TestBed.inject(ScrollHosts).forPage(content);
    zone.run(() => host.listen(() => zones.push(NgZone.isInAngularZone())));
    window.dispatchEvent(new Event('scroll'));
    expect(host).toBeInstanceOf(PageScrollHost);
    expect(host.content).toBe(content);
    expect(zones).toEqual([false]);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run (from `frontend/`): `./node_modules/.bin/jest src/app/shared/scroll-host --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL — `Cannot find module './page-scroll-host'`.

- [ ] **Step 3: Implement**

```ts
// frontend/src/app/shared/scroll-host/page-scroll-host.ts
import type { ScrollHost } from './scroll-host';

/** The class a CDK BlockScrollStrategy puts on `<html>` while it pins the page. */
export const PAGE_SCROLL_BLOCK_CLASS = 'cdk-global-scrollblock';

/** The document as a scroller (#2038). While a CDK block pins the page it reports the
 *  pinned offset and stays silent, and a write waits for the release, so no consumer
 *  reads the block as a scroll. */
export class PageScrollHost implements ScrollHost {
  readonly intersectionRoot = null;
  private pendingTop: number | null = null;

  constructor(
    readonly content: HTMLElement,
    private readonly view: Window,
    private readonly outsideZone: <T>(run: () => T) => T,
  ) {}

  get scrollTop(): number {
    if (this.blocked()) return -parseFloat(this.root.style.top) || 0;
    return this.scrollingElement.scrollTop;
  }

  set scrollTop(top: number) {
    if (!this.blocked()) {
      this.scrollingElement.scrollTop = top;
      return;
    }
    this.pendingTop = top;
    this.root.style.top = `${-top}px`;
  }

  get clientHeight(): number {
    return this.view.innerHeight;
  }

  get scrollHeight(): number {
    return this.scrollingElement.scrollHeight;
  }

  scrollTo(options: ScrollToOptions): void {
    if (!this.blocked()) {
      this.view.scrollTo(options);
      return;
    }
    if (options.top !== undefined) this.scrollTop = options.top;
  }

  viewportTop(): number {
    return 0;
  }

  toContentY(viewportY: number): number {
    return viewportY + this.scrollTop;
  }

  scrollPaddingTop(): number {
    return parseFloat(this.view.getComputedStyle(this.root).scrollPaddingTop) || 0;
  }

  listen(listener: () => void): () => void {
    const view = this.view;
    const onScroll = (): void => {
      if (!this.blocked()) listener();
    };
    const release = this.outsideZone(() => {
      view.addEventListener('scroll', onScroll, { passive: true });
      const observer = new MutationObserver(() => this.landPendingTop());
      observer.observe(this.root, { attributes: true, attributeFilter: ['class'] });
      return observer;
    });
    return () => {
      view.removeEventListener('scroll', onScroll);
      release.disconnect();
    };
  }

  watchResize(listener: () => void): () => void {
    const view = this.view;
    const visualViewport = view.visualViewport;
    const onResize = (): void => listener();
    this.outsideZone(() => {
      view.addEventListener('resize', onResize, { passive: true });
      visualViewport?.addEventListener('resize', onResize, { passive: true });
    });
    return () => {
      view.removeEventListener('resize', onResize);
      visualViewport?.removeEventListener('resize', onResize);
    };
  }

  private landPendingTop(): void {
    if (this.pendingTop === null || this.blocked()) return;
    const top = this.pendingTop;
    this.pendingTop = null;
    this.scrollingElement.scrollTop = top;
  }

  private blocked(): boolean {
    return this.root.classList.contains(PAGE_SCROLL_BLOCK_CLASS);
  }

  private get root(): HTMLElement {
    return this.view.document.documentElement;
  }

  private get scrollingElement(): Element {
    return this.view.document.scrollingElement ?? this.root;
  }
}
```

`scroll-hosts.service.ts` — replace the whole file (it currently holds only `forElement`):

```ts
import { DOCUMENT } from '@angular/common';
import { Injectable, NgZone, inject } from '@angular/core';
import { ElementScrollHost, ScrollHost } from './scroll-host';
import { PageScrollHost } from './page-scroll-host';

@Injectable({ providedIn: 'root' })
export class ScrollHosts {
  private readonly zone = inject(NgZone);
  private readonly document = inject(DOCUMENT);
  private readonly outsideZone = <T>(run: () => T): T => this.zone.runOutsideAngular(run);

  forElement(element: HTMLElement): ScrollHost {
    return new ElementScrollHost(element, this.outsideZone);
  }

  forPage(content: HTMLElement): ScrollHost {
    return new PageScrollHost(content, this.document.defaultView ?? window, this.outsideZone);
  }
}
```

- [ ] **Step 4: Run the tests and the typecheck**

Run: `./node_modules/.bin/jest src/app/shared/scroll-host --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS (both spec files).
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`
Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/scroll-host
git commit -m "feat(#2038): a PageScrollHost reads the document as a scroller, CDK blocks included"
```

---

### Task 2: The rail stands vertical everywhere

**Files:**
- Modify: `frontend/src/app/shared/progress-rail/scroll-progress-rail.ts:14-40`
- Modify: `frontend/src/app/shared/progress-rail/progress-rail.component.spec.ts:9-39,58`
- Modify: `frontend/src/app/reader/article/article-page/article-page.component.ts:24,96-101`
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.spec.ts:34-39`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts:348-353`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts` (test "runs along the bottom on a wide layout…")
- Modify: `frontend/e2e/article-reading-progress.spec.ts:58-60,144-167`

**Interfaces:**
- Produces: `ScrollProgressRailOptions` without `isWide`, with `readonly horizontal?: Signal<boolean>`; `ScrollProgressRail.horizontal` is `false` unless that option says otherwise; `replacesScrollbar()` = `knowsLength()` for every current caller.

- [ ] **Step 1: Change the tests first**

`progress-rail.component.spec.ts`, replace the `mount` helper and the orientation test (lines 9–39 as they stand):

```ts
  function mount(horizontal?: boolean) {
    const progress = TestBed.runInInjectionContext(
      () =>
        new ScrollProgressRail({
          horizontal: horizontal === undefined ? undefined : signal(horizontal),
          scroller: () => undefined,
          contentBottom: () => null,
          layout: signal(0),
        }),
    );
    const fixture = TestBed.createComponent(ProgressRailComponent);
    fixture.componentRef.setInput('progress', progress);
    fixture.detectChanges();
    return { host: fixture.nativeElement as HTMLElement, progress, fixture };
  }

  it('is decorative and idle until the content overflows', () => {
    const { host, progress, fixture } = mount();
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.classList).toContain('idle');

    progress.overflows.set(true);
    fixture.detectChanges();

    expect(host.classList).not.toContain('idle');
  });

  it('stands up unless a caller asks for the bottom hairline', () => {
    expect(mount().host.classList).not.toContain('horizontal');
    expect(mount(true).host.classList).toContain('horizontal');
  });
```

In the same file's `setup()` (line 58) delete the line `          isWide: signal(false),`.

`article-layer.component.spec.ts` lines 34–39, delete the line `    isWide: signal(false),` from the `new ScrollProgressRail({…})` literal.

`entry-list.component.spec.ts`, in `describe('the list progress rail (#1392)')`, replace the test

```ts
    it('runs along the bottom on a wide layout, keeping the scrollbar', () => {
      const fixture = mount(pagedList, [wideLayout]);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 0);
      scrollTo(fixture, scroller, 1300);

      expect(rail(fixture)!.classList).toContain('horizontal');
      expect(fill(fixture)).toBe('50');
      expect(scroller.classList).not.toContain('scrollbar-hidden');
    });
```

with

```ts
    it('stands on the right edge on a wide layout too', () => {
      const fixture = mount(pagedList, [wideLayout]);
      const scroller = layOut(fixture, 100, 1100);
      scrollTo(fixture, scroller, 0);
      scrollTo(fixture, scroller, 1300);

      expect(rail(fixture)!.classList).not.toContain('horizontal');
      expect(fill(fixture)).toBe('50');
    });
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./node_modules/.bin/jest src/app/shared/progress-rail src/app/reader/list/entry-list/entry-list.component.spec.ts --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL — "stands on the right edge on a wide layout too" (the rail carries `horizontal` on the wide layout) and the progress-rail spec (no `horizontal` option yet; with ts-jest diagnostics it may surface as a type error on `horizontal`).

- [ ] **Step 3: Implement**

`scroll-progress-rail.ts`, the options interface currently opens:

```ts
export interface ScrollProgressRailOptions {
  readonly isWide: Signal<boolean>;
  readonly scroller: () => ScrollHost | undefined;
```

Replace those three lines with:

```ts
export interface ScrollProgressRailOptions {
  /** Unset everywhere: the coarse-pointer fallback should an iPhone draw its own indicator (#2038). */
  readonly horizontal?: Signal<boolean>;
  readonly scroller: () => ScrollHost | undefined;
```

and replace

```ts
  /** A wide layout keeps its scrollbar and gets a hairline along the bottom; a phone
   *  gets a rail on the right edge in place of the scrollbar it withholds. */
  readonly horizontal = computed(() => this.options.isWide());
```

with

```ts
  readonly horizontal = computed(() => this.options.horizontal?.() ?? false);
```

`article-page.component.ts`: delete line 24 `import { LayoutService } from '../../layout.service';` (its only use is the next line) and, in `progressRail` (lines 96–101), delete `    isWide: inject(LayoutService).isWide,`. If `inject` is then unused in the file's import list, ESLint/tsc will say so — it is not (the component injects other services), so leave the import.

`entry-list.component.ts` lines 348–353, delete `    isWide: this.screen.isWide,` from `new ScrollProgressRail({…})` (`this.screen` stays: `pull.enabled` reads it).

`frontend/e2e/article-reading-progress.spec.ts`: replace the doc comment of `railFilledFraction` (lines 58–60)

```ts
/** How full the phone's rail is, as a fraction of its height. #435 stood the
 *  cue up on the right edge below the split-pane breakpoint, so below 900px the
 *  cue fills downward — the horizontal hairline is the wide layout's alone. */
```

with

```ts
/** How full the rail is, as a fraction of its height: it fills downward at every width (#435, #2038). */
```

and replace the split-layout test body from the comment `// The wide layout keeps the hairline…` to the end of the test with:

```ts
    // The rail stands at the pane's right edge in place of the pane's scrollbar (#2038).
    await expect(pane.locator(BOTTOM_HAIRLINE)).toHaveCount(0);

    const box = (await pane.locator(SIDE_RAIL).boundingBox())!;
    const paneBox = (await pane.locator('.scroller').boundingBox())!;
    expect(box.x + box.width).toBeCloseTo(paneBox.x + paneBox.width, 0);
    expect(box.x + box.width).toBeLessThan(DESKTOP.width);
  });
```

and rename that test to `'the rail stands at the reading pane edge, not the window'`.

- [ ] **Step 4: Run tests and typecheck**

Run: `./node_modules/.bin/jest src/app/shared src/app/reader/article src/app/reader/list --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS.
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit && npm run typecheck:e2e`
Expected: no output.
Run: `grep -rn "isWide" src/app/shared/progress-rail src/app/reader/article/article-page/article-page.component.ts`
Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/progress-rail frontend/src/app/reader frontend/e2e/article-reading-progress.spec.ts
git commit -m "feat(#2038): the progress rail stands at the right edge at every width"
```

---

### Task 3: The reader claims the page

**Files:**
- Create: `frontend/src/app/reader/shell/reader-page.ts`
- Create: `frontend/src/app/reader/shell/reader-page.spec.ts`
- Modify: `frontend/src/styles/_base.scss` (append)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts` (field after `sidebarOrganising`, line 229)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.spec.ts` (one test)
- Modify: `frontend/src/app/core/errors/blank-screen-probe.ts:195-205` (+ spec)

**Interfaces:**
- Consumes: `createBlockScrollStrategy(injector: Injector): BlockScrollStrategy` from `@angular/cdk/overlay`; `ReaderShellComponent.sidebarOpen: WritableSignal<boolean>`, `.articleFullscreen: Signal<boolean>`.
- Produces: `export const READER_PAGE_CLASS = 'reader-page'`; `export function holdReaderPage(locked: Signal<boolean>): EffectRef` (injection context); global `html.reader-page` rules.

- [ ] **Step 1: Write the failing tests**

```ts
// frontend/src/app/reader/shell/reader-page.spec.ts
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { READER_PAGE_CLASS, holdReaderPage } from './reader-page';
import { trackScrollTop } from '../../../testing/scroll-testing';

describe('holdReaderPage', () => {
  const root = document.documentElement;
  let page: { top: number };

  beforeEach(() => {
    Object.defineProperty(document, 'scrollingElement', { value: root, configurable: true });
    Object.defineProperty(window.history, 'scrollRestoration', {
      value: 'auto',
      writable: true,
      configurable: true,
    });
    page = trackScrollTop(root);
  });

  afterEach(() => jest.restoreAllMocks());

  function hold(locked = signal(false)): void {
    TestBed.runInInjectionContext(() => holdReaderPage(locked));
    TestBed.tick();
  }

  it('claims the page while the reader is mounted', () => {
    hold();
    expect(root.classList).toContain(READER_PAGE_CLASS);
    expect(history.scrollRestoration).toBe('manual');

    TestBed.resetTestingModule();

    expect(root.classList).not.toContain(READER_PAGE_CLASS);
    expect(history.scrollRestoration).toBe('auto');
  });

  it('leaves the page at the top for the next route', () => {
    hold();
    page.top = 3000;
    TestBed.resetTestingModule();
    expect(page.top).toBe(0);
  });

  it('pins the page while locked, the way a CDK dialog does', () => {
    Object.defineProperty(root, 'scrollHeight', { value: 5000, configurable: true });
    const restore = jest.spyOn(window, 'scroll').mockImplementation(() => undefined);
    page.top = 1200;
    const locked = signal(false);
    hold(locked);

    locked.set(true);
    TestBed.tick();
    expect(root.classList).toContain('cdk-global-scrollblock');
    expect(root.style.top).toBe('-1200px');

    locked.set(false);
    TestBed.tick();
    expect(root.classList).not.toContain('cdk-global-scrollblock');
    expect(restore).toHaveBeenCalledWith(0, 1200);
  });
});
```

In `reader-shell.component.spec.ts`, insert immediately before the line `  describe('hide-on-scroll header', () => {`:

```ts
  it('claims the page while mounted (#2038)', () => {
    const fixture = boot();
    expect(document.documentElement.classList).toContain('reader-page');
    fixture.destroy();
    expect(document.documentElement.classList).not.toContain('reader-page');
  });

```

In `blank-screen-probe.spec.ts`, after the test `'records the scroll offset of a scrolled element'`:

```ts
  it('records the scroll offset of the page itself', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    textRect = BELOW_THE_FOLD;
    const root = document.documentElement;
    Object.defineProperty(document, 'scrollingElement', { value: root, configurable: true });
    Object.defineProperty(root, 'scrollTop', { value: 4200, configurable: true });
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);
    delete (root as unknown as { scrollTop?: number }).scrollTop;

    expect(sentPayloads()[0].snapshot.scrolled).toEqual([
      expect.objectContaining({ element: 'html', scrollTop: 4200 }),
    ]);
  });
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./node_modules/.bin/jest src/app/reader/shell/reader-page.spec.ts src/app/reader/shell/reader-shell.component.spec.ts src/app/core/errors --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL — `Cannot find module './reader-page'`; the shell test (no `reader-page` class); the probe test (`scrolled` is `[]`: `body *` excludes `<html>`).

- [ ] **Step 3: Implement**

```ts
// frontend/src/app/reader/shell/reader-page.ts
import { DOCUMENT } from '@angular/common';
import { DestroyRef, EffectRef, Injector, Signal, effect, inject } from '@angular/core';
import { createBlockScrollStrategy } from '@angular/cdk/overlay';

/** On `<html>` while the reader owns the page; `styles/_base.scss` keys the page rules to it. */
export const READER_PAGE_CLASS = 'reader-page';

/** The reader scrolls the document (#2038): it claims the page while mounted, pins it while
 *  `locked` holds, and leaves it at the top for the next route. Call in an injection context. */
export function holdReaderPage(locked: Signal<boolean>): EffectRef {
  const document = inject(DOCUMENT);
  const root = document.documentElement;
  const history = document.defaultView?.history;
  const restoration = history?.scrollRestoration;
  const block = createBlockScrollStrategy(inject(Injector));
  root.classList.add(READER_PAGE_CLASS);
  if (history) history.scrollRestoration = 'manual';
  inject(DestroyRef).onDestroy(() => {
    block.disable();
    root.classList.remove(READER_PAGE_CLASS);
    if (history && restoration) history.scrollRestoration = restoration;
    (document.scrollingElement ?? root).scrollTop = 0;
  });
  return effect(() => {
    if (locked()) block.enable();
    else block.disable();
  });
}
```

Append to `frontend/src/styles/_base.scss`:

```scss
/* The reader scrolls the document (#2038): no browser pull-to-reload or edge bounce
   steals its own pulls. */
html.reader-page {
  overscroll-behavior-y: none;
}

/* Set by the entry list while its progress rail stands in for the scrollbar. */
html.reader-page.page-scrollbar-hidden {
  scrollbar-width: none;
}

html.reader-page.page-scrollbar-hidden::-webkit-scrollbar {
  display: none;
}
```

`reader-shell.component.ts`: add `import { holdReaderPage } from './reader-page';` beside the other `./` imports, and directly after

```ts
  readonly sidebarOrganising = signal(false);
```

insert

```ts

  /** The drawer and a full-screen article cover the list; nothing may scroll it beneath them. */
  private readonly _holdPage = holdReaderPage(
    computed(() => (this.sidebarOpen() && this.screen.isNarrow()) || this.articleFullscreen()),
  );
```

`blank-screen-probe.ts`, replace

```ts
function scrolledElements(): Record<string, unknown>[] {
  return Array.from(document.querySelectorAll('body *'))
    .filter((element) => element.scrollTop > 0)
```

with

```ts
function scrolledElements(): Record<string, unknown>[] {
  const page = document.scrollingElement ?? document.documentElement;
  return [page, ...Array.from(document.querySelectorAll('body *'))]
    .filter((element) => element.scrollTop > 0)
```

- [ ] **Step 4: Run tests and typecheck**

Run: `./node_modules/.bin/jest src/app/reader/shell src/app/core/errors --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS.
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`
Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/shell frontend/src/app/core/errors frontend/src/styles/_base.scss
git commit -m "feat(#2038): the reader claims the page: no overscroll, no scrollbar, manual history scroll, a pin for drawer and article"
```

---

### Task 4: The shell publishes the list column's box and the audio bar's height

**Files:**
- Create: `frontend/src/app/reader/shell/page-chrome-geometry.ts`
- Create: `frontend/src/app/reader/shell/page-chrome-geometry.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html:135,206`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts` (fields after `showSidebarButton`)

**Interfaces:**
- Consumes: `MockResizeObserver`, `installMockResizeObserver` (`src/testing/resize-observer-testing.ts`).
- Produces: `export class PageChromeGeometry` with `constructor(options: PageChromeGeometryOptions)` where `PageChromeGeometryOptions = { host: HTMLElement; listColumn: () => HTMLElement | undefined; main: () => HTMLElement | undefined; audioBar: () => HTMLElement | undefined }`; custom properties `--list-column-left`, `--list-column-width`, `--audio-bar-h` (px) on the shell host. Task 5 consumes them.

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/app/reader/shell/page-chrome-geometry.spec.ts
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PageChromeGeometry } from './page-chrome-geometry';
import {
  MockResizeObserver,
  installMockResizeObserver,
} from '../../../testing/resize-observer-testing';

function boxed(rect: Partial<DOMRect>, tag = 'div'): HTMLElement {
  const element = document.createElement(tag);
  element.getBoundingClientRect = () => rect as DOMRect;
  return element;
}

const observing = (element: Element): MockResizeObserver | undefined =>
  MockResizeObserver.instances.find((observer) => observer.targets.has(element));

describe('PageChromeGeometry', () => {
  beforeEach(() => installMockResizeObserver());

  function publish(parts: { column?: HTMLElement; main?: HTMLElement; audioBar?: HTMLElement }) {
    const host = document.createElement('div');
    const column = signal(parts.column);
    TestBed.runInInjectionContext(
      () =>
        new PageChromeGeometry({
          host,
          listColumn: column,
          main: () => parts.main,
          audioBar: () => parts.audioBar,
        }),
    );
    TestBed.tick();
    return { host, column };
  }

  it('publishes the list column box for the fixed list chrome', () => {
    const column = boxed({ left: 312, width: 560 });
    const { host } = publish({ column });
    observing(column)!.fire();
    expect(host.style.getPropertyValue('--list-column-left')).toBe('312px');
    expect(host.style.getPropertyValue('--list-column-width')).toBe('560px');
  });

  it('re-measures the column when the main area resizes around it', () => {
    let left = 312;
    const column = document.createElement('section');
    column.getBoundingClientRect = () => ({ left, width: 280 }) as DOMRect;
    const main = boxed({}, 'main');
    const { host } = publish({ column, main });

    left = 8;
    observing(main)!.fire();

    expect(host.style.getPropertyValue('--list-column-left')).toBe('8px');
  });

  it('follows the column the layout renders next', () => {
    const first = boxed({ left: 0, width: 375 });
    const second = boxed({ left: 312, width: 560 });
    const { host, column } = publish({ column: first });

    column.set(second);
    TestBed.tick();
    observing(second)!.fire();

    expect(observing(first)).toBeUndefined();
    expect(host.style.getPropertyValue('--list-column-left')).toBe('312px');
  });

  it('publishes the audio bar height the page leaves free', () => {
    const audioBar = boxed({ height: 72 });
    const { host } = publish({ audioBar });
    observing(audioBar)!.fire();
    expect(host.style.getPropertyValue('--audio-bar-h')).toBe('72px');
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./node_modules/.bin/jest src/app/reader/shell/page-chrome-geometry.spec.ts --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL — `Cannot find module './page-chrome-geometry'`.

- [ ] **Step 3: Implement**

```ts
// frontend/src/app/reader/shell/page-chrome-geometry.ts
import { NgZone, effect, inject } from '@angular/core';

export interface PageChromeGeometryOptions {
  readonly host: HTMLElement;
  readonly listColumn: () => HTMLElement | undefined;
  readonly main: () => HTMLElement | undefined;
  readonly audioBar: () => HTMLElement | undefined;
}

/** Publishes on the shell what its fixed chrome positions against, since `fixed` measures
 *  from the viewport (#2038): the list column's box and the audio bar's height. Built in a
 *  field initializer, for its effects. */
export class PageChromeGeometry {
  private readonly zone = inject(NgZone);

  // `main` too: hiding the sidebar moves a min-width split column without resizing it.
  private readonly _followListColumn = effect((onCleanup) => {
    const column = this.options.listColumn();
    if (!column) return;
    onCleanup(this.observe([column, this.options.main()], () => this.publishColumn(column)));
  });

  private readonly _followAudioBar = effect((onCleanup) => {
    const bar = this.options.audioBar();
    if (!bar) return;
    onCleanup(
      this.observe([bar], () => this.publish('--audio-bar-h', bar.getBoundingClientRect().height)),
    );
  });

  constructor(private readonly options: PageChromeGeometryOptions) {}

  private observe(targets: (HTMLElement | undefined)[], measure: () => void): () => void {
    if (typeof ResizeObserver === 'undefined') return () => undefined;
    const observer = this.zone.runOutsideAngular(() => new ResizeObserver(() => measure()));
    for (const target of targets) if (target) observer.observe(target);
    return () => observer.disconnect();
  }

  private publishColumn(column: HTMLElement): void {
    const box = column.getBoundingClientRect();
    this.publish('--list-column-left', box.left);
    this.publish('--list-column-width', box.width);
  }

  private publish(property: string, pixels: number): void {
    this.options.host.style.setProperty(property, `${pixels}px`);
  }
}
```

`reader-shell.component.html` line 135: `    <section class="list">` → `    <section class="list" #listColumn>`.
Line 206: `<app-audio-player-bar />` → `<app-audio-player-bar #audioBar />`.

`reader-shell.component.ts`: add `import { PageChromeGeometry } from './page-chrome-geometry';` and, directly after

```ts
  private readonly showSidebarButton =
    viewChild<ElementRef<HTMLButtonElement>>('showSidebarButton');
```

insert

```ts
  private readonly listColumn = viewChild<ElementRef<HTMLElement>>('listColumn');
  private readonly mainColumn = viewChild<ElementRef<HTMLElement>>('mainEl');
  private readonly audioBar = viewChild('audioBar', { read: ElementRef });
  private readonly _chromeGeometry = new PageChromeGeometry({
    host: this.hostRef.nativeElement,
    listColumn: () => this.listColumn()?.nativeElement,
    main: () => this.mainColumn()?.nativeElement,
    audioBar: () => this.audioBar()?.nativeElement as HTMLElement | undefined,
  });
```

(Nothing reads the properties yet; in the shell spec it observes nothing useful (jsdom has no layout), so it is inert there. The wiring is proved by Task 6's divider-drag e2e.)

- [ ] **Step 4: Run tests and typecheck**

Run: `./node_modules/.bin/jest src/app/reader/shell --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS.
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`
Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/shell
git commit -m "feat(#2038): the shell publishes the list column's box and the audio bar's height"
```

---

### Task 5: The list scrolls the page

One commit: the host switch, the list frames and the shell's fixed/pinned CSS depend on each other (with only one of them the rows either scroll inside themselves while the host listens to the window, or the page grows under an unchanged list).

Places that assumed an element scroller beyond the host interface, and what happens to each:
- `.rows { flex: 1; min-height: 0; overflow: auto }` and the host's `height: 100%; display: flex` — removed; `.rows` gets a viewport `min-height` so the airy sheet and a pull still reach the foot of a short list.
- `.rows` `padding-top: $header-clear` — kept (the rows start at the page top under the fixed bars); `$corner-clear` adds `--audio-bar-h`.
- `:host { container: entry-list / size }` — a size container cannot grow with content; it moves to `.list-frame.tail`, the only box the `@container entry-list` rule's children live in. No other `entry-list` container query or list `cqi` uses it (`entry-split` has its own container).
- `.list-header`, `.pull-indicator`, `.list-error`, `app-progress-rail`, `app-loading-overlay`, `.end-corner`, `.corner-actions` — absolute against the host; now absolute inside the fixed frames.
- `[class.scrollbar-hidden]` on both `.rows` — removed; an effect in the entry list puts `page-scrollbar-hidden` on `<html>` instead, from the same `progressRail.replacesScrollbar()` (Step 3).
- Sticky elements inside `.rows`, `scrollIntoView` in the list — none (grep in Step 7).
- `scroll-padding` — `.rows` sets none; `PageScrollHost.scrollPaddingTop()` reads `<html>`'s, also none; no list consumer calls it.
- Keyboard: Space/PageDown/arrows scroll the document by browser default when focus is in the list; inside the split pane they scroll the pane. Nothing to code.
- Reading focus measures against `viewportTop()`/`clientHeight`: `.rows` already began at viewport top 0 under the floating bars, so the page host's `0` keeps the geometry.

**Files:**
- Modify: `frontend/src/testing/scroll-testing.ts`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts:209-212`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.html`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.scss`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.scss`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.spec.ts:836-848` and the 12 `listScroller(fixture)` calls
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.scss:18-25`
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.scss:22-33`

**Interfaces:**
- Consumes: `ScrollHosts.forPage(content)` (Task 1); `--list-column-left`, `--list-column-width`, `--audio-bar-h` (Task 4); `holdReaderPage` lock (Task 3).
- Produces: `@Injectable() export class ElementBackedScrollHosts extends ScrollHosts` in `src/testing/scroll-testing.ts`; `.list-frame.lead` / `.list-frame.tail` in the entry list.

- [ ] **Step 1: Point the existing specs at the right scroller, and write the failing test**

`src/testing/scroll-testing.ts` — add to the imports and append:

```ts
import { Injectable } from '@angular/core';
import type { ScrollHost } from '../app/shared/scroll-host/scroll-host';
import { ScrollHosts } from '../app/shared/scroll-host/scroll-hosts.service';
```

```ts
/** For specs that drive the list's scroller as an element: jsdom has no page scroll (#2038). */
@Injectable()
export class ElementBackedScrollHosts extends ScrollHosts {
  override forPage(content: HTMLElement): ScrollHost {
    return this.forElement(content);
  }
}
```

`entry-list.component.spec.ts`: import `ElementBackedScrollHosts` and `trackScrollTop` from `'../../../../testing/scroll-testing'` and `ScrollHosts` from `'../../../shared/scroll-host/scroll-hosts.service'`; in `mount()`, insert `      { provide: ScrollHosts, useClass: ElementBackedScrollHosts },` directly before `      ...providers,`. Then add, as the last block inside `describe('EntryListComponent', …)`:

```ts
  describe('on the page (#2038)', () => {
    it('collapses its header when the document scrolls', () => {
      const fixture = mount({}, [{ provide: ScrollHosts, useClass: ScrollHosts }]);
      Object.defineProperty(document, 'scrollingElement', {
        value: document.documentElement,
        configurable: true,
      });
      const page = trackScrollTop(document.documentElement);

      page.top = 100;
      window.dispatchEvent(new Event('scroll'));
      page.top = 800;
      window.dispatchEvent(new Event('scroll'));
      fixture.detectChanges();

      expect(fixture.componentInstance.collapsed()).toBe(true);
    });
  });
```

In the same spec's `describe('the list progress rail (#1392)')` the scrollbar is now the page's: in `'stands on the right edge in place of the scrollbar on a phone'` replace `      expect(scroller.classList).toContain('scrollbar-hidden');` with `      expect(document.documentElement.classList).toContain('page-scrollbar-hidden');`; in `'keeps the scrollbar of a paged search, which never gets a rail'` replace `      expect(scroller.classList).not.toContain('scrollbar-hidden');` with `      expect(document.documentElement.classList).not.toContain('page-scrollbar-hidden');`; and append to that describe:

```ts
    it('gives the page its scrollbar back when the list goes', () => {
      const fixture = mount(pagedList);
      scrollTo(fixture, layOut(fixture, 100, 1100), 0);
      expect(document.documentElement.classList).toContain('page-scrollbar-hidden');

      fixture.destroy();

      expect(document.documentElement.classList).not.toContain('page-scrollbar-hidden');
    });
```

(Task 2 already replaced the wide-layout test with `'stands on the right edge on a wide layout too'`, which asserts no scrollbar class; leave it.)

`reader-shell.component.spec.ts`: add `import { trackScrollTop } from '../../../testing/scroll-testing';`, and replace

```ts
  /** Drive the entry list's real scroll container: assign an offset and fire the
   *  scroll event `onRowsScroll` handles, updating the `collapsed` signal the app
   *  bar mirrors — tests must scroll the element the component actually consults. */
  function listScroller(fixture: ReturnType<typeof boot>) {
    const element = (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>('.rows')!;
    return {
      el: element,
      scrollTo(top: number): void {
        element.scrollTop = top;
        element.dispatchEvent(new Event('scroll'));
      },
    };
  }
```

with

```ts
  /** Drive the entry list's real scroller, the page (#2038): set the document's offset
   *  and fire the window scroll its PageScrollHost hears. */
  function listScroller() {
    Object.defineProperty(document, 'scrollingElement', {
      value: document.documentElement,
      configurable: true,
    });
    const page = trackScrollTop(document.documentElement);
    return {
      scrollTo(top: number): void {
        page.top = top;
        window.dispatchEvent(new Event('scroll'));
      },
    };
  }
```

then: `perl -pi -e 's/listScroller\(fixture\)/listScroller()/g' src/app/reader/shell/reader-shell.component.spec.ts`.

- [ ] **Step 2: Run the specs to see the new test fail and the rest hold**

Run: `./node_modules/.bin/jest src/app/reader/list/entry-list src/app/reader/shell/reader-shell.component.spec.ts --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL — `on the page (#2038) › collapses its header when the document scrolls` (the list still listens to `.rows`) and the shell's hide-on-scroll tests that drive `listScroller()` (same reason). Every other entry-list test passes on `ElementBackedScrollHosts`.

- [ ] **Step 3: The list takes the page host**

`entry-list.component.ts` lines 209–212, replace

```ts
  private readonly scrollHost = computed((): ScrollHost | undefined => {
    const rows = this.rows()?.nativeElement;
    return rows ? this.scrollHosts.forElement(rows) : undefined;
  });
```

with

```ts
  private readonly scrollHost = computed((): ScrollHost | undefined => {
    const rows = this.rows()?.nativeElement;
    return rows ? this.scrollHosts.forPage(rows) : undefined;
  });
```

Directly after `progressRail` is declared, add the page-scrollbar effect (import `DOCUMENT` from `@angular/common` and `DestroyRef` from `@angular/core` if not already imported):

```ts
  private readonly _hidePageScrollbar = (() => {
    const root = inject(DOCUMENT).documentElement;
    inject(DestroyRef).onDestroy(() => root.classList.remove('page-scrollbar-hidden'));
    return effect(() =>
      root.classList.toggle('page-scrollbar-hidden', this.progressRail.replacesScrollbar()),
    );
  })();
```

- [ ] **Step 4: The list's chrome moves into two fixed frames**

`entry-list.component.html`:

1. Replace lines 1–74 (from the opening comment through the closing `}` of `@if (error(); as problem)`) with the lead frame followed by the search status, which stays outside it:

```html
<div class="list-frame lead">
  <!-- Floats over the scroller's reserved top padding rather than sitting above
       it in the flow, so collapsing the bar never resizes the scroller and the
       rows stay put under the finger (#87). -->
  <header
    class="list-header"
    #listHdr
    [class.collapsed]="collapsed()"
    [class.is-search]="selection().kind === 'search'"
  >
    <app-list-header
      [selection]="selection()"
      [title]="title()"
      [searchTitlePrefix]="searchTitlePrefix()"
      [searchTitleTerm]="searchTitleTerm()"
      [searchCountLabel]="searchCountLabel()"
      [titleCount]="titleCount()"
      [titleTag]="titleTag()"
      [titleFaviconUrl]="titleFaviconUrl()"
      [titleAvatarUrl]="titleAvatarUrl()"
      [lastRefreshed]="lastRefreshed()"
      [nextRefresh]="nextRefresh()"
      [headerLeading]="headerLeading()"
      [leadingActions]="leadingActions()"
      [headerActions]="headerActions()"
      [collapsed]="collapsed()"
      [refreshing]="refreshing()"
      [canMarkAllRead]="canMarkAllRead()"
      (refresh)="refresh.emit()"
      (orderChange)="orderChange.emit($event)"
      (unreadOnlyChange)="unreadOnlyChange.emit($event)"
      (markAllRead)="markAllRead.emit()"
    />
  </header>

  @if (revealVisible()) {
    <div
      class="pull-indicator"
      [class.armed]="pull.pullArmed()"
      [class.dragging]="pull.dragging()"
      [style.transform]="pull.revealTransform()"
      aria-hidden="true"
    >
      <div class="pill">
        <app-spinner [size]="20" [decorative]="true" [animate]="pull.pullArmed() || refreshing()" />
        @if (refreshing()) {
          <span class="label">{{ 'reader.refreshing' | transloco }}</span>
        }
      </div>
    </div>
  }

  @if (error(); as problem) {
    <app-error-banner
      class="list-error text-scaled"
      [message]="
        problem.status === 0
          ? ('reader.errorOffline' | transloco)
          : ('reader.errorServer' | transloco: { status: problem.status })
      "
      [actionLabel]="'reader.retry' | transloco"
      [dismissLabel]="'reader.dismiss' | transloco"
      (action)="retry.emit()"
      (dismiss)="dismiss.emit()"
    />
  }
</div>

@if (selection().kind === 'search') {
  <!-- Announces the count, not the rows: speaking every row would drown a
       screen-reader user in noise. It re-announces on Load more too — a
       deliberate choice, since that's a discrete manual action, not a stream. -->
  <p class="sr-only" aria-live="polite" role="status">
    {{ 'reader.searchCount' | transloco: { count: entries().length } }}
  </p>
}
```

2. In both `.rows` elements (magazine, lines 94–104, and list, lines 142–151) delete the line `    [class.scrollbar-hidden]="progressRail.replacesScrollbar()"` (Step 3's effect now hides the page's scrollbar instead).

3. Replace everything from `<app-progress-rail [progress]="progressRail" />` (line 197) to the end of the file with the tail frame:

```html
<div class="list-frame tail">
  <app-progress-rail [progress]="progressRail" />

  <app-loading-overlay [shown]="waitOverlay()" [label]="'common.loading' | transloco" />

  @let hasEntries = content.visibleEntryCount() > 0;
  @let toTopShown = scrolling.showToTop() && hasEntries;
  @if (toTopShown || cornerTrailing()) {
    <div class="end-corner">
      @if (toTopShown) {
        <app-to-top-button (activate)="scrollToTop()" />
      }
      @if (cornerTrailing(); as trailing) {
        <span class="corner-trailing"><ng-container [ngTemplateOutlet]="trailing" /></span>
      }
    </div>
  }

  @let markAllShown = canMarkAllRead() && hasEntries;
  @let markAboveShown = toTopShown && scrolling.hasAboveFold() && marksAboveFold();
  @if (markAllShown || markAboveShown) {
    <div class="corner-actions">
      @if (markAllShown) {
        <button
          type="button"
          class="mark-all"
          (click)="markAllRead.emit()"
          [attr.aria-label]="'reader.markAllRead' | transloco"
          [attr.title]="'reader.markAllRead' | transloco"
        >
          <app-icon name="done_all" size="dense" />
        </button>
      }
      @if (markAboveShown) {
        <button
          type="button"
          class="mark-above"
          (click)="onMarkAboveRead()"
          [attr.aria-label]="'reader.markAboveRead' | transloco"
          [attr.title]="'reader.markAboveRead' | transloco"
        >
          <app-icon name="playlist_add_check" size="dense" />
        </button>
      }
    </div>
  }
</div>
```

Run `npx prettier --write src/app/reader/list/entry-list/entry-list.component.html` afterwards; the markup inside the frames is the old markup re-indented, nothing else.

`entry-list.component.scss`:

Replace

```scss
/* Tail room so the last row can scroll up clear of the corner buttons. */
$corner-clear: calc(3 * (var(--space-5) + var(--corner-button-size) + var(--space-3)));

:host {
  display: flex;
  flex-direction: column;
  min-height: 0;
  height: 100%;
  position: relative; /* anchor for the pull-to-refresh indicator */
  container: entry-list / size;
}
```

with

```scss
/* Tail room so the last row can scroll up clear of the corner buttons and the audio bar. */
$corner-clear: calc(
  3 * (var(--space-5) + var(--corner-button-size) + var(--space-3)) + var(--audio-bar-h, 0rem)
);

:host {
  display: block;
}

/* The list column's visible box, pinned while the rows scroll the page (#2038); the
   shell publishes the column's box because `fixed` measures from the viewport. */
.list-frame {
  position: fixed;
  top: 0;
  bottom: var(--audio-bar-h, 0);
  left: var(--list-column-left, 0);
  width: var(--list-column-width, 100%);
  z-index: 1;
  pointer-events: none;
}

/* Above the tail frame, so the list header still covers the rail and the wait veil. */
.list-frame.lead {
  z-index: 2;
}

.list-frame.tail {
  container: entry-list / size;
}

.list-header,
.list-error,
.corner-actions {
  pointer-events: auto;
}
```

In `.rows`, replace

```scss
.rows {
  flex: 1;
  min-height: 0;
  overflow: auto;
```

with

```scss
.rows {
  /* A screen at least, so the airy sheet and a pull reach the foot of a short list. */
  min-height: calc(100dvh - var(--audio-bar-h, 0rem));
```

Replace

```scss
/* Anchored to this component's box, not the viewport: in split-pane mode a
   fixed button would hang over the reader pane. The buttons render even under
   an open article, so they must stay below `.article-overlay`'s z-index: 5. */
.corner-actions {
```

with

```scss
/* Anchored to the tail frame, so in split view they stay in the list column; the
   frame sits below `.article-overlay`'s z-index: 5, which covers them under an article. */
.corner-actions {
```

- [ ] **Step 5: The shell's chrome is fixed, its columns pinned**

`reader-shell.component.scss` (quoted blocks are the current text; replace each with the block after it):

```scss
:host {
  display: flex;
  flex-direction: column;

  /* `dvh` tracks mobile Safari's toolbar; without it the shell's bottom edge
     hides behind it, since the page itself never scrolls (an inner container
     does). The `vh` line above is the fallback for browsers without `dvh`. */
  height: 100vh;
  height: 100dvh;

  /* Clip the header when it slides up off the top (hide-on-scroll), and anchor
     it: it is positioned against this box, not the viewport. */
  overflow: hidden;
  position: relative;
}
```
→
```scss
/* The list scrolls the page (#2038): the shell grows with it and its chrome is fixed. */
:host {
  display: block;
}
```

In `app-reader-header { … }`: `  position: absolute;` → `  position: fixed;`.

```scss
.body {
  /* Contains the narrow drawer and its backdrop, and clips a squeezed list, so
     nothing paints over the audio player below. */
  position: relative;
  overflow: clip;
  flex: 1;
  display: flex;
  min-height: 0;
}
```
→
```scss
.body {
  /* Clips sideways overflow, a split list held at its min-width, so the page never scrolls sideways. */
  overflow-x: clip;
  display: flex;
}
```

```scss
.sidebar {
  width: calc(var(--sidebar-nav-w) + 2 * var(--space-2));
  flex: 0 0 auto;
```
→
```scss
.sidebar {
  /* Pinned beside the list while the list scrolls the page; `.sidebar-body` scrolls inside. */
  position: sticky;
  top: 0;
  align-self: flex-start;
  height: calc(100dvh - var(--audio-bar-h, 0rem));
  width: calc(var(--sidebar-nav-w) + 2 * var(--space-2));
  flex: 0 0 auto;
```

```scss
.article-overlay {
  position: absolute;
  inset: 0;
```
→
```scss
.article-overlay {
  /* Over the list column: `fixed` measures from the viewport, so the shell publishes the column. */
  position: fixed;
  top: 0;
  bottom: var(--audio-bar-h, 0);
  left: var(--list-column-left, 0);
  width: var(--list-column-width, 100%);
```

```scss
.main.split .reader {
  position: relative;
  flex: 1;
  min-width: 0;
}
```
→
```scss
/* Pinned beside the list, which owns the page; the article scrolls inside it. */
.main.split .reader {
  position: sticky;
  top: 0;
  align-self: flex-start;
  height: calc(100dvh - var(--audio-bar-h, 0rem));
  flex: 1;
  min-width: 0;
}
```

In `.main.split .pane-divider { … }` replace

```scss
  flex: 0 0 var(--space-2);
  align-self: stretch;
  margin-inline: calc(var(--space-2) * -0.5);
  position: relative;
```

with

```scss
  flex: 0 0 var(--space-2);
  align-self: flex-start;
  height: calc(100dvh - var(--audio-bar-h, 0rem));
  margin-inline: calc(var(--space-2) * -0.5);
  position: sticky;
  top: 0;
```

In the `.under-header` block: the comment's first line `/* Nothing in normal flow can sit "below the header" (absolute, opaque). This` → `/* Nothing in normal flow can sit "below the header" (fixed, opaque). This`, and `  position: absolute;` → `  position: fixed;`.

After the `.under-header` reduced-motion block, add:

```scss
/* The shell publishes this bar's height as `--audio-bar-h`; everything pinned to the foot leaves it free. */
app-audio-player-bar {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 9;
}
```

```scss
.body.is-narrow .sidebar {
  /* Anchored to the shell host (in document flow), not the viewport: a
     `fixed` drawer stays pinned while iOS rubber-bands the page on
     pull-to-refresh, so it drifts against the header and list. */
  position: absolute;
  inset-block: 0;
  left: 0;
```
→
```scss
.body.is-narrow .sidebar {
  /* Fixed again: `overscroll-behavior-y: none` on the page ended the rubber-band drift (#2038). */
  position: fixed;
  inset-block: 0 var(--audio-bar-h, 0);
  height: auto;
  left: 0;
```

```scss
.body.is-narrow .backdrop {
  display: block;
  position: absolute;
  inset: 0;
```
→
```scss
.body.is-narrow .backdrop {
  display: block;
  position: fixed;
  inset: 0 0 var(--audio-bar-h, 0);
```

`sidebar/sidebar.component.scss`, in `.sidebar-body { … }` after `  overflow: auto;` add `  overscroll-behavior: contain;`.

`article-layer/article-layer.component.scss`, in `.scroller { … }` after `  overflow: auto;` add

```scss

  /* At its end, a wheel or touch must not go on to scroll the list behind (#2038). */
  overscroll-behavior: contain;
```

Fixed-ancestor check (#100), for the record in the commit body: the app bar, `.under-header` and the audio bar are children of the untransformed shell host; the list frames sit under `.main` (no transform, #100) › `.list` › `app-entry-list` (no transform; `.rows`' pull transform is a sibling, not an ancestor); the drawer and backdrop under `.body` (no transform since this change); `.article-overlay` is itself the transformed box, as before.

- [ ] **Step 6: Run the suites, the typecheck and Stylelint**

Run: `./node_modules/.bin/jest src/app/reader src/app/shared src/app/core --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS.
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`
Expected: no output.
Run: `npx stylelint "src/**/*.scss"`
Expected: no output.

- [ ] **Step 7: Grep for what the switch must have removed**

Run: `grep -rn "position: sticky\|scrollIntoView" src/app/reader/list --include='*.scss' --include='*.ts' | grep -v spec`
Expected: no output.
Run: `grep -n "scrollbar-hidden\|overflow: auto\|container: entry-list" src/app/reader/list/entry-list/entry-list.component.{html,scss}`
Expected: exactly one line — `container: entry-list / size;` inside `.list-frame.tail`.

- [ ] **Step 8: Smoke it in a browser before committing**

Serve the worktree (Global Constraints, *Serving this worktree*) and at 375×812 and 1440×900 confirm: the list scrolls the document (`window.scrollY` moves in the console), the header and list header stay on screen and retract on a phone, the rail sits at the list column's right edge, the corner discs sit above the audio bar when a track is loaded, the split pane and sidebar stay put. Fix before committing; this is the one commit that can leave the reader unusable.

- [ ] **Step 9: Commit**

```bash
git add frontend/src
git commit -m "feat(#2038): the list scrolls the page at every width; its chrome is fixed, the side columns pinned" -m "Fixed elements checked for a transformed ancestor (#100): app bar, .under-header and audio bar sit under the shell host; the list frames under .main > .list > app-entry-list; drawer and backdrop under .body; .article-overlay is itself the transformed box, as before."
```

---

### Task 6: Playwright — the page scroll, and the specs that scrolled `.rows`

**Files:**
- Create: `frontend/e2e/page-scroll.spec.ts`
- Modify: `frontend/e2e/header-scroll-mobile.spec.ts:62-63` and every `rows.evaluate(`
- Modify: `frontend/e2e/list-scroll-reset.spec.ts:96-103,130-147`
- Modify: `frontend/e2e/list-order.spec.ts:136-139`
- Modify: `frontend/e2e/reading-focus-orientation.spec.ts:59-66,113-115`

**Interfaces:**
- Consumes: `signInWithLayout(page, layout)` (`e2e/support/auth.ts`), `entryWire`, `entryDetailJson`, `readerFailedJson` (`e2e/support/reader.ts`); the classes `.list-frame`, `.main.split > .list`, `.main.split .reader`, `aside.sidebar`, `.pane-divider`, `.article-overlay`, `app-reader-view .bar .close`.

- [ ] **Step 1: Write the new spec**

```ts
// frontend/e2e/page-scroll.spec.ts
import { test, expect, Page } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson } from './support/reader';

// The list scrolls the document at every width (#2038): a mobile browser collapses its
// bar only for a page scroll. Every entry is stubbed, so the spec owns what it scrolls.
const PHONE = { width: 375, height: 812 };
const WIDE = { width: 1440, height: 900 };
const LIST_OFFSET = 1200;

const ENTRIES = Array.from({ length: 40 }, (_, i) =>
  entryWire({
    id: i + 1,
    title: `Entry number ${i + 1}`,
    url: `https://example.invalid/${i + 1}`,
    summary: 'A summary long enough to give the row some height. '.repeat(3),
    excerpt: 'A summary long enough to give the row some height. '.repeat(3),
    subscriptionId: 5,
    source: 'stub',
  }),
);

const TALL_BODY = Array.from(
  { length: 40 },
  (_, i) => `<p>Paragraph ${i} of filler text, long enough that the article overflows its pane.</p>`,
).join('');

async function stubReader(page: Page): Promise<void> {
  await page.route('**/api/tags', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { tags: [] } });
  });
  await page.route('**/api/entries/*/state', async (route) => {
    await route.fulfill({
      status: 200,
      json: {
        state: { entryId: 1, isHidden: true, isFavorite: false, isKept: false, hiddenAt: 'x' },
      },
    });
  });
  await page.route('**/api/entries/*/reader', async (route) => {
    await route.fulfill({ status: 200, json: readerFailedJson('unextractable') });
  });
  await page.route(
    (url) => /^\/api\/entries\/\d+$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const id = Number(new URL(route.request().url()).pathname.split('/').pop());
      const match = ENTRIES.find((each) => each.id === id) ?? ENTRIES[0];
      await route.fulfill({ status: 200, json: entryDetailJson(match, TALL_BODY) });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
}

const scrollY = (page: Page): Promise<number> => page.evaluate(() => window.scrollY);

async function scrollPageTo(page: Page, top: number): Promise<void> {
  await page.evaluate((to) => window.scrollTo({ top: to, behavior: 'instant' }), top);
  await expect.poll(() => scrollY(page)).toBe(top);
}

/** The title of a row in the middle third, so clicking it scrolls nothing into view. */
function rowTitleInView(page: Page): Promise<string> {
  return page.evaluate(() => {
    const titles = Array.from(document.querySelectorAll<HTMLElement>('app-entry-row .title'));
    const third = window.innerHeight / 3;
    const visible = titles.find((title) => {
      const box = title.getBoundingClientRect();
      return box.top > third && box.bottom < 2 * third;
    });
    return visible?.textContent?.trim() ?? '';
  });
}

async function dragDivider(page: Page, deltaX: number): Promise<void> {
  const box = (await page.locator('.pane-divider').boundingBox())!;
  const centreX = box.x + box.width / 2;
  const centreY = box.y + box.height / 2;
  await page.mouse.move(centreX, centreY);
  await page.mouse.down();
  await page.mouse.move(centreX + deltaX, centreY, { steps: 8 });
  await page.mouse.up();
}

test.describe('The list scrolls the page on a phone', () => {
  test.use({ viewport: PHONE });

  test.beforeEach(async ({ page }) => {
    await stubReader(page);
    const signedIn = await signInWithLayout(page, 'list');
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');
    await expect(page.locator('app-entry-row').first()).toBeVisible();
  });

  test('scrolling the list moves the document, and the app bar retracts', async ({ page }) => {
    await scrollPageTo(page, LIST_OFFSET);
    await expect(page.locator('app-reader-header')).toHaveClass(/hidden/);
    expect(await page.locator('.rows').evaluate((rows) => rows.scrollTop)).toBe(0);
  });

  test('the rail fills at the column edge, with no page scrollbar', async ({ page }) => {
    await scrollPageTo(page, LIST_OFFSET);
    const rail = page.locator('app-entry-list app-progress-rail');
    await expect(rail).not.toHaveClass(/idle/);
    const filled = await rail.evaluate((el) => parseFloat(el.style.getPropertyValue('--rail-fill')));
    expect(filled).toBeGreaterThan(0);
    const box = (await rail.boundingBox())!;
    expect(box.x + box.width).toBeCloseTo(PHONE.width, 0);
    const scrollbar = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('scrollbar-width'),
    );
    expect(scrollbar).toBe('none');
  });

  test('a full-screen article pins the list, and back lands on its exact offset', async ({
    page,
  }) => {
    await scrollPageTo(page, LIST_OFFSET);
    const title = await rowTitleInView(page);
    expect(title, 'no row in the middle third — the case proves nothing').not.toBe('');

    await page.getByText(title, { exact: true }).first().click();
    await expect(page.locator('.article-overlay app-reader-view')).toBeVisible();
    await expect(page.locator('html')).toHaveClass(/cdk-global-scrollblock/);
    await expect(page.locator('app-reader-view .scroller')).toHaveJSProperty('scrollTop', 0);

    await page.locator('app-reader-view .bar .close').click();
    await expect(page.locator('.article-overlay')).toHaveCount(0);
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect(await scrollY(page)).toBe(LIST_OFFSET);
  });

  test('the open drawer pins the page', async ({ page }) => {
    await scrollPageTo(page, LIST_OFFSET);
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await expect(page.locator('html')).toHaveClass(/cdk-global-scrollblock/);

    await page
      .locator('.backdrop')
      .click({ position: { x: PHONE.width - 10, y: PHONE.height / 2 } });
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect(await scrollY(page)).toBe(LIST_OFFSET);
  });
});

test.describe('The list scrolls the page beside a pinned reader pane', () => {
  test.use({ viewport: WIDE });

  test.beforeEach(async ({ page }) => {
    await stubReader(page);
    const signedIn = await signInWithLayout(page, 'pane');
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');
    await page.getByText('Entry number 1', { exact: true }).click();
    await expect(page.getByText('Paragraph 0 of filler text').first()).toBeVisible();
  });

  test('the document moves while the sidebar and the reader pane stay put', async ({ page }) => {
    const sidebar = page.locator('aside.sidebar');
    const pane = page.locator('.main.split .reader');
    const sidebarBefore = (await sidebar.boundingBox())!;
    const paneBefore = (await pane.boundingBox())!;

    await scrollPageTo(page, LIST_OFFSET);

    expect((await sidebar.boundingBox())!.y).toBe(sidebarBefore.y);
    expect((await pane.boundingBox())!.y).toBe(paneBefore.y);
  });

  test('a wheel at the reader pane end does not scroll the list', async ({ page }) => {
    const scroller = page.locator('.main.split .reader app-reader-view .scroller');
    expect(
      await scroller.evaluate((el) => el.scrollHeight > el.clientHeight),
      'the pane does not overflow — the case proves nothing',
    ).toBe(true);
    await scroller.evaluate((el) => el.scrollTo({ top: el.scrollHeight, behavior: 'instant' }));
    await scrollPageTo(page, 400);

    const paneBox = (await scroller.boundingBox())!;
    await page.mouse.move(paneBox.x + paneBox.width / 2, paneBox.y + paneBox.height / 2);
    await page.mouse.wheel(0, 800);
    await page.waitForTimeout(300);
    expect(await scrollY(page)).toBe(400);

    // The control: the same wheel over the list does move the page.
    const listBox = (await page.locator('.main.split > .list').boundingBox())!;
    await page.mouse.move(listBox.x + listBox.width / 2, paneBox.y + paneBox.height / 2);
    await page.mouse.wheel(0, 800);
    await expect.poll(() => scrollY(page)).toBeGreaterThan(400);
  });

  test('the list rail sits at the column edge and follows a divider drag', async ({ page }) => {
    const rail = page.locator('app-entry-list app-progress-rail');
    const column = page.locator('.main.split > .list');
    await expect(rail).not.toHaveClass(/idle/);
    const rightEdge = async (target: typeof rail): Promise<number> => {
      const box = (await target.boundingBox())!;
      return box.x + box.width;
    };

    expect(Math.abs((await rightEdge(rail)) - (await rightEdge(column)))).toBeLessThanOrEqual(1);
    const before = await rightEdge(rail);

    await dragDivider(page, -120);

    await expect
      .poll(async () => Math.abs((await rightEdge(rail)) - (await rightEdge(column))))
      .toBeLessThanOrEqual(1);
    expect(await rightEdge(rail)).toBeLessThan(before - 60);
  });
});
```

- [ ] **Step 2: Port the specs that scrolled `.rows`**

`header-scroll-mobile.spec.ts` lines 62–63:

```ts
/** The list's own scroll container — the scroller that drives the app bar. */
const ROWS = '.rows';
```
→
```ts
/** The list's rows; the page they lie on is what scrolls (#2038). */
const ROWS = '.rows';
/** The list's scroller: the document, through its scrolling element. */
const PAGE = 'html';
```

then `perl -pi -e 's/\brows\.evaluate\(/page.locator(PAGE).evaluate(/g' e2e/header-scroll-mobile.spec.ts` (every `rows.evaluate` there reads or writes the list's scroll offset; `rows` stays for the `toBeVisible` checks).

`list-scroll-reset.spec.ts` lines 96–103:

```ts
/** The list's own scroller — the shell locks the page and scrolls this instead. */
function listScroller(page: Page) {
  return page.locator('app-entry-list .rows').first();
}

function scrollTop(page: Page): Promise<number> {
  return listScroller(page).evaluate((el) => el.scrollTop);
}
```
→
```ts
/** The list scrolls the page (#2038). */
function scrollTop(page: Page): Promise<number> {
  return page.evaluate(() => window.scrollY);
}
```

and in `scrollListTo` replace

```ts
  await listScroller(page).evaluate(
    (el, to) =>
      new Promise<void>((resolve) => {
        if (el.scrollTop === to) return resolve();
        el.addEventListener('scroll', () => resolve(), { once: true });
        el.scrollTo({ top: to });
      }),
    top,
  );
```

with

```ts
  await page.evaluate(
    (to) =>
      new Promise<void>((resolve) => {
        if (window.scrollY === to) return resolve();
        window.addEventListener('scroll', () => resolve(), { once: true });
        window.scrollTo({ top: to });
      }),
    top,
  );
```

`list-order.spec.ts` lines 136–139:

```ts
    await page
      .locator('app-entry-list .rows')
      .first()
      .evaluate((el) => el.scrollTo({ top: 1500 }));
```
→
```ts
    await page.evaluate(() => window.scrollTo({ top: 1500 }));
```

`reading-focus-orientation.spec.ts`, in `readingFocusEdges` replace

```ts
    const scroller = document.querySelector('.rows') as HTMLElement;
    const scrollerTop = scroller.getBoundingClientRect().top;
    const edge = scroller.clientHeight * bandBottom;
```

with

```ts
    const scroller = document.querySelector('.rows') as HTMLElement;
    const scrollerTop = 0;
    const edge = window.innerHeight * bandBottom;
```

and replace

```ts
    await page.locator('.rows').evaluate((el) => {
      el.scrollTo({ top: (el.scrollHeight - el.clientHeight) / 2, behavior: 'instant' });
    });
```

with

```ts
    await page.locator('html').evaluate((el) => {
      el.scrollTo({ top: (el.scrollHeight - el.clientHeight) / 2, behavior: 'instant' });
    });
```

- [ ] **Step 3: Typecheck and run them against the worktree build**

Run: `npm run typecheck:e2e`
Expected: no output.

Playwright's config points at `:4200`, which Docker serves from the **main** checkout, and its global setup refuses a worktree (the #615 stack-ownership preflight). With the worktree server running (Global Constraints, *Serving this worktree*), write an **untracked** config next to the real one — never commit it:

```bash
cat > playwright.worktree.config.ts <<'EOF'
import { defineConfig } from '@playwright/test';
import base from './playwright.config';

export default defineConfig({
  ...base,
  globalSetup: undefined,
  webServer: undefined,
  use: { ...base.use, baseURL: 'http://localhost:4210' },
});
EOF
```

Run: `npx playwright test --config playwright.worktree.config.ts e2e/page-scroll.spec.ts e2e/header-scroll-mobile.spec.ts e2e/list-scroll-reset.spec.ts e2e/list-order.spec.ts e2e/reading-focus-orientation.spec.ts e2e/pull-to-refresh-mobile.spec.ts e2e/article-reading-progress.spec.ts e2e/pane-split-resize.spec.ts`
Expected: all pass. A skip saying "seeded admin login unavailable" means the shared stack lacks the admin: run `docker compose exec php bin/console app:e2e:seed-admin` and `docker compose exec php bin/console app:e2e:seed-admin-subscription` from the repo root, then rerun. `pull-to-refresh-mobile` and `pane-split-resize` are unchanged and must still pass on the page.
Then: `rm playwright.worktree.config.ts`.

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e
git commit -m "test(#2038): the list scrolls the page on a phone and beside a pinned pane; specs scroll the page, not .rows"
```

---

### Task 7: Record the convention, live check, simplify, PR

**Files:**
- Modify: `docs/design-language.md` (section `### Sticky and scroll`, after its first paragraph and code block)

- [ ] **Step 1: Record the reader-page convention**

In `docs/design-language.md`, directly after the `:host { position: sticky; … }` code block of "Sticky and scroll", insert:

```markdown
**The reader scrolls the page (#2038).** The list's rows lie on the document; its chrome —
app bar, `.under-header`, list header, rail, corner discs, audio bar — is `position: fixed`.
List chrome sits in the entry list's two `.list-frame`s, placed by `--list-column-left` and
`--list-column-width`, which the shell measures off the `.list` column because `fixed`
measures from the viewport; everything pinned to the foot leaves `--audio-bar-h` free. The
sidebar column, the pane divider and split view's reader pane are sticky flex children
(`top: 0`, a viewport-tall height, `align-self: flex-start`) whose scrollers set
`overscroll-behavior: contain`. A new fixed element is checked for a transformed, filtered or
contained ancestor (#100).
```

Commit:

```bash
git add docs/design-language.md
git commit -m "docs(#2038): the reader page in the sticky-and-scroll conventions"
```

- [ ] **Step 2: Live check from this worktree**

Docker's `:4200` serves the main checkout. Serve this worktree against the running Docker backend with a scratch proxy (not committed):

```bash
cat > /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/384082d0-e8fb-43c8-9349-56301e4c76a1/scratchpad/proxy-2038.json <<'EOF'
{ "/api": { "target": "https://localhost:8443", "secure": false, "headers": { "Host": "localhost" } },
  "/state": { "target": "https://localhost:8443", "secure": false, "headers": { "Host": "localhost" } } }
EOF
npx ng serve --port 4210 --proxy-config /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/384082d0-e8fb-43c8-9349-56301e4c76a1/scratchpad/proxy-2038.json
```

Logged in with the demo account, check and note each result:
- **Phone 375×812:** the list scrolls the page; app bar and list header retract and return; the rail fills on the right with no native scrollbar; to-top appears past a screen and works; pull-to-refresh fires at the top; reading focus follows when enabled; corner discs sit clear of the audio bar with a track loaded, and hide when the playlist squeezes the column; the drawer opens over a still page and closes with the list in place; a full-screen article opens with the list pinned under it, swipe-back shows the list at its offset, back lands exactly.
- **Wide 1440×900, list/magazine layout:** the sidebar stays put; hiding/showing it moves the list header, rail and corners with the column; a full-screen article covers exactly the list column below the app bar.
- **Wide, pane layout:** the divider drag moves the rail and the list header with the column; a wheel at the reader pane's end and over a **short, non-overflowing** article leaves the list still (risk 7 — if the short case leaks in Chromium, report it, do not patch silently); the sidebar's own scroll does not leak into the list at its end.
- **Dialogs:** open a confirm dialog (Mark all read) and an action sheet over a scrolled list and over an open article: nothing jumps, the app bar does not flip, the rail does not repaint full on close.
- **Leaving:** scroll the list, open Settings: Settings opens at the top; back to the reader restores the list offset.
- Scan the browser console for errors, and the dev log: `ls -t ../backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`.

- [ ] **Step 3: `/simplify`, the gate, the build**

Run `/simplify` over the branch diff and apply what it finds. Then from `frontend/`:

Run: `npm run check`
Expected: all green (ESLint, Prettier, Stylelint, tsc for app/spec/e2e, Jest).
Run: `npm run build`
Expected: success.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin feature/2038-page-scroll-phase-2
gh pr create --base develop --title "feat(#2038): the list scrolls the page at every width" --body "$(cat <<'EOF'
Phase 2 of #2038 (spec: docs/superpowers/specs/2026-11-05-2038-page-scroll-on-narrow-design.md).

- `PageScrollHost` reads the document as a scroller; the entry list takes it at every width,
  so mobile browsers collapse their bar while the list scrolls. A CDK block (dialog, drawer,
  full-screen article) is invisible to its consumers: the pinned offset is reported, scroll
  events are swallowed, a write lands after the release.
- The shell's and the list's chrome is `position: fixed`; list chrome rides in two frames placed
  by `--list-column-left`/`--list-column-width`, which the shell publishes with `--audio-bar-h`.
- The sidebar column, pane divider and split reader pane are pinned (sticky) with `contain`
  scrollers; the narrow drawer is fixed and pins the page while open.
- The progress rail stands at the right edge at every width; the page scrollbar is hidden on
  the reader route, where overscroll is off and history scroll restoration is manual.
- Full-screen articles still scroll inside their overlay, over a pinned list; phase 3 hands
  them the page.

Known cost: a paged search shows no scroll cue until its last page loads (no total for the rail,
no page scrollbar). #1332's first-tap-stops-the-coast returns for fixed chrome, as the spec accepts.

Refs #2038
EOF
)"
```

If PR #2039 had not merged when the branch was cut (see **Branch**), use `--base feature/2038-page-scroll-on-narrow` and retarget to `develop` after #2039 merges. The body says `Refs`, not `Closes`: #2038 stays open for phases 3–4.
