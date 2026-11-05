# #2038 Phase 3 — the article handoff — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** At every width a full-screen article, and the link reader, scrolls the document instead of an inner `.scroller`, so a mobile browser collapses its bar while you read. The list underneath is held at its exact offset, and back (button, swipe-back, end-pull, browser back) puts it there before the next paint.

**Architecture:** A shell-provided `PageScrollOwner` keeps one page and a stack of `PageLayer`s. The `[appPageLayer]` directive registers the list column and each `.article-overlay`. The top layer sits in the page's flow. Every layer beneath it is pinned: it gets `.page-pinned`, which is `position: fixed` in the list column's box, shifted up by `--page-pin-top`. A pinned layer's `PageScrollHost` reports the pin, hears no scroll and moves the pin on a write, the same contract phase 2 gave it under a CDK block.

When an overlay leaves, the owner does three things in one synchronous call inside the overlay directive's `DestroyRef`:
1. It pins the leaving layer, so `article-leave` plays in place.
2. It unpins the layer beneath.
3. It writes that layer's offset to the page.

The article layer knows it is on the page because its `PageLayer` reaches it through the overlay outlet's `injector`. On the page, its chrome moves into two zero-height sticky wrappers, which survive the swipe and slide transforms that would trap a `fixed` box. Split view keeps its element scroller. Moving between split and full screen remounts both panes; their remembered offsets carry the position across.

**Tech Stack:** Angular 20.3 (standalone components, signals, zone.js, `animate.leave`, `NgTemplateOutlet`'s `injector`), `@angular/cdk` 20.2 overlay block strategy, Jest (jsdom), Playwright (Chromium), SCSS + Stylelint.

**Spec:** `docs/superpowers/specs/2026-11-05-2038-page-scroll-on-narrow-design.md`. This plan is Delivery item 3, "Article handoff". It covers §2 `PageScrollOwner`, §4's CDK checks, and Risks 2–4. Phase 2's plan (`docs/superpowers/plans/2026-11-05-2038-phase-2-list-on-the-page.md`) is the base this builds on.

**Branch:** `feature/2038-page-scroll-phase-3`, already checked out in the main checkout at `develop` 01a09048b. There is **no worktree**: Docker's `:4200` serves this checkout, so Playwright and the live check run against it directly.

## Open questions the spec does not settle — decided here

1. **How a pinned layer is placed.** The spec says `position: fixed; top: -<offset>px; inset-inline: 0`.
   - **Decision:** `.page-pinned { position: fixed; top: calc(-1 * var(--page-pin-top)); left: var(--list-column-left); width: var(--list-column-width) }`. `--page-pin-top` is the page offset minus the layer's document top, measured when the layer is pinned.
   - **Why:** use the column box, not `inset-inline: 0`. On a wide screen the list column sits beside the sidebar, and a full-width pin would cover it.
   - **Cost:** the shell must publish the column box from a box that stays in flow (decision 9).
2. **How the article's chrome follows the page.** This covers the toolbar, the nameplate, the rail, the corner discs and the dock.
   - **The problem:** while the article is in flow, its `.frame` takes the swipe transform and `.article-overlay` takes the enter and leave transforms. Either would become the containing block of a `fixed` child (#100).
   - **Decision:** two zero-height wrappers inside `.frame`. `.chrome-head` comes before the scroller and holds the nameplate, the toolbar and the rail. `.chrome-foot` comes after it and holds the corners, the dock and the end corner.
   - **In the split pane** they are `position: absolute` over the pane, so they look exactly as they do today.
   - **On the page** they are `position: sticky` (head at `top: 0`, foot at `bottom: var(--audio-bar-h)`). Sticky follows the page, and an ancestor's transform only moves it visually, so the chrome slides with the frame and the overlay, as it does today.
   - **While pinned** (`:host-context(.page-pinned)`) they turn `absolute` at `--page-pin-top`, because sticky inside a `fixed` box is not reliable across engines.
   - **Cost:** two wrapper elements, and the rail moves earlier in the DOM. It is not focusable, so tab order is unchanged.
   - **Rejected:** `fixed` wrappers. They would need the enter animation moved off `.article-overlay`, and that element must keep it: `animate.leave` waits only on the element's own animations, and `e2e/article-slide-out.spec.ts` pins it there.
3. **How an article layer learns it owns the page.**
   - **Decision:** `[appPageLayer]` provides a `PageLayer` and exports its `injector`. Each overlay outlet passes it: `*ngTemplateOutlet="readerPane; context: {…}; injector: entryLayer.injector"`.
   - `ArticleLayerComponent` and `EntryListComponent` call `inject(PageLayer, { optional: true })` and pass the result to `ScrollHosts.forPage(content, layer)`. The split pane gets no layer and keeps `forElement`.
   - **Cost:** one `injector:` per overlay outlet.
   - **Rejected:** a fourth `pageLayer` input threaded through reader-view, link-reader, article-page and article-layer.
4. **The split ↔ full-screen host swap (Risk 3).** The shell's `@if (splitView())` already destroys and recreates the list and the article on the switch. There is no in-place host swap; each remount lands on its remembered offset:
   - **The article:** `ArticleScrollRestore` restores it. This is unchanged: `remember()` saves on every scroll, and `reseat()` re-lands on the new instance's `decorated`.
   - **The list:** `ListScrollState` restores it from `ListScrollMemory` (Task 5). Today a remount restores nothing and relies on the page offset surviving. It no longer survives, because the article owned the page, and a forced layout in the middle of a remount can clamp it.
   - **The owner tolerates any destroy order:** a layer that leaves from beneath the top changes nothing on the page.
   - **Cost:** the remount re-renders both panes, which happens today too.
5. **What replaces "the list must not move under the article".** Phase 2 got this from the CDK block.
   - **Decision:** `PageScrollOwner` pins the list. Its element is `fixed`, and its `PageScrollHost` reports the pin, hears nothing and moves the pin on a write. A list reload under an open article therefore restores into the pin, and the page takes that offset on unpin.
   - `holdReaderPage` keeps only `sidebarOpen() && screen.isNarrow()`.
6. **The page scrollbar while an article owns the page.** Phase 2 hides it only while the list's rail replaces it. An article's rail always knows its length.
   - **Decision:** the owner puts `page-overlaid` on `<html>` while more than one layer is stacked, and `_base.scss` hides the scrollbar for `page-scrollbar-hidden` **or** `page-overlaid`.
   - **Cost:** one class.
7. **The audio bar against an in-flow article.** A narrow overlay (z 12) used to end above the audio bar. In flow, its page runs on beneath the bar.
   - **Decision:** the audio bar goes from z-index 9 to **13**, and an article on the page pads its end by `--audio-bar-h`.
8. **Writes the owner makes under a CDK block.** An article can close while a sheet still holds the page.
   - **Decision:** the owner writes through its own unlayered `PageScrollHost`, which phase 2 taught to hold a write and land it after the release. The owner listens once so the host's release observer is armed.
9. **The list column box while the list is pinned.** A pinned `.list` is sized from `--list-column-width`, so measuring it would feed back its own stale value on a resize or rotation.
   - **Decision:** outside split view the shell measures `main`, which is the list column's box and stays in flow. In split view it measures `.list`, which is never pinned there.
10. **Before-paint ordering (Risk 2).** Angular 20.3 calls a directive's `DestroyRef` callbacks synchronously in the change detection that removes its `@if` view. `animate.leave` defers only the node's removal (`debug_node.mjs`: `destroyLView` → `executeOnDestroys`; `runLeaveAnimations` keeps the element).
    - **Decision:** `PageScrollOwner.leave` pins, unpins and writes `scrollTop` synchronously in that callback. No `requestAnimationFrame`, no microtask. Jest pins the order.
11. **`ScrollHost.scrollPaddingTop()` on the page (Risk 4).** Phase 2's `PageScrollHost` reads `<html>`'s `scroll-padding-top`, which nothing sets, so a contents-list jump would land under the toolbar.
    - **Decision:** it reads its `content` (the article's `.scroller` or the list's `.rows`), as `ElementScrollHost` does. The article keeps its `scroll-padding-top` rules.
    - Reading focus and sections need nothing new: on the page they measure from the viewport top (`viewportTop() === 0`), which is where the overlay's scroller started.
12. **`scrollHeight` of a pinned host.** It stays the document's.
    - Only `ListScrollState`'s settle loop reads it while the list is pinned. The loop compares heights for stability and stops after at most 30 frames.
    - **Decision:** unchanged.
13. **The e2e CDK check.** A `Dialog` (block strategy) reachable over every article is the share action sheet. It opens only on a coarse pointer.
    - **Decision:** the spec uses `test.use({ viewport: PHONE, hasTouch: true, isMobile: true })`. This makes `(pointer: coarse)` match; `sidebar-mobile.spec.ts` uses the same setup.
    - A confirm dialog and the big player are checked live (Task 9).
14. **NEEDS LARS — the device check before merge.** The point of the issue, the browser bar collapsing while reading, is only visible in Brave on the iPhone. The spec puts that check in phase 4.
    - **Recommendation:** open the PR after the `:4200` and iOS-simulator checks, and do the device check in phase 4 as the spec's Delivery says. If Lars wants the iPhone check before this merges, Task 9 Step 2 grows by that check and nothing else changes.

## Global Constraints

- **Scope.** A full-screen article (`.article-overlay`) and the link reader scroll the page at **every width**. Split view's reader pane (and a link reader in it) keeps its element scroller, with no handoff (spec §2).
- **Classes and properties.**
  - `.page-pinned` on a pinned layer, `--page-pin-top` (px) on that layer.
  - `page-overlaid` on `<html>` while more than one layer is stacked.
  - `on-page` on `app-article-layer` when it was given a `PageLayer`.
  - Phase 2's names are unchanged: `reader-page`, `page-scrollbar-hidden`, `cdk-global-scrollblock`, `--list-column-left`, `--list-column-width`, `--audio-bar-h`, `--app-bar-h`.
- **z-index.**
  - App bar `10`, `.under-header` `4`, drawer `20`, backdrop `15`.
  - `.article-overlay` is `5` wide and `12` below `bp.$bp-lg`. It is now `position: relative` in flow and `fixed` while pinned.
  - **Audio bar `13`** (was `9`).
  - `.list-frame.lead` `2`, `.list-frame.tail` `1`.
  - Inside the article layer, `.chrome-head` and `.chrome-foot` are `1`, above the isolated `.scroller`. Within them the existing values hold: `.mini` 2, `.bar` 1, corners 20, dock 21.
- **Before paint.** A layer switch — pin, unpin and page write — runs in one synchronous call. Never in `requestAnimationFrame`, `setTimeout`, `afterNextRender` or a microtask.
- **Outside the zone.** Scroll and resize listeners and observers are created outside the Angular zone (#501).
- **Styles.**
  - No hex colours.
  - No `px` in padding, margin, gap, width, height or min-height in component SCSS. Use `0rem` inside `calc()` and `var()` fallbacks for lengths.
  - Styles go in sibling `.scss` files.
  - Every new `fixed` or `sticky` element is checked for a transformed, filtered or contained ancestor (#100). The only new `fixed` box is `.page-pinned`, which has none.
- **Comments.** None by default. One line only where a reader would otherwise get the code wrong.
- **Jest.** From `frontend/`, paths before the ignore flag: `./node_modules/.bin/jest <paths> --testPathIgnorePatterns "/node_modules/" "/e2e/"`. Native Jest does no typechecking, so also run `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`, and `npm run typecheck:e2e` for e2e edits. Format touched files with `npx prettier --write <files>`.
- **Playwright.** From `frontend/`: `npx playwright test <specs>` against `:4200`, which serves this checkout.
  - A skip saying "seeded admin login unavailable" means the shared stack lacks the admin. From the repo root, run `docker compose exec php bin/console app:e2e:seed-admin`, then rerun.
  - If `:4200` serves a stale chunk, hard-reload. If it is still stale, run `docker compose restart frontend`.
- **Commits.** `type(#2038): …`, with no Claude attribution anywhere. The PR body says `Refs #2038`, never `Closes`.
- **Before the PR.** Run `/simplify`, then `npm run check` and `npm run build` from `frontend/`.

## File map

All `shared/…`, `reader/…` and `core/…` paths are under `frontend/src/app/`.

| File | Change |
|---|---|
| `shared/scroll-host/page-layer.ts` (+ spec) | **new**: `PageLayer`, `PAGE_PINNED_CLASS`, `PAGE_PIN_TOP` |
| `shared/scroll-host/page-scroll-host.ts` (+ spec) | gated by an optional `PageLayer`; `scrollPaddingTop()` reads `content` |
| `shared/scroll-host/scroll-hosts.service.ts` | `forPage(content, layer = null)` |
| `reader/shell/page-scroll-owner.ts` (+ spec) | **new**: `PageScrollOwner`, `PAGE_OVERLAID_CLASS` |
| `reader/shell/page-layer.directive.ts` (+ spec) | **new**: `[appPageLayer]` |
| `frontend/src/styles/_base.scss` | the page scrollbar is also hidden for `page-overlaid` |
| `reader/article/article-layer/article-layer.component.{html,scss,ts}` (+ spec) | head and foot chrome wrappers; page mode |
| `reader/list/entry-list/entry-list.component.ts` (+ spec) | hands its `PageLayer` to the page host |
| `reader/list/entry-list/list-scroll-state.ts` | a remounted list lands on its remembered offset |
| `reader/shell/reader-shell.component.{ts,html,scss}` (+ spec) | the switch: owner, layers, lock, geometry, overlay CSS, audio z-index |
| `frontend/e2e/page-scroll.spec.ts` | the article test pins the list instead of blocking the page; `rowTitleInView` moves to support |
| `frontend/e2e/{article-back-desktop,article-reading-progress,article-mini-header,article-tail-space,header-scroll-mobile,reading-focus-blocks,reading-focus-long-paragraph}.spec.ts` | scroll the page, not the overlay's `.scroller` |
| `frontend/e2e/support/scroll.ts` | `scrollPageToEnd`, `rowTitleInView`, `touchDrag`, `liftTouch` |
| `frontend/e2e/article-page-scroll.spec.ts` | **new**: the handoff on a phone and a wide screen, across the split, under a sheet |
| `docs/design-language.md` | "Sticky and scroll": page layers |

**Commit order.** The app works after every commit:
- **Tasks 1–5** add pieces nothing uses yet:
  - Nothing provides a `PageLayer`, so every host stays unlayered and every article layer stays in element mode.
  - The `page-overlaid` rule matches nothing.
  - Task 3's wrappers are layout-neutral in element mode.
  - Task 5 only re-lands a remounted list on the offset it already shows.
- **Task 6 is the one switch** and must land whole. Once the overlays register as layers:
  - The CDK lock on `articleFullscreen` would pin the very page the article now scrolls.
  - `.article-overlay`'s `fixed` column box would trap the in-flow article in a viewport-tall box.
  - The page-scroll e2e would assert a block that no longer happens.
- **Tasks 7–8** are e2e only.

---

### Task 1: `PageLayer`, and a pinned layer's page host

**Files:**
- Create: `frontend/src/app/shared/scroll-host/page-layer.ts`
- Create: `frontend/src/app/shared/scroll-host/page-layer.spec.ts`
- Modify: `frontend/src/app/shared/scroll-host/page-scroll-host.ts`
- Modify: `frontend/src/app/shared/scroll-host/page-scroll-host.spec.ts`
- Modify: `frontend/src/app/shared/scroll-host/scroll-hosts.service.ts`

**Interfaces:**
- Consumes: `PageScrollHost` and `PAGE_SCROLL_BLOCK_CLASS` (phase 2); `trackScrollTop` (`src/testing/scroll-testing.ts`).
- Produces:
  - `export const PAGE_PINNED_CLASS = 'page-pinned'`
  - `export const PAGE_PIN_TOP = '--page-pin-top'`
  - `export class PageLayer`:
    - `constructor(readonly element: HTMLElement)`
    - `get pinned(): boolean`
    - `get pinnedTop(): number`
    - `pinAt(top: number): void`
    - `moveTo(top: number): void`
    - `unpin(): number`
  - `new PageScrollHost(content, view, outsideZone, layer: PageLayer | null = null)`
  - `ScrollHosts.forPage(content: HTMLElement, layer: PageLayer | null = null): ScrollHost`

- [ ] **Step 1: Write the failing `PageLayer` spec**

```ts
// frontend/src/app/shared/scroll-host/page-layer.spec.ts
import { PAGE_PINNED_CLASS, PAGE_PIN_TOP, PageLayer } from './page-layer';

describe('PageLayer', () => {
  const element = document.createElement('section');

  afterEach(() => {
    jest.restoreAllMocks();
    element.className = '';
    element.removeAttribute('style');
  });

  /** The layer's box starts `documentTop` down the document while the page sits at `pageTop`. */
  function showsAt(documentTop: number, pageTop: number): void {
    jest
      .spyOn(element, 'getBoundingClientRect')
      .mockReturnValue({ top: documentTop - pageTop } as DOMRect);
  }

  it('starts in the flow', () => {
    const layer = new PageLayer(element);
    expect(layer.pinned).toBe(false);
    expect(layer.pinnedTop).toBe(0);
    expect(element.classList).not.toContain(PAGE_PINNED_CLASS);
  });

  it('pins where it shows, shifted by how far the page had scrolled into it', () => {
    showsAt(100, 1200);
    const layer = new PageLayer(element);

    layer.pinAt(1200);

    expect(layer.pinned).toBe(true);
    expect(layer.pinnedTop).toBe(1200);
    expect(element.classList).toContain(PAGE_PINNED_CLASS);
    expect(element.style.getPropertyValue(PAGE_PIN_TOP)).toBe('1100px');
  });

  it('moves its pin on a write, at once', () => {
    showsAt(100, 1200);
    const layer = new PageLayer(element);
    layer.pinAt(1200);

    layer.moveTo(900);

    expect(layer.pinnedTop).toBe(900);
    expect(element.style.getPropertyValue(PAGE_PIN_TOP)).toBe('800px');
  });

  it('ignores a write while in the flow', () => {
    const layer = new PageLayer(element);
    layer.moveTo(900);
    expect(layer.pinned).toBe(false);
    expect(element.style.getPropertyValue(PAGE_PIN_TOP)).toBe('');
  });

  it('returns its offset as it goes back into the flow', () => {
    showsAt(0, 1200);
    const layer = new PageLayer(element);
    layer.pinAt(1200);

    expect(layer.unpin()).toBe(1200);

    expect(layer.pinned).toBe(false);
    expect(element.classList).not.toContain(PAGE_PINNED_CLASS);
    expect(element.style.getPropertyValue(PAGE_PIN_TOP)).toBe('');
  });
});
```

- [ ] **Step 2: Add the failing `PageScrollHost` cases**

In `page-scroll-host.spec.ts`:

(a) Add `import { PageLayer } from './page-layer';` below the existing imports.

(b) Replace the root scroll-padding case:

```ts
  it('reads the band chrome covers from the root element', () => {
    root.style.scrollPaddingTop = '96px';
    expect(pageHost().scrollPaddingTop()).toBe(96);
  });
```

with:

```ts
  it('reads the band chrome covers from its content, like an element host', () => {
    document.body.append(content);
    content.style.scrollPaddingTop = '96px';
    expect(pageHost().scrollPaddingTop()).toBe(96);
    content.style.scrollPaddingTop = '';
    content.remove();
  });
```

(c) Directly after the closing `});` of `describe('under a CDK block', …)`, still inside `describe('PageScrollHost', …)`, add:

```ts
  describe('for a layer pinned beneath another (#2038)', () => {
    const element = document.createElement('section');
    const layerHost = (layer: PageLayer): PageScrollHost =>
      new PageScrollHost(content, window, inZone, layer);

    it('reports its pin and hears none of the page the layer above scrolls', () => {
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      const heard: number[] = [];
      host.listen(() => heard.push(host.scrollTop));

      layer.pinAt(1200);
      page.top = 300;
      scrollEvent();

      expect(host.scrollTop).toBe(1200);
      expect(host.toContentY(10)).toBe(1210);
      expect(heard).toEqual([]);
    });

    it('moves its pin on a write and leaves the page to the layer above', () => {
      const scrollTo = jest.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      layer.pinAt(1200);
      page.top = 300;

      host.scrollTop = 900;
      host.scrollTo({ top: 800, behavior: 'smooth' });

      expect(layer.pinnedTop).toBe(800);
      expect(page.top).toBe(300);
      expect(scrollTo).not.toHaveBeenCalled();
    });

    it('reads its pin, not the block, while a CDK dialog holds the page', () => {
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      layer.pinAt(1200);
      page.top = 300;

      blockAt(300);

      expect(host.scrollTop).toBe(1200);
    });

    it('is the page again once unpinned', () => {
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      layer.pinAt(1200);

      page.top = layer.unpin();

      expect(host.scrollTop).toBe(1200);
    });
  });
```

(d) In `describe('ScrollHosts.forPage', …)`, add:

```ts
  it('hands the layer it is given to the host', () => {
    const element = document.createElement('section');
    const layer = new PageLayer(element);
    layer.pinAt(700);
    const host = TestBed.inject(ScrollHosts).forPage(document.createElement('div'), layer);
    expect(host.scrollTop).toBe(700);
  });
```

- [ ] **Step 3: Run them to verify they fail**

Run: `./node_modules/.bin/jest src/app/shared/scroll-host --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL. `Cannot find module './page-layer'` in both specs.

- [ ] **Step 4: Write `PageLayer`**

```ts
// frontend/src/app/shared/scroll-host/page-layer.ts
/** The class a pinned layer carries; the reader shell's stylesheet fixes it in place. */
export const PAGE_PINNED_CLASS = 'page-pinned';
/** How far the page had scrolled into a pinned layer; its pinned box is shifted up by it. */
export const PAGE_PIN_TOP = '--page-pin-top';

/** One of the reader's page-sized layers, the list or a full-screen article (#2038): in the
 *  page's flow while it owns the page, pinned at its own offset while a layer above does. */
export class PageLayer {
  private top: number | null = null;
  private documentTop = 0;

  constructor(readonly element: HTMLElement) {}

  get pinned(): boolean {
    return this.top !== null;
  }

  /** The page offset the layer is held at, and takes again when unpinned. */
  get pinnedTop(): number {
    return this.top ?? 0;
  }

  /** Out of the flow, showing exactly what it showed with the page at `top`. */
  pinAt(top: number): void {
    this.documentTop = this.element.getBoundingClientRect().top + top;
    this.element.classList.add(PAGE_PINNED_CLASS);
    this.hold(top);
  }

  moveTo(top: number): void {
    if (this.top !== null) this.hold(top);
  }

  /** Back into the flow; returns the offset the page must take in the same task. */
  unpin(): number {
    const top = this.pinnedTop;
    this.top = null;
    this.element.classList.remove(PAGE_PINNED_CLASS);
    this.element.style.removeProperty(PAGE_PIN_TOP);
    return top;
  }

  private hold(top: number): void {
    this.top = top;
    this.element.style.setProperty(PAGE_PIN_TOP, `${top - this.documentTop}px`);
  }
}
```

- [ ] **Step 5: Gate `PageScrollHost` on its layer**

Replace `frontend/src/app/shared/scroll-host/page-scroll-host.ts` with:

```ts
import type { ScrollHost } from './scroll-host';
import type { PageLayer } from './page-layer';
import { pageScrollingElement } from '../../core/page-scrolling-element';

/** The class a CDK BlockScrollStrategy puts on `<html>` while it pins the page. */
export const PAGE_SCROLL_BLOCK_CLASS = 'cdk-global-scrollblock';

/** While a CDK block pins the page, or its layer is pinned beneath another (#2038), it reports
 *  the pinned offset, stays silent, and defers writes. */
export class PageScrollHost implements ScrollHost {
  readonly intersectionRoot = null;
  private pendingTop: number | null = null;

  constructor(
    readonly content: HTMLElement,
    private readonly view: Window,
    private readonly outsideZone: <T>(run: () => T) => T,
    private readonly layer: PageLayer | null = null,
  ) {}

  get scrollTop(): number {
    if (this.layer?.pinned) return this.layer.pinnedTop;
    if (this.blocked()) return -parseFloat(this.root.style.top) || 0;
    return this.scrollingElement.scrollTop;
  }

  set scrollTop(top: number) {
    if (this.layer?.pinned) {
      this.layer.moveTo(top);
      return;
    }
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
    if (!this.held()) {
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
    return parseFloat(this.view.getComputedStyle(this.content).scrollPaddingTop) || 0;
  }

  listen(listener: () => void): () => void {
    const view = this.view;
    const onScroll = (): void => {
      if (!this.held()) listener();
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

  private held(): boolean {
    return (this.layer?.pinned ?? false) || this.blocked();
  }

  private blocked(): boolean {
    return this.root.classList.contains(PAGE_SCROLL_BLOCK_CLASS);
  }

  private get root(): HTMLElement {
    return this.view.document.documentElement;
  }

  private get scrollingElement(): Element {
    return pageScrollingElement(this.view.document);
  }
}
```

- [ ] **Step 6: Pass the layer through `ScrollHosts.forPage`**

In `scroll-hosts.service.ts`, add `import type { PageLayer } from './page-layer';` and replace `forPage`:

```ts
  forPage(content: HTMLElement, layer: PageLayer | null = null): ScrollHost {
    return new PageScrollHost(content, this.document.defaultView ?? window, this.outsideZone, layer);
  }
```

`ElementBackedScrollHosts.forPage(content)` in `src/testing/scroll-testing.ts` keeps its single parameter. TypeScript accepts an override with fewer parameters.

- [ ] **Step 7: Run the specs and the typechecks**

Run: `./node_modules/.bin/jest src/app/shared/scroll-host src/app/reader/list/entry-list --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS.
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit`
Expected: no output.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/shared/scroll-host
git commit -m "feat(#2038): a page layer pinned beneath another keeps its own offset"
```

---

### Task 2: `PageScrollOwner` and `[appPageLayer]`

**Files:**
- Create: `frontend/src/app/reader/shell/page-scroll-owner.ts`
- Create: `frontend/src/app/reader/shell/page-scroll-owner.spec.ts`
- Create: `frontend/src/app/reader/shell/page-layer.directive.ts`
- Create: `frontend/src/app/reader/shell/page-layer.directive.spec.ts`
- Modify: `frontend/src/styles/_base.scss:48-54`

**Interfaces:**
- Consumes: Task 1's `PageLayer`, `PAGE_PINNED_CLASS` and `ScrollHosts.forPage(content, layer?)`; `PAGE_SCROLL_BLOCK_CLASS`; `trackPageScrollTop` and `restorePageScroll`.
- Produces:
  - `export const PAGE_OVERLAID_CLASS = 'page-overlaid'`
  - `@Injectable() export class PageScrollOwner { enter(layer: PageLayer): void; leave(layer: PageLayer): void }`, provided per reader (Task 6 adds it to the shell's `providers`)
  - `@Directive({ selector: '[appPageLayer]', exportAs: 'appPageLayer' }) export class PageLayerDirective { readonly injector: Injector }`, which provides `PageLayer` for its element

- [ ] **Step 1: Write the failing owner spec**

```ts
// frontend/src/app/reader/shell/page-scroll-owner.spec.ts
import { TestBed } from '@angular/core/testing';
import { PAGE_OVERLAID_CLASS, PageScrollOwner } from './page-scroll-owner';
import { PageLayer } from '../../shared/scroll-host/page-layer';
import { PAGE_SCROLL_BLOCK_CLASS } from '../../shared/scroll-host/page-scroll-host';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';

describe('PageScrollOwner', () => {
  const root = document.documentElement;
  let page: { top: number };
  let owner: PageScrollOwner;

  beforeEach(() => {
    page = trackPageScrollTop();
    TestBed.configureTestingModule({ providers: [PageScrollOwner] });
    owner = TestBed.inject(PageScrollOwner);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    root.style.top = '';
    restorePageScroll();
  });

  /** A layer whose box starts `documentTop` down the document, measured against the live page. */
  function layerAt(documentTop: number): PageLayer {
    const element = document.createElement('div');
    jest
      .spyOn(element, 'getBoundingClientRect')
      .mockImplementation(() => ({ top: documentTop - page.top }) as DOMRect);
    return new PageLayer(element);
  }

  /** The list scrolled to 1200, with an article entered over it. */
  function listUnderArticle(): { list: PageLayer; article: PageLayer } {
    const list = layerAt(0);
    owner.enter(list);
    page.top = 1200;
    const article = layerAt(0);
    owner.enter(article);
    return { list, article };
  }

  const state = (layer: PageLayer): string => (layer.pinned ? 'pinned' : 'in flow');

  it('leaves the first layer in the flow and the page where it is', () => {
    page.top = 1200;
    const list = layerAt(0);

    owner.enter(list);

    expect(list.pinned).toBe(false);
    expect(page.top).toBe(1200);
    expect(root.classList).not.toContain(PAGE_OVERLAID_CLASS);
  });

  it('pins the layer beneath where it stands and starts the entering one at the top', () => {
    const { list, article } = listUnderArticle();

    expect(list.pinned).toBe(true);
    expect(list.pinnedTop).toBe(1200);
    expect(article.pinned).toBe(false);
    expect(page.top).toBe(0);
    expect(root.classList).toContain(PAGE_OVERLAID_CLASS);
  });

  it('hands the page back in one call: the leaver pinned and the list in flow before it moves', () => {
    const { list, article } = listUnderArticle();
    page.top = 640;
    const writes: string[] = [];
    Object.defineProperty(root, 'scrollTop', {
      configurable: true,
      get: () => page.top,
      set: (top: number) => {
        writes.push(`article ${state(article)}, list ${state(list)}, page ${top}`);
        page.top = top;
      },
    });

    owner.leave(article);

    expect(writes).toEqual(['article pinned, list in flow, page 1200']);
    expect(article.pinnedTop).toBe(640);
    expect(root.classList).not.toContain(PAGE_OVERLAID_CLASS);
  });

  it('hands a link reader’s page back to the article, then the article’s to the list', () => {
    const { list, article } = listUnderArticle();
    page.top = 640;
    const link = layerAt(0);
    owner.enter(link);
    expect(article.pinnedTop).toBe(640);
    page.top = 300;

    owner.leave(link);
    expect(page.top).toBe(640);
    expect([state(article), state(list)]).toEqual(['in flow', 'pinned']);

    owner.leave(article);
    expect(page.top).toBe(1200);
    expect(state(list)).toBe('in flow');
  });

  it('changes nothing on the page when a layer leaves from beneath the top', () => {
    const { list, article } = listUnderArticle();
    page.top = 640;

    owner.leave(list);
    expect(page.top).toBe(640);
    expect(article.pinned).toBe(false);

    owner.leave(article);
    expect(page.top).toBe(640);
    expect(article.pinned).toBe(true);
  });

  it('lands the hand-back after a CDK dialog that holds the page lets go', async () => {
    const { list, article } = listUnderArticle();
    root.style.top = '-640px';
    root.classList.add(PAGE_SCROLL_BLOCK_CLASS);
    page.top = 0;

    owner.leave(article);
    expect(article.pinnedTop).toBe(640);
    expect(list.pinned).toBe(false);
    expect(root.style.top).toBe('-1200px');

    root.style.top = '';
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    page.top = 640;
    await new Promise((resolve) => setTimeout(resolve));

    expect(page.top).toBe(1200);
  });

  it('drops its class from the page with the reader', () => {
    listUnderArticle();
    TestBed.resetTestingModule();
    expect(root.classList).not.toContain(PAGE_OVERLAID_CLASS);
  });
});
```

- [ ] **Step 2: Write the failing directive spec**

```ts
// frontend/src/app/reader/shell/page-layer.directive.spec.ts
import { Component, inject, signal } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { PageLayerDirective } from './page-layer.directive';
import { PageScrollOwner } from './page-scroll-owner';
import { PAGE_PINNED_CLASS, PageLayer } from '../../shared/scroll-host/page-layer';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';

@Component({ selector: 'app-layer-probe', template: '' })
class LayerProbeComponent {
  readonly layer = inject(PageLayer, { optional: true });
}

@Component({
  imports: [PageLayerDirective, NgTemplateOutlet, LayerProbeComponent],
  providers: [PageScrollOwner],
  template: `
    <section class="list" appPageLayer></section>
    @if (open()) {
      <div class="overlay" appPageLayer #layer="appPageLayer">
        <ng-container *ngTemplateOutlet="probe; injector: layer.injector" />
      </div>
    }
    <ng-template #probe><app-layer-probe /></ng-template>
  `,
})
class PageLayersHostComponent {
  readonly open = signal(false);
}

describe('PageLayerDirective', () => {
  let fixture: ComponentFixture<PageLayersHostComponent>;
  let page: { top: number };
  const host = (): HTMLElement => fixture.nativeElement as HTMLElement;

  beforeEach(() => {
    page = trackPageScrollTop();
    TestBed.configureTestingModule({ imports: [PageLayersHostComponent] });
    fixture = TestBed.createComponent(PageLayersHostComponent);
    fixture.detectChanges();
    page.top = 1200;
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    restorePageScroll();
  });

  function setOpen(open: boolean): void {
    fixture.componentInstance.open.set(open);
    fixture.detectChanges();
  }

  it('pins the list where it stood when an overlay enters, and starts the page at the top', () => {
    setOpen(true);

    expect(host().querySelector('.list')!.classList).toContain(PAGE_PINNED_CLASS);
    expect(host().querySelector('.overlay')!.classList).not.toContain(PAGE_PINNED_CLASS);
    expect(page.top).toBe(0);
  });

  it('hands the page back to the list as the overlay leaves', () => {
    setOpen(true);
    page.top = 500;

    setOpen(false);

    expect(host().querySelector('.list')!.classList).not.toContain(PAGE_PINNED_CLASS);
    expect(page.top).toBe(1200);
  });

  it('lends its layer to an outlet whose template is declared outside it', () => {
    setOpen(true);
    const probe = fixture.debugElement.query(By.directive(LayerProbeComponent))
      .componentInstance as LayerProbeComponent;
    expect(probe.layer?.element).toBe(host().querySelector('.overlay'));
  });
});
```

- [ ] **Step 3: Run them to verify they fail**

Run: `./node_modules/.bin/jest src/app/reader/shell/page-scroll-owner.spec.ts src/app/reader/shell/page-layer.directive.spec.ts --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL. `Cannot find module './page-scroll-owner'` and `'./page-layer.directive'`.

- [ ] **Step 4: Write the owner**

```ts
// frontend/src/app/reader/shell/page-scroll-owner.ts
import { DOCUMENT } from '@angular/common';
import { DestroyRef, Injectable, inject } from '@angular/core';
import { ScrollHosts } from '../../shared/scroll-host/scroll-hosts.service';
import type { PageLayer } from '../../shared/scroll-host/page-layer';

/** On `<html>` while a layer over the list owns the page; its rail stands in for the scrollbar. */
export const PAGE_OVERLAID_CLASS = 'page-overlaid';

/** One page, stacked layers (#2038): the top one is in the flow and owns the document's offset,
 *  every one beneath is pinned at its own. The one place a layer switch writes the page offset. */
@Injectable()
export class PageScrollOwner {
  private readonly document = inject(DOCUMENT);
  private readonly page = inject(ScrollHosts).forPage(this.document.body);
  private readonly layers: PageLayer[] = [];

  constructor() {
    // Listening arms the host's release observer: a write under a CDK block lands after it.
    const stopListening = this.page.listen(() => undefined);
    inject(DestroyRef).onDestroy(() => {
      stopListening();
      this.document.documentElement.classList.remove(PAGE_OVERLAID_CLASS);
    });
  }

  enter(layer: PageLayer): void {
    const beneath = this.layers.at(-1);
    this.layers.push(layer);
    this.markOverlaid();
    if (!beneath) return;
    beneath.pinAt(this.page.scrollTop);
    this.page.scrollTop = 0;
  }

  /** Pins the leaver so its leave animation plays in place, and gives the page back to the
   *  layer beneath at its offset, all before the next paint (spec Risk 2). */
  leave(layer: PageLayer): void {
    const index = this.layers.indexOf(layer);
    if (index === -1) return;
    const onTop = index === this.layers.length - 1;
    this.layers.splice(index, 1);
    this.markOverlaid();
    if (!onTop) return;
    layer.pinAt(this.page.scrollTop);
    const beneath = this.layers.at(-1);
    if (beneath) this.page.scrollTop = beneath.unpin();
  }

  private markOverlaid(): void {
    this.document.documentElement.classList.toggle(PAGE_OVERLAID_CLASS, this.layers.length > 1);
  }
}
```

- [ ] **Step 5: Write the directive**

```ts
// frontend/src/app/reader/shell/page-layer.directive.ts
import { DestroyRef, Directive, ElementRef, Injector, inject } from '@angular/core';
import { PageLayer } from '../../shared/scroll-host/page-layer';
import { PageScrollOwner } from './page-scroll-owner';

/** A page-sized layer of the reader: the list column, or a full-screen article over it (#2038). */
@Directive({
  selector: '[appPageLayer]',
  exportAs: 'appPageLayer',
  providers: [
    {
      provide: PageLayer,
      useFactory: () => new PageLayer(inject<ElementRef<HTMLElement>>(ElementRef).nativeElement),
    },
  ],
})
export class PageLayerDirective {
  /** For an outlet whose template is declared elsewhere, and so cannot see this element's layer. */
  readonly injector = inject(Injector);

  constructor() {
    const owner = inject(PageScrollOwner);
    const layer = inject(PageLayer);
    owner.enter(layer);
    inject(DestroyRef).onDestroy(() => owner.leave(layer));
  }
}
```

- [ ] **Step 6: Hide the page scrollbar while a layer over the list owns the page**

In `frontend/src/styles/_base.scss`, replace

```scss
html.reader-page.page-scrollbar-hidden {
  scrollbar-width: none;
}

html.reader-page.page-scrollbar-hidden::-webkit-scrollbar {
  display: none;
}
```

with

```scss
/* A full-screen article's rail always knows its length, so it always stands in. */
html.reader-page:is(.page-scrollbar-hidden, .page-overlaid) {
  scrollbar-width: none;
}

html.reader-page:is(.page-scrollbar-hidden, .page-overlaid)::-webkit-scrollbar {
  display: none;
}
```

- [ ] **Step 7: Run the specs, the typechecks and Stylelint**

Run: `./node_modules/.bin/jest src/app/reader/shell/page-scroll-owner.spec.ts src/app/reader/shell/page-layer.directive.spec.ts --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS, 10 tests.
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit && npx stylelint src/styles/_base.scss`
Expected: no output.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/shell/page-scroll-owner.ts frontend/src/app/reader/shell/page-scroll-owner.spec.ts frontend/src/app/reader/shell/page-layer.directive.ts frontend/src/app/reader/shell/page-layer.directive.spec.ts frontend/src/styles/_base.scss
git commit -m "feat(#2038): PageScrollOwner hands the page between stacked layers"
```

---

### Task 3: The article layer's chrome in a head and a foot

A layout-neutral restructure, so Task 4 can make the chrome sticky without touching the frame.

**Files:**
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.html`
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.scss`
- Test: `frontend/src/app/reader/article/article-layer/article-layer.component.spec.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `.frame` has exactly three children: `.chrome-head`, `.scroller` and `.chrome-foot`, in that order.
  - `.chrome-head` holds `.mini` (full screen only), `.bar` and `app-progress-rail`.
  - `.chrome-foot` holds `.corner-actions`, `.dock` and `.end-corner` (while not leaving).

- [ ] **Step 1: Write the failing test**

In `article-layer.component.spec.ts`, inside `describe('ArticleLayerComponent', …)`, add after the "projects the dock slot…" test:

```ts
  it('holds the chrome in a head and a foot around the scroller (#2038)', () => {
    goFullscreen();
    const frame = element.querySelector('.frame')!;

    const order = Array.from(frame.children).map((child) => child.className.split(' ')[0]);

    expect(order).toEqual(['chrome-head', 'scroller', 'chrome-foot']);
    expect(element.querySelector('.chrome-head .mini')).not.toBeNull();
    expect(element.querySelector('.chrome-head .bar .probe')).not.toBeNull();
    expect(element.querySelector('.chrome-head app-progress-rail')).not.toBeNull();
    expect(element.querySelector('.chrome-foot .corner-actions .corner-probe')).not.toBeNull();
    expect(element.querySelector('.chrome-foot .dock .dock-probe')).not.toBeNull();
    expect(element.querySelector('.chrome-foot .end-corner .corner-back')).not.toBeNull();
  });
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./node_modules/.bin/jest src/app/reader/article/article-layer --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL. `order` is `['mini', 'bar', 'scroller', 'app-progress-rail'…]`. The first entry of the received array is `"mini"`.

- [ ] **Step 3: Restructure the template**

Replace `article-layer.component.html` with:

```html
<div
  class="frame"
  [class.with-bar]="!fullscreen()"
  [style.transform]="gestures.swipeTransform()"
  [style.transition]="gestures.snapTransition()"
>
  <div class="chrome-head">
    @if (fullscreen()) {
      <!-- The toolbar below retracts on scroll, so full-screen the nameplate needs
           a strip of its own. aria-hidden: the h1 carries the same title, so
           announcing this too would read the headline twice. -->
      <div class="mini" aria-hidden="true">
        <ng-content select="[articleMini]" />
      </div>
    }
    <div #bar class="bar" [class.hidden]="toolbarHidden()">
      <button class="close" type="button" [attr.aria-label]="backLabel()" (click)="onBackButton()">
        <app-icon name="arrow_back" size="md" />
      </button>
      <ng-content select="[articleBar]" />
    </div>
    <!-- No progressbar role: announcing every scroll frame would only be noise. -->
    <app-progress-rail [progress]="progressRail()" />
  </div>
  <div #scroller class="scroller" [class.scrollbar-hidden]="progressRail().replacesScrollbar()">
    <div
      class="reader"
      [class.with-tail]="withTail()"
      [style.transform]="gestures.pullTransform()"
      [style.transition]="gestures.snapTransition()"
    >
      <ng-content />
      @if (fullscreen() && gestures.pulling()) {
        <div class="pull-back" [class.armed]="gestures.pullArmed()" aria-hidden="true">
          <app-spinner [size]="22" />
        </div>
      }
    </div>
  </div>
  <div class="chrome-foot">
    <div class="corner-actions">
      <ng-content select="[articleCorner]" />
    </div>
    <div class="dock">
      <ng-content select="[articleDock]" />
    </div>
    @if (!leaving()) {
      <div class="end-corner">
        @if (showToTop()) {
          <app-to-top-button (activate)="toTop.emit()" />
        }
        <button
          class="corner-back"
          type="button"
          [attr.aria-label]="backLabel()"
          (click)="onBackButton()"
        >
          <app-icon name="arrow_back" size="dense" />
        </button>
      </div>
    }
  </div>
</div>
```

- [ ] **Step 4: Give the wrappers the frame's box**

In `article-layer.component.scss`, directly after the `.with-bar .scroller { … }` rule, insert:

```scss
/* The pane's chrome, outside the scroller so a tap reaches it (#1332). Above the isolated
   scroller; the head spans the pane for the rail, the foot anchors the corners. */
.chrome-head,
.chrome-foot {
  position: absolute;
  inset-inline: 0;
  z-index: 1;
  pointer-events: none;
}

.chrome-head {
  top: 0;
  bottom: 0;
}

.chrome-foot {
  bottom: 0;
}

.mini,
.bar,
.corner-actions,
.dock,
.end-corner {
  pointer-events: auto;
}
```

No other rule changes. `.mini`, `.bar` and the rail resolve against the head, which is exactly the frame's box. The corner anchors resolve against the zero-height foot at the frame's bottom edge, which is where they sat before.

- [ ] **Step 5: Run the specs that read the layer**

Run: `./node_modules/.bin/jest src/app/reader/article --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS, the new test included.

- [ ] **Step 6: Prove it looks the same in the browser**

Run: `npx playwright test e2e/article-back-desktop.spec.ts e2e/article-mini-header.spec.ts e2e/header-scroll-mobile.spec.ts e2e/article-reading-progress.spec.ts`
Expected: all pass. These still scroll the overlay's `.scroller`, which is unchanged until Task 6, and they measure the toolbar, the nameplate, the rail and the to-top disc.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/article/article-layer
git commit -m "refactor(#2038): the article layer's chrome in a head and a foot wrapper"
```

---

### Task 4: The article layer and the list run on the page when given a layer

**Files:**
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.ts`
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.scss`
- Test: `frontend/src/app/reader/article/article-layer/article-layer.component.spec.ts`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.ts:206-212`
- Test: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `PageLayer` and `ScrollHosts.forPage(content, layer)` (Task 1); `.chrome-head` and `.chrome-foot` (Task 3).
- Produces:
  - `ArticleLayerComponent` carries the host class `on-page` exactly when `inject(PageLayer, { optional: true })` found one. Its `scroller` is then `forPage(.scroller, layer)`; otherwise it is `forElement(.scroller)`.
  - The stylesheet keys page mode to `:host(.on-page)` and the pinned state to `:host-context(.page-pinned)`.
  - `EntryListComponent` calls `forPage(rows, inject(PageLayer, { optional: true }))`.

- [ ] **Step 1: Write the failing layer tests**

In `article-layer.component.spec.ts`:

(a) Extend the imports: `inject` from `@angular/core`; `PageLayer` from `'../../../shared/scroll-host/page-layer'`; and `restorePageScroll`, `trackPageScrollTop` from `'../../../../testing/scroll-testing'` (next to `scrollerOf`).

(b) Below `LayerHostComponent`, add a host that provides a layer:

```ts
@Component({
  imports: [ArticleLayerComponent],
  providers: [{ provide: PageLayer, useFactory: () => new PageLayer(document.createElement('div')) }],
  template: `
    <app-article-layer
      [fullscreen]="true"
      [resetKey]="page()"
      [progressRail]="progressRail"
      backLabel="Back"
      (scrolled)="scrolledTo.push($event)"
    >
      <article>body</article>
    </app-article-layer>
  `,
})
class PageLayerHostComponent {
  readonly page = signal('entry:1');
  readonly layer = inject(PageLayer);
  readonly progressRail = new ScrollProgressRail({
    scroller: () => undefined,
    contentBottom: () => null,
    layout: signal(0),
  });
  readonly scrolledTo: number[] = [];
}
```

(c) At the end of the file, after `describe('ArticleLayerComponent', …)`, add:

```ts
describe('ArticleLayerComponent on the page (#2038)', () => {
  let fixture: ComponentFixture<PageLayerHostComponent>;
  let page: { top: number };

  beforeEach(() => {
    installMockResizeObserver();
    page = trackPageScrollTop();
    TestBed.configureTestingModule({
      imports: [PageLayerHostComponent, provideTranslocoTesting()],
    });
    fixture = TestBed.createComponent(PageLayerHostComponent);
    fixture.detectChanges();
  });

  afterEach(restorePageScroll);

  const layerElement = (): HTMLElement =>
    (fixture.nativeElement as HTMLElement).querySelector('app-article-layer') as HTMLElement;

  function scrollPage(top: number): void {
    page.top = top;
    window.dispatchEvent(new Event('scroll'));
    fixture.detectChanges();
  }

  it('marks itself on the page', () => {
    expect(layerElement().classList).toContain('on-page');
  });

  it('hears the page, not its own box, and retracts its toolbar on it', () => {
    scrollPage(400);

    expect(fixture.componentInstance.scrolledTo).toEqual([400]);
    expect(layerElement().querySelector('.bar')!.classList).toContain('hidden');
  });

  it('starts a new page at the top of the document', () => {
    scrollPage(400);

    fixture.componentInstance.page.set('entry:2');
    fixture.detectChanges();

    expect(page.top).toBe(0);
  });

  it('hears nothing while pinned beneath another layer, and moves its pin on a reset', () => {
    const { layer } = fixture.componentInstance;
    layer.pinAt(300);

    scrollPage(1200);
    fixture.componentInstance.page.set('entry:2');
    fixture.detectChanges();

    expect(fixture.componentInstance.scrolledTo).toEqual([]);
    expect(layer.pinnedTop).toBe(0);
    expect(page.top).toBe(1200);
  });
});
```

The existing `LayerHostComponent` provides no layer, so every existing test keeps the element scroller.

- [ ] **Step 2: Write the failing list test**

In `entry-list.component.spec.ts`:
- Add `import { PageLayer } from '../../../shared/scroll-host/page-layer';`.
- Add `elementScrollHost` to the existing `../../../../testing/scroll-testing` import.

Then add inside the top-level `describe` (next to the other scroll tests):

```ts
  it('hands its page layer to the page host (#2038)', () => {
    const layer = new PageLayer(document.createElement('section'));
    const forPage = jest.fn((content: HTMLElement) => elementScrollHost(content));

    mount({}, [
      { provide: PageLayer, useValue: layer },
      { provide: ScrollHosts, useValue: { forPage } },
    ]);

    expect(forPage).toHaveBeenCalledWith(expect.any(HTMLElement), layer);
  });
```

- [ ] **Step 3: Run them to verify they fail**

Run: `./node_modules/.bin/jest src/app/reader/article/article-layer src/app/reader/list/entry-list/entry-list.component.spec.ts --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL. The four "on the page" tests fail: no `on-page` class, and `scrolledTo` is `[]` after a window scroll. The list test fails with `forPage` called with `(HTMLElement)` only.

- [ ] **Step 4: Pick the host in the layer**

In `article-layer.component.ts`:
- Add `import { PageLayer } from '../../../shared/scroll-host/page-layer';`.
- In `@Component({…})`, add `host: { '[class.on-page]': 'onPage' },` after `styleUrl`.
- Replace the `scroller` computed, and the line before it, with:

```ts
  private readonly scrollHosts = inject(ScrollHosts);
  private readonly pageLayer = inject(PageLayer, { optional: true });
  /** Full screen the article owns the page (#2038); the split pane scrolls its own box. */
  protected readonly onPage = this.pageLayer !== null;
  readonly scroller = computed((): ScrollHost | undefined => {
    const element = this.scrollerRef()?.nativeElement;
    if (!element) return undefined;
    return this.pageLayer
      ? this.scrollHosts.forPage(element, this.pageLayer)
      : this.scrollHosts.forElement(element);
  });
```

In the class doc comment, replace `the one scroller,` with `its scroller (the pane's box, or the page),`.

- [ ] **Step 5: Lay the layer out on the page**

At the end of `article-layer.component.scss`, append:

```scss
/* Full screen on the page (#2038): the document scrolls, and the layer grows with its article. */
:host(.on-page),
:host(.on-page) .frame {
  height: auto;
}

/* No longer a scroller; an inline-size container only, so `cqb` reads the viewport. */
:host(.on-page) .scroller {
  height: auto;
  overflow: visible;
  overscroll-behavior: auto;
  container: reader-pane / inline-size;
}

/* Covers the pinned list even when short, and runs clear of the fixed audio bar. */
:host(.on-page) .reader {
  min-height: 100dvh;
  padding-bottom: var(--audio-bar-h, 0rem);
}

/* Sticky, not fixed: the swipe and the slide transform an ancestor, which traps a fixed box (#100). */
:host(.on-page) .chrome-head {
  position: sticky;
  bottom: auto;
  height: 0;
}

:host(.on-page) .chrome-foot {
  position: sticky;
  bottom: var(--audio-bar-h, 0);
}

:host(.on-page) app-progress-rail {
  bottom: auto;
  height: calc(100dvh - var(--audio-bar-h, 0rem));
}

/* Pinned beneath a layer or leaving: held where the viewport was. Must follow the rules above. */
:host-context(.page-pinned) .chrome-head {
  position: absolute;
  top: var(--page-pin-top, 0rem);
}

:host-context(.page-pinned) .chrome-foot {
  position: absolute;
  top: calc(var(--page-pin-top, 0rem) + 100dvh - var(--audio-bar-h, 0rem));
  bottom: auto;
}
```

The pinned rules tie with the `.on-page` rules on specificity. Source order is what makes them win, so they must stay last in the file.

- [ ] **Step 6: Hand the list's layer to its host**

In `entry-list.component.ts`:
- Add `import { PageLayer } from '../../../shared/scroll-host/page-layer';`.
- Replace

```ts
  private readonly scrollHosts = inject(ScrollHosts);
  private readonly scrollHost = computed((): ScrollHost | undefined => {
    const rows = this.rows()?.nativeElement;
    return rows ? this.scrollHosts.forPage(rows) : undefined;
  });
```

with

```ts
  private readonly scrollHosts = inject(ScrollHosts);
  private readonly pageLayer = inject(PageLayer, { optional: true });
  private readonly scrollHost = computed((): ScrollHost | undefined => {
    const rows = this.rows()?.nativeElement;
    return rows ? this.scrollHosts.forPage(rows, this.pageLayer) : undefined;
  });
```

- [ ] **Step 7: Run the specs, the typechecks and Stylelint**

Run: `./node_modules/.bin/jest src/app/reader/article src/app/reader/list --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS.
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit && npx stylelint "src/app/reader/article/article-layer/*.scss"`
Expected: no output.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/article/article-layer frontend/src/app/reader/list/entry-list/entry-list.component.ts frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts
git commit -m "feat(#2038): the article layer and the list run on the page when given a layer"
```

---

### Task 5: A remounted list lands on its remembered offset

The shell remounts the list on every split ↔ full-screen switch. Until now the page offset carried it across. Once an article owns the page, it no longer can (decision 4).

**Files:**
- Modify: `frontend/src/app/reader/list/entry-list/list-scroll-state.ts:94-103`
- Test: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`

**Interfaces:**
- Consumes: `ListScrollState.applyScroll(scroller, top)`, `ListScrollMemory.read(selection)`.
- Produces: a list built with its rows already loaded calls `applyScroll(host, memory.read(selection))` once its host first exists. It does so again whenever the host is rebuilt (the rows element is recreated), as well as on a selection change.

- [ ] **Step 1: Write the failing test**

In `entry-list.component.spec.ts`, add `import { ListScrollState } from './list-scroll-state';`. Inside `describe('scroll position across a view switch', …)` add:

```ts
    it('lands a list built anew, rows already loaded, on its remembered offset (#2038)', () => {
      const apply = jest.spyOn(ListScrollState.prototype, 'applyScroll');

      mount({}, [{ provide: ListScrollMemory, useValue: { save: jest.fn(), read: () => 420 } }]);

      expect(apply).toHaveBeenCalledWith(expect.anything(), 420);
      apply.mockRestore();
    });
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./node_modules/.bin/jest src/app/reader/list/entry-list/entry-list.component.spec.ts -t "built anew" --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL. `applyScroll` is never called with 420: the effect's first run finds no `#rows` yet, and it does not track the host.

If it **passes**, Angular already resolves `#rows` by the effect's first run. In that case:
- Skip Step 3.
- Keep the test as the pin.
- Commit it alone as `test(#2038): a remounted list lands on its remembered offset`.

- [ ] **Step 3: Track the host**

In `list-scroll-state.ts`, replace

```ts
  // A view switch leaves the previous view's list on screen until the new page
  // lands. Hand the scroller the incoming view's place right away, so the wait
  // shows that view's window, not the one left. The restore above repeats it.
  private readonly _scrollOnSelectionChange = effect(() => {
    const selection = this.options.selection();
    untracked(() => {
      const scroller = this.options.scroller();
      if (scroller) this.applyScroll(scroller, this.scroll.read(selection));
    });
  });
```

with

```ts
  // A view switch leaves the previous view's list on screen until the new page
  // lands. Hand the scroller the incoming view's place right away, so the wait
  // shows that view's window, not the one left. The restore above repeats it.
  // A remount (split ↔ full screen) lands the same way once its rows exist (#2038).
  private readonly _scrollOnSelectionChange = effect(() => {
    const selection = this.options.selection();
    const scroller = this.options.scroller();
    if (scroller) untracked(() => this.applyScroll(scroller, this.scroll.read(selection)));
  });
```

- [ ] **Step 4: Run the whole list spec**

Run: `./node_modules/.bin/jest src/app/reader/list --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS. Two things now happen on a re-render, and every existing assertion still holds:
- The new apply reads the same remembered offset that the selection-change and loading-edge applies already write.
- The skeleton → rows transition re-applies that same offset.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list/entry-list/list-scroll-state.ts frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts
git commit -m "fix(#2038): a remounted list lands on its remembered offset"
```

---

### Task 6: A full-screen article takes the page from the list (the switch)

Why this is one commit is set out under Commit order above.

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html:113-131, 136`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.scss`
- Test: `frontend/src/app/reader/shell/reader-shell.component.spec.ts`
- Modify: `frontend/e2e/page-scroll.spec.ts` (the article test)

**Interfaces:**
- Consumes: `PageScrollOwner` and `PageLayerDirective` (Task 2); `on-page` and the page-mode styles (Task 4); the remount landing (Task 5).
- Produces:
  - `section.list` and each `.article-overlay` carry `appPageLayer`.
  - The overlay outlets pass `injector: <layer>.injector`.
  - `.page-pinned` is styled.
  - The CDK lock is the drawer's alone.
  - Outside split view the column box is measured off `main`.
  - The audio bar sits at z 13.

- [ ] **Step 1: Write the failing shell tests**

In `reader-shell.component.spec.ts`:

(a) Give the page driver a reader. Replace the helper at the bottom of the hide-on-scroll section:

```ts
  function listScroller() {
    const page = trackPageScrollTop();
    return {
      scrollTo(top: number): void {
        page.top = top;
        window.dispatchEvent(new Event('scroll'));
      },
    };
  }
```

with

```ts
  function listScroller() {
    const page = trackPageScrollTop();
    return {
      get top(): number {
        return page.top;
      },
      scrollTo(top: number): void {
        page.top = top;
        window.dispatchEvent(new Event('scroll'));
      },
    };
  }
```

(b) Inside `describe('returning from a full-screen article (#128)', …)`, add after the existing tests:

```ts
    afterEach(() => {
      const root = document.documentElement;
      delete (root as unknown as { scrollHeight?: number }).scrollHeight;
      root.classList.remove('cdk-global-scrollblock');
      root.style.top = '';
      jest.restoreAllMocks();
    });

    it('pins the list where it stood and starts the article at the top of the page (#2038)', () => {
      const { fixture, rows } = bootNarrowScrolledDown();
      const list = (fixture.nativeElement as HTMLElement).querySelector('section.list')!;

      openArticle(fixture);
      expect(list.classList).toContain('page-pinned');
      expect(rows.top).toBe(0);

      closeArticle(fixture);
      expect(list.classList).not.toContain('page-pinned');
      expect(rows.top).toBe(800);
    });

    it('leaves the list bar alone while the article scrolls the page (#2038)', () => {
      const { fixture, rows } = bootNarrowScrolledDown();
      openArticle(fixture);

      rows.scrollTo(100);
      rows.scrollTo(30); // a scroll UP would expand a list that heard it
      fixture.detectChanges();

      expect(fixture.componentInstance.headerHidden()).toBe(true);
    });

    it('takes no CDK block for the article, while the drawer still does (#2038)', () => {
      const root = document.documentElement;
      Object.defineProperty(root, 'scrollHeight', { value: 5000, configurable: true });
      jest.spyOn(window, 'scroll').mockImplementation(() => undefined);
      const { fixture } = bootNarrowScrolledDown();
      screen.isNarrow.set(true);

      openArticle(fixture);
      expect(root.classList).not.toContain('cdk-global-scrollblock');
      closeArticle(fixture);

      fixture.componentInstance.setSidebarOpen(true);
      fixture.detectChanges();
      expect(root.classList).toContain('cdk-global-scrollblock');
    });
```

(c) Add a new describe before `describe('link reader (#1664)', …)`:

```ts
  describe('the page across the split ↔ full-screen switch (#2038)', () => {
    const originalResizeObserver = globalThis.ResizeObserver;

    afterEach(() => {
      globalThis.ResizeObserver = originalResizeObserver;
    });

    function openEntry(fixture: ReturnType<typeof boot>): void {
      qp.next(convertToParamMap({ entry: '1' }));
      fixture.detectChanges();
      ctrl.match(() => true);
      fixture.detectChanges();
    }

    it('remounts the article on the page over a pinned list, and back into the pane', () => {
      const fixture = boot();
      screen.isWide.set(true);
      fixture.componentInstance.layout.set('pane');
      fixture.detectChanges();
      openEntry(fixture);
      const host = fixture.nativeElement as HTMLElement;
      expect(host.querySelector('section.reader app-article-layer')!.classList).not.toContain(
        'on-page',
      );

      screen.isWide.set(false);
      fixture.detectChanges();
      expect(host.querySelector('section.list')!.classList).toContain('page-pinned');
      expect(host.querySelector('.article-overlay')!.classList).not.toContain('page-pinned');
      expect(host.querySelector('.article-overlay app-article-layer')!.classList).toContain(
        'on-page',
      );

      screen.isWide.set(true);
      fixture.detectChanges();
      expect(host.querySelector('section.list')!.classList).not.toContain('page-pinned');
      expect(host.querySelector('section.reader app-article-layer')!.classList).not.toContain(
        'on-page',
      );
    });

    it('measures the list column off main, which keeps its box while the list is pinned', () => {
      installMockResizeObserver();
      const fixture = boot();
      const host = fixture.nativeElement as HTMLElement;
      const main = host.querySelector<HTMLElement>('.main')!;
      jest
        .spyOn(main, 'getBoundingClientRect')
        .mockReturnValue({ left: 280, width: 1160 } as DOMRect);

      MockResizeObserver.instances
        .filter((observer) => observer.targets.has(main))
        .forEach((observer) => observer.fire());

      expect(host.style.getPropertyValue('--list-column-left')).toBe('280px');
      expect(host.style.getPropertyValue('--list-column-width')).toBe('1160px');
    });
  });
```

Add `import { MockResizeObserver, installMockResizeObserver } from '../../../testing/resize-observer-testing';` to the imports.

- [ ] **Step 2: Run them to verify they fail**

Run: `./node_modules/.bin/jest src/app/reader/shell/reader-shell.component.spec.ts -t "2038" --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: FAIL. There is no `page-pinned` class and `rows.top` stays 800. In jsdom no CDK block holds the page, so the list hears the article's scroll and `headerHidden()` is `false`. The article layer has no `on-page`. The column is published from `section.list` (`0px`).

- [ ] **Step 3: Provide the owner, the layers and the new lock**

In `reader-shell.component.ts`:

- Add the imports:

```ts
import { PageScrollOwner } from './page-scroll-owner';
import { PageLayerDirective } from './page-layer.directive';
```

- Add `PageLayerDirective,` to `imports` after `PaneResizeDirective,`. Add `PageScrollOwner,` to `providers` after `PendingEntriesPoll,`.
- Replace

```ts
  /** The drawer and a full-screen article cover the list; nothing may scroll it beneath them. */
  private readonly _holdPage = holdReaderPage(
    this.hostRef.nativeElement,
    computed(() => (this.sidebarOpen() && this.screen.isNarrow()) || this.articleFullscreen()),
  );
```

with

```ts
  /** The open drawer covers the list; nothing may scroll it beneath. A full-screen article
   *  pins the list itself (PageScrollOwner, #2038). */
  private readonly _holdPage = holdReaderPage(
    this.hostRef.nativeElement,
    computed(() => this.sidebarOpen() && this.screen.isNarrow()),
  );
```

- In `_chromeGeometry`, replace `listColumn: () => this.listColumn()?.nativeElement,` with:

```ts
    // Off split view the list column is main's box, which stays in flow while the list is pinned.
    listColumn: () => (this.splitView() ? this.listColumn() : this.mainColumn())?.nativeElement,
```

- [ ] **Step 4: Register the layers in the template**

In `reader-shell.component.html`, replace

```html
      <!-- The list stays mounted; a full-screen article overlays it, so swiping it
           away reveals the list underneath. The app bar mirrors this list's own
           `collapsed` state, so the list scroller is the bar's only scroll source (#128). -->
      <ng-container *ngTemplateOutlet="entryListPane" />
      @if (openEntry()) {
        <div class="article-overlay" animate.leave="article-leave">
          <!-- Immersive (own toolbar, above the app bar) only below the wide breakpoint.
               On wide screens the bar is app chrome, so the full-pane article slots
               beneath it with the same toolbar arrangement the split pane uses. -->
          <ng-container *ngTemplateOutlet="readerPane; context: { fullscreen: !screen.isWide() }" />
        </div>
      }
      @if (linkTarget()) {
        <div class="article-overlay" animate.leave="article-leave">
          <ng-container *ngTemplateOutlet="linkPane; context: { fullscreen: !screen.isWide() }" />
        </div>
      }
```

with

```html
      <!-- The list stays mounted; a full-screen article overlays it, so swiping it
           away reveals the list underneath. Each is a page layer: the top one owns the
           document's scroll, the ones beneath are pinned at their offsets (#2038). The app
           bar mirrors this list's own `collapsed` state, its only scroll source (#128). -->
      <ng-container *ngTemplateOutlet="entryListPane" />
      @if (openEntry()) {
        <div
          class="article-overlay"
          appPageLayer
          #entryLayer="appPageLayer"
          animate.leave="article-leave"
        >
          <!-- Immersive (own toolbar, above the app bar) only below the wide breakpoint.
               On wide screens the bar is app chrome, so the full-pane article slots
               beneath it with the same toolbar arrangement the split pane uses. -->
          <ng-container
            *ngTemplateOutlet="
              readerPane;
              context: { fullscreen: !screen.isWide() };
              injector: entryLayer.injector
            "
          />
        </div>
      }
      @if (linkTarget()) {
        <div
          class="article-overlay"
          appPageLayer
          #linkLayer="appPageLayer"
          animate.leave="article-leave"
        >
          <ng-container
            *ngTemplateOutlet="
              linkPane;
              context: { fullscreen: !screen.isWide() };
              injector: linkLayer.injector
            "
          />
        </div>
      }
```

and replace `<section class="list" #listColumn>` with `<section class="list" #listColumn appPageLayer>`.

- [ ] **Step 5: Put the overlay in flow and style the pin**

In `reader-shell.component.scss`:

(a) Replace

```scss
/* Full-screen article over the still-mounted list (#128), sliding in from the
   right. Lives on a wrapper, not `app-reader-view` itself: a transform on that
   component would hijack its `position: fixed` descendants (#100). */
.article-overlay {
  @include page-chrome.list-column-box;

  /* Wide: beneath the app bar (10) — brand and account stay visible, and the
     article panel reserves the bar's height like the split pane does. */
  z-index: 5;
  animation: article-enter 0.22s ease-out;
}
```

with

```scss
/* Full-screen article over the still-mounted list (#128), sliding in from the right:
   a page layer, in the flow while it owns the page (#2038). Transparent, so a swipe-back
   shows the pinned list; the opaque background is the sliding frame's. */
.article-overlay {
  position: relative;

  /* Wide: beneath the app bar (10) — brand and account stay visible, and the
     article panel reserves the bar's height like the split pane does. */
  z-index: 5;
  animation: article-enter 0.22s ease-out;
}
```

(b) Directly after the `@media (width < bp.$bp-lg) { .article-overlay { z-index: 12; } }` block, insert:

```scss
/* A page layer beneath the one that owns the page (PageScrollOwner): held in the list
   column's box where it showed. After `.article-overlay`, whose position it overrides. */
.page-pinned {
  position: fixed;
  top: calc(-1 * var(--page-pin-top, 0rem));
  left: var(--list-column-left, 0);
  width: var(--list-column-width, 100%);
}
```

(c) Replace

```scss
/* The shell publishes this bar's height as `--audio-bar-h`; everything pinned to the foot leaves it free. */
app-audio-player-bar {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 9;
}
```

with

```scss
/* The shell publishes this bar's height as `--audio-bar-h`; everything pinned to the foot
   leaves it free. Above a narrow full-screen article (12), whose page runs on beneath it. */
app-audio-player-bar {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 13;
}
```

`page-chrome` is still used by `pinned-column`, so the `@use` stays.

- [ ] **Step 6: Update the page-scroll e2e's article test**

In `frontend/e2e/page-scroll.spec.ts`, replace

```ts
    await page.getByText(title, { exact: true }).first().click();
    await expect(page.locator('.article-overlay app-reader-view')).toBeVisible();
    await expect(page.locator('html')).toHaveClass(/cdk-global-scrollblock/);
    await expect(page.locator('app-reader-view .scroller')).toHaveJSProperty('scrollTop', 0);

    await page.locator('app-reader-view .bar .close').click();
    await expect(page.locator('.article-overlay')).toHaveCount(0);
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect(await pageScrollY(page)).toBe(LIST_OFFSET);
```

with

```ts
    await page.getByText(title, { exact: true }).first().click();
    await expect(page.locator('.article-overlay app-reader-view')).toBeVisible();
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect(await pageScrollY(page)).toBe(0);

    await page.locator('app-reader-view .bar .close').click();
    await expect(page.locator('.article-overlay')).toHaveCount(0);
    await expect(page.locator('section.list')).not.toHaveClass(/page-pinned/);
    expect(await pageScrollY(page)).toBe(LIST_OFFSET);
```

- [ ] **Step 7: Run the shell spec, every reader spec and the typechecks**

Run: `./node_modules/.bin/jest src/app/reader src/app/shared --testPathIgnorePatterns "/node_modules/" "/e2e/"`
Expected: PASS. That includes the existing "returning from a full-screen article (#128)" tests: the list is pinned and silent under the article and lands on 800 on close.
Run: `npx tsc -p tsconfig.app.json --noEmit && npx tsc -p tsconfig.spec.json --noEmit && npm run typecheck:e2e && npx stylelint "src/app/reader/shell/*.scss"`
Expected: no output.
Run: `npx prettier --write src/app/reader/shell/reader-shell.component.html src/app/reader/shell/reader-shell.component.ts src/app/reader/shell/reader-shell.component.scss src/app/reader/shell/reader-shell.component.spec.ts e2e/page-scroll.spec.ts`

- [ ] **Step 8: Smoke the switch in the browser**

Run: `npx playwright test e2e/page-scroll.spec.ts e2e/article-slide-out.spec.ts e2e/link-card-reader.spec.ts`
Expected: all pass. `article-slide-out` still sees `article-leave` on the leaving overlay: the leave animation stays on `.article-overlay`, which is now pinned while it plays.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/reader/shell frontend/e2e/page-scroll.spec.ts
git commit -m "feat(#2038): a full-screen article takes the page from the list"
```

---

### Task 7: The article specs scroll the page, not `.scroller`

Every e2e that scrolled the overlay article's `.scroller` must scroll the document. Split-pane cases are left alone, because the pane still scrolls itself: `article-back-desktop`'s second test, `article-reading-progress`'s split describe and `page-scroll`'s wide describe.

**Files:**
- Modify: `frontend/e2e/support/scroll.ts`
- Modify: `frontend/e2e/article-back-desktop.spec.ts`
- Modify: `frontend/e2e/article-reading-progress.spec.ts`
- Modify: `frontend/e2e/article-mini-header.spec.ts`
- Modify: `frontend/e2e/article-tail-space.spec.ts`
- Modify: `frontend/e2e/header-scroll-mobile.spec.ts`
- Modify: `frontend/e2e/reading-focus-blocks.spec.ts`
- Modify: `frontend/e2e/reading-focus-long-paragraph.spec.ts`

**Interfaces:**
- Consumes: `pageScrollY(page)` and `scrollPageTo(page, top)` (phase 2, `e2e/support/scroll.ts`).
- Produces: `export async function scrollPageToEnd(page: Page): Promise<number>` in `e2e/support/scroll.ts`.

- [ ] **Step 1: Add `scrollPageToEnd`**

Append to `frontend/e2e/support/scroll.ts`:

```ts
/** Scrolls the page to its last offset and returns it. */
export async function scrollPageToEnd(page: Page): Promise<number> {
  const end = await page.evaluate(
    () => document.documentElement.scrollHeight - window.innerHeight,
  );
  await scrollPageTo(page, end);
  return end;
}
```

- [ ] **Step 2: `article-back-desktop.spec.ts`, the full-pane test**

Replace

```ts
    // The toolbar sticks below the bar while the article scrolls, same
    // contract as the split pane.
    await page.locator('app-reader-view .scroller').evaluate((el) => el.scrollTo({ top: 800 }));
```

with

```ts
    // The toolbar sticks below the bar while the article scrolls the page (#2038).
    await page.evaluate(() => window.scrollTo({ top: 800, behavior: 'instant' }));
```

Leave the split-pane test's `.scroller` line (the second occurrence) as it is.

- [ ] **Step 3: `article-reading-progress.spec.ts`, the phone describe**

Change the import to `import { scrollPageTo, scrollPageToEnd } from './support/scroll';`.

In the first test, replace

```ts
    const pane = await openArticle(page);
    const scroller = pane.locator('.scroller');
```

with `    const pane = await openArticle(page);`, and replace

```ts
    await scroller.evaluate((el) => {
      const content = el.querySelector('.content')!;
      const bottom = content.getBoundingClientRect().bottom - el.getBoundingClientRect().top;
      el.scrollTo({ top: el.scrollTop + bottom - el.clientHeight, behavior: 'instant' });
    });
```

with

```ts
    await pane.evaluate((el) => {
      const bottom = el.querySelector('.content')!.getBoundingClientRect().bottom;
      window.scrollTo({ top: window.scrollY + bottom - window.innerHeight, behavior: 'instant' });
    });
```

Replace the whole second test with:

```ts
  // The cue has to survive the reading tail, which is half a viewport of blank
  // space below the last paragraph — exactly where the reader finishes the
  // article. The rail holds the viewport; ridden in the scrolled content, the
  // text would carry it away.
  test('the rail spans the viewport, including over the reading tail', async ({ page }) => {
    const signedIn = await signInWithLayout(page, 'list');
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, LONG_BODY);
    await page.reload();
    const pane = await openArticle(page);

    await scrollPageToEnd(page);
    await page.waitForTimeout(300);

    const gaps = await pane.evaluate((el) => {
      const rail = el.querySelector('app-progress-rail')!.getBoundingClientRect();
      return {
        fromTop: rail.top,
        fromBottom: window.innerHeight - rail.bottom,
        fromRightEdge: el.getBoundingClientRect().right - rail.right,
      };
    });
    expect(Math.abs(gaps.fromTop)).toBeLessThanOrEqual(1);
    expect(Math.abs(gaps.fromBottom)).toBeLessThanOrEqual(1);
    expect(Math.abs(gaps.fromRightEdge)).toBeLessThanOrEqual(1);
  });
```

- [ ] **Step 4: `article-mini-header.spec.ts`**

Add `import { scrollPageTo } from './support/scroll';`. Then replace both occurrences (the Edit tool's `replace_all`) of

```ts
    await pane
      .locator('.scroller')
      .evaluate((el) => el.scrollTo({ top: 600, behavior: 'instant' }));
```

with `    await scrollPageTo(page, 600);`.

The "a long title is cut to one line" check (`.scroller` `scrollWidth - clientWidth`) stays. A visible-overflow box still reports a sideways overflow there.

- [ ] **Step 5: `article-tail-space.spec.ts`**

Add `import { scrollPageToEnd } from './support/scroll';`.

In the first test, replace

```ts
    await scroller.evaluate((el) => el.scrollTo({ top: el.scrollHeight, behavior: 'instant' }));
```

with `    await scrollPageToEnd(page);`, and replace

```ts
    await scroller.evaluate((el) => {
      const p = el.querySelectorAll('.content p');
      const rect = p[p.length - 1].getBoundingClientRect();
      const paneRect = el.getBoundingClientRect();
      const centre = rect.top + rect.height / 2 - paneRect.top;
      el.scrollTop += centre - el.clientHeight / 2;
    });
```

with

```ts
    await scroller.evaluate((el) => {
      const p = el.querySelectorAll('.content p');
      const rect = p[p.length - 1].getBoundingClientRect();
      const centre = rect.top + rect.height / 2;
      window.scrollBy({ top: centre - window.innerHeight / 2, behavior: 'instant' });
    });
```

Replace the second test with:

```ts
  test('an article that fits the screen gains no dead scroll', async ({ page }) => {
    await openStubbedArticle(page, SHORT_BODY);

    const overflow = await page.evaluate(
      () => document.documentElement.scrollHeight - window.innerHeight,
    );
    expect(overflow).toBeLessThanOrEqual(2);
  });
```

In `tailMetrics`, replace

```ts
      cinemaRoom: scroller.clientHeight - readerTop,
      focusRoom: scroller.clientHeight / 2,
```

with

```ts
      cinemaRoom: window.innerHeight - readerTop,
      focusRoom: window.innerHeight / 2,
```

On the page, `cqb` has no block container and reads the small viewport height, which equals `innerHeight` in Playwright.

- [ ] **Step 6: `header-scroll-mobile.spec.ts`, the article to-top test**

Delete `    const scroller = page.locator('app-reader-view .scroller');`. Replace `    await scroller.evaluate((el) => el.scrollTo({ top: 900 }));` with `    await page.evaluate(() => window.scrollTo({ top: 900, behavior: 'instant' }));`. Replace `    await scroller.evaluate((el) => el.scrollTo({ top: 1400 }));` with `    await page.evaluate(() => window.scrollTo({ top: 1400, behavior: 'instant' }));`.

In the comment above the body stub, replace `drives the article's own scroller past the` with `drives the article past the`.

- [ ] **Step 7: The two reading-focus specs**

In both `reading-focus-blocks.spec.ts` and `reading-focus-long-paragraph.spec.ts`, replace

```ts
    await pane.evaluate((el) => el.scrollTo({ top: el.scrollHeight / 2, behavior: 'instant' }));
```

with

```ts
    await page.evaluate(() =>
      window.scrollTo({ top: document.documentElement.scrollHeight / 2, behavior: 'instant' }),
    );
```

`pane` still locates `app-reader-view .scroller` for the later queries.

- [ ] **Step 8: Typecheck, format and run**

Run: `npm run typecheck:e2e && npx prettier --write e2e/support/scroll.ts e2e/article-back-desktop.spec.ts e2e/article-reading-progress.spec.ts e2e/article-mini-header.spec.ts e2e/article-tail-space.spec.ts e2e/header-scroll-mobile.spec.ts e2e/reading-focus-blocks.spec.ts e2e/reading-focus-long-paragraph.spec.ts`
Expected: no type errors.
Run: `npx playwright test e2e/article-back-desktop.spec.ts e2e/article-reading-progress.spec.ts e2e/article-mini-header.spec.ts e2e/article-tail-space.spec.ts e2e/header-scroll-mobile.spec.ts e2e/reading-focus-blocks.spec.ts e2e/reading-focus-long-paragraph.spec.ts e2e/reading-focus-orientation.spec.ts e2e/list-scroll-reset.spec.ts`
Expected: all pass.

If "no dead scroll" fails, the page holds an in-flow element beside the article. Report which element, by running `[...document.body.querySelectorAll('*')].filter(e => e.getBoundingClientRect().bottom > innerHeight)` in the page. Do not loosen the bound.

- [ ] **Step 9: Commit**

```bash
git add frontend/e2e
git commit -m "test(#2038): full-screen article specs scroll the page, not .scroller"
```

---

### Task 8: Playwright — the handoff, phone and wide, across the split, under a sheet

**Files:**
- Modify: `frontend/e2e/support/scroll.ts`
- Modify: `frontend/e2e/page-scroll.spec.ts` (import `rowTitleInView` from support)
- Create: `frontend/e2e/article-page-scroll.spec.ts`

**Interfaces:**
- Consumes: `pageScrollY`, `scrollPageTo` and `scrollPageToEnd` (support); `stubOneFeedReader`, `entryWire`, `entryDetailJson` and `readerFailedJson` (`e2e/support/reader.ts`); `presetLocalStorage` (`e2e/support/auth.ts`); `aLinkCard` (`src/testing/entry-testing.ts`).
- Produces, in `e2e/support/scroll.ts`:
  - `rowTitleInView(page: Page): Promise<string>`
  - `touchDrag(page: Page, selector: string, from: TouchPoint, to: TouchPoint): Promise<void>`
  - `liftTouch(page: Page, selector: string): Promise<void>`
  - `interface TouchPoint { readonly x: number; readonly y: number }`

- [ ] **Step 1: Share the helpers**

Move `rowTitleInView` from `frontend/e2e/page-scroll.spec.ts` to `frontend/e2e/support/scroll.ts`, unchanged except for `export`. Then append:

```ts
export interface TouchPoint {
  readonly x: number;
  readonly y: number;
}

/** A one-finger drag on the first match of `selector`, from `from` through `to`, not yet lifted. */
export async function touchDrag(
  page: Page,
  selector: string,
  from: TouchPoint,
  to: TouchPoint,
): Promise<void> {
  await page
    .locator(selector)
    .first()
    .evaluate(
      (target, points) => {
        const fire = (type: string, point: { x: number; y: number }): void => {
          const touch = new Touch({ identifier: 1, target, clientX: point.x, clientY: point.y });
          target.dispatchEvent(
            new TouchEvent(type, { bubbles: true, cancelable: true, touches: [touch] }),
          );
        };
        const midway = {
          x: (points.from.x + points.to.x) / 2,
          y: (points.from.y + points.to.y) / 2,
        };
        fire('touchstart', points.from);
        fire('touchmove', midway);
        fire('touchmove', points.to);
      },
      { from, to },
    );
}

export async function liftTouch(page: Page, selector: string): Promise<void> {
  await page
    .locator(selector)
    .first()
    .evaluate((target) =>
      target.dispatchEvent(new TouchEvent('touchend', { bubbles: true, touches: [] })),
    );
}
```

In `page-scroll.spec.ts`, delete the local `rowTitleInView` and import it with the others: `import { pageScrollY, rowTitleInView, scrollPageTo } from './support/scroll';`.

- [ ] **Step 2: Write the spec**

```ts
// frontend/e2e/article-page-scroll.spec.ts
import { test, expect, Page } from '@playwright/test';
import type { ReadingLayout } from '../src/app/reader/reading-layout.service';
import { aLinkCard } from '../src/testing/entry-testing';
import { presetLocalStorage } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';
import {
  liftTouch,
  pageScrollY,
  rowTitleInView,
  scrollPageTo,
  scrollPageToEnd,
  touchDrag,
} from './support/scroll';

// A full-screen article takes the page from the list and hands it back (#2038): the browser
// bar can collapse while reading, and back lands the list on its exact offset. Every read the
// reader makes is stubbed, so the spec owns what it scrolls.
const PHONE = { width: 375, height: 812 };
const WIDE = { width: 1440, height: 900 };
/** Under the 900px wide breakpoint, over the 720px drawer one: full screen beside the sidebar. */
const MEDIUM = { width: 820, height: 900 };
const LIST_OFFSET = 1200;
const ARTICLE_OFFSET = 600;
const LAYER = 'app-reader-view app-article-layer';
const LAST_PARAGRAPH = 'Paragraph 39 of filler text';

const TALL_BODY = Array.from(
  { length: 40 },
  (_, i) => `<p>Paragraph ${i} of filler text, long enough that the article runs past a screen.</p>`,
).join('');

const ENTRIES = Array.from({ length: 40 }, (_, i) =>
  entryWire({
    id: i + 1,
    title: `Entry number ${i + 1}`,
    subscriptionId: 1,
    summary: 'A summary long enough to give the row some height. '.repeat(3),
    excerpt: 'A summary long enough to give the row some height. '.repeat(3),
    share: 'link',
    linkCards: [aLinkCard({ title: 'The linked story' })],
  }),
);

const LINKED_PAGE = {
  status: 'ok',
  url: 'https://news.test/story',
  title: 'The linked story',
  byline: null,
  siteName: 'news.test',
  contentHtml: TALL_BODY,
  excerpt: null,
  paywalled: false,
  originalHero: null,
  extractedAt: '2026-08-01T12:50:34+00:00',
};

async function openReader(page: Page, layout: ReadingLayout): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await presetLocalStorage(page, { 'sfr.layout': layout });
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ json: { entries: ENTRIES, nextCursor: null } });
    },
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const id = Number(new URL(route.request().url()).pathname.split('/').pop());
      const match = ENTRIES.find((each) => each.id === id) ?? ENTRIES[0];
      await route.fulfill({ json: entryDetailJson(match, TALL_BODY) });
    },
  );
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ json: readerFailedJson('unextractable') }),
  );
  await page.route('**/api/entries/*/state', (route) => route.fulfill({ json: {} }));
  await page.route(/\/api\/reader\?/, (route) => route.fulfill({ json: LINKED_PAGE }));
  await page.goto('/');
  await expect(page.locator('app-entry-row').first()).toBeVisible();
}

/** Scrolls the list, opens the row in the middle of the screen, and returns that row. */
async function openArticleFromList(page: Page) {
  await scrollPageTo(page, LIST_OFFSET);
  const title = await rowTitleInView(page);
  expect(title, 'no row in the middle third — the case proves nothing').not.toBe('');
  const row = page.getByText(title, { exact: true }).first();
  const rowY = (await row.boundingBox())!.y;
  await row.click();
  await expect(page.locator('app-reader-view').getByText(LAST_PARAGRAPH)).toBeAttached();
  return { row, rowY };
}

async function expectListBackAtItsOffset(page: Page): Promise<void> {
  await expect(page.locator('.article-overlay')).toHaveCount(0);
  await expect(page.locator('section.list')).not.toHaveClass(/page-pinned/);
  expect(await pageScrollY(page)).toBe(LIST_OFFSET);
}

test.describe('A full-screen article owns the page on a phone', () => {
  test.use({ viewport: PHONE });

  test.beforeEach(async ({ page }) => {
    await openReader(page, 'list');
  });

  test('opens at the top of the page over the list, held where it was', async ({ page }) => {
    const { row, rowY } = await openArticleFromList(page);

    expect(await pageScrollY(page)).toBe(0);
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect((await row.boundingBox())!.y).toBeCloseTo(rowY, 0);
  });

  test('scrolls the page while reading; its toolbar retracts, the list bar stays', async ({
    page,
  }) => {
    await openArticleFromList(page);
    const appBar = page.locator('app-reader-header');
    const appBarHidden = await appBar.evaluate((el) => el.classList.contains('hidden'));

    await scrollPageTo(page, ARTICLE_OFFSET);

    await expect(page.locator(`${LAYER} .bar`)).toHaveClass(/hidden/);
    expect((await page.locator(`${LAYER} .mini`).boundingBox())!.y).toBeCloseTo(0, 0);
    expect(await appBar.evaluate((el) => el.classList.contains('hidden'))).toBe(appBarHidden);
  });

  test('the back disc lands the list on its exact offset', async ({ page }) => {
    await openArticleFromList(page);
    await scrollPageTo(page, ARTICLE_OFFSET);

    await page.locator(`${LAYER} .corner-back`).click();

    await expectListBackAtItsOffset(page);
  });

  test('a swipe-back slides the article off the held list and lands on its offset', async ({
    page,
  }) => {
    const { row, rowY } = await openArticleFromList(page);
    await scrollPageTo(page, ARTICLE_OFFSET);

    await touchDrag(page, `${LAYER} .reader`, { x: 20, y: 400 }, { x: 260, y: 410 });
    await expect(page.locator(`${LAYER} .frame`)).toHaveAttribute('style', /translate3d\(240px/);
    expect((await row.boundingBox())!.y).toBeCloseTo(rowY, 0);

    await liftTouch(page, `${LAYER} .reader`);
    await expectListBackAtItsOffset(page);
  });

  test('a pull past the end of the article returns to the list at its offset', async ({
    page,
  }) => {
    await openArticleFromList(page);
    await scrollPageToEnd(page);

    await touchDrag(page, `${LAYER} .reader`, { x: 180, y: 600 }, { x: 180, y: 250 });
    await expect(page.locator(`${LAYER} .pull-back`)).toBeAttached();
    await liftTouch(page, `${LAYER} .reader`);

    await expectListBackAtItsOffset(page);
  });

  test('a link reader takes the page from the article and gives it back where it was', async ({
    page,
  }) => {
    await openArticleFromList(page);
    const openLink = page.locator('app-reader-view app-link-card button.open-in-reader');
    await openLink.scrollIntoViewIfNeeded();
    const articleOffset = await pageScrollY(page);
    expect(articleOffset, 'the card sits on the first screen — the case proves nothing').toBeGreaterThan(0);

    await openLink.click();
    const linkReader = page.locator('app-link-reader');
    await expect(linkReader.getByText(LAST_PARAGRAPH)).toBeAttached();
    expect(await pageScrollY(page)).toBe(0);
    await expect(page.locator('.article-overlay').first()).toHaveClass(/page-pinned/);
    await scrollPageTo(page, ARTICLE_OFFSET);

    await linkReader.locator('.corner-back').click();
    await expect(linkReader).toHaveCount(0);
    expect(await pageScrollY(page)).toBe(articleOffset);
    await expect(page.locator('.article-overlay')).not.toHaveClass(/page-pinned/);

    await page.locator(`${LAYER} .corner-back`).click();
    await expectListBackAtItsOffset(page);
  });
});

test.describe('A full-screen article owns the page beneath the app bar on a wide screen', () => {
  test.use({ viewport: WIDE });

  test('scrolls the page with its toolbar under the app bar, and back lands the list', async ({
    page,
  }) => {
    await openReader(page, 'list');
    await openArticleFromList(page);
    expect(await pageScrollY(page)).toBe(0);
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
    const sidebar = page.locator('aside.sidebar');
    const sidebarY = (await sidebar.boundingBox())!.y;

    await scrollPageTo(page, ARTICLE_OFFSET);

    const appBar = (await page.locator('app-reader-header header').boundingBox())!;
    const close = page.locator(`${LAYER} .bar .close`);
    expect((await close.boundingBox())!.y).toBeGreaterThanOrEqual(appBar.y + appBar.height);
    expect((await sidebar.boundingBox())!.y).toBe(sidebarY);

    await close.click();
    await expectListBackAtItsOffset(page);
  });
});

test.describe('Switching between split view and full screen with an article open', () => {
  test.use({ viewport: WIDE });

  test('carries the list’s and the article’s offsets both ways', async ({ page }) => {
    await openReader(page, 'pane');
    await openArticleFromList(page);
    const pane = page.locator('.main.split > .reader app-reader-view .scroller');
    await pane.evaluate((el, top) => el.scrollTo({ top, behavior: 'instant' }), ARTICLE_OFFSET);
    await expect.poll(() => pane.evaluate((el) => el.scrollTop)).toBe(ARTICLE_OFFSET);
    // Two frames, so the pane's scroll event has been heard and its offset remembered.
    await page.evaluate(
      () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
    );
    expect(await pageScrollY(page)).toBe(LIST_OFFSET);

    // Resized mid-test on purpose; each wait below gates on the layout actually switching.
    await page.setViewportSize(MEDIUM);
    await expect(page.locator('.main.split')).toHaveCount(0);
    await expect(page.locator('.article-overlay app-reader-view')).toBeVisible();
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
    await expect.poll(() => pageScrollY(page)).toBe(ARTICLE_OFFSET);

    await page.setViewportSize(WIDE);
    await expect(page.locator('.main.split')).toHaveCount(1);
    await expect.poll(() => pageScrollY(page)).toBe(LIST_OFFSET);
    await expect.poll(() => pane.evaluate((el) => el.scrollTop)).toBe(ARTICLE_OFFSET);
  });
});

test.describe('An action sheet over a full-screen article', () => {
  test.use({ viewport: PHONE, hasTouch: true, isMobile: true });

  test('holds the page while open and gives it back exactly, with nothing reacting', async ({
    page,
  }) => {
    await openReader(page, 'list');
    expect(
      await page.evaluate(() => matchMedia('(pointer: coarse)').matches),
      'not a coarse pointer — the share button opens a popover, not the sheet',
    ).toBe(true);
    await openArticleFromList(page);
    await scrollPageTo(page, ARTICLE_OFFSET);
    const bar = page.locator(`${LAYER} .bar`);
    await expect(bar).toHaveClass(/hidden/);
    const rail = page.locator(`${LAYER} app-progress-rail`);
    const fill = (): Promise<number> =>
      rail.evaluate((el) => parseFloat(el.style.getPropertyValue('--rail-fill')));
    const fillBefore = await fill();

    await page.locator(`${LAYER} .corner-actions button.corner-share`).click();
    await expect(page.locator('.app-action-sheet')).toBeVisible();
    await expect(page.locator('html')).toHaveClass(/cdk-global-scrollblock/);

    await page.keyboard.press('Escape');
    await expect(page.locator('.app-action-sheet')).toHaveCount(0);
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect(await pageScrollY(page)).toBe(ARTICLE_OFFSET);
    await expect(bar).toHaveClass(/hidden/);
    expect(await fill()).toBeCloseTo(fillBefore, 1);
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
  });
});
```

The memory note `playwright-never-resize-mid-test` forbids resizing without a settled signal. The swap test resizes because the switch is the behaviour under test. Each resize is gated on `.main.split` appearing or disappearing, which is the layout's own settled signal, so the wait is never vacuous.

- [ ] **Step 3: Typecheck, format and run**

Run: `npm run typecheck:e2e && npx prettier --write e2e/support/scroll.ts e2e/page-scroll.spec.ts e2e/article-page-scroll.spec.ts`
Expected: no type errors.
Run: `npx playwright test e2e/article-page-scroll.spec.ts e2e/page-scroll.spec.ts`
Expected: 9 + 7 pass.

Each test's own assertions say which piece failed. The likely causes:
- The swipe: `frame` style with no `translate3d(240px` means the gesture did not track. Check that the touches reached `app-article-layer`.
- The swap's first `pageScrollY` poll: the article restore did not land. The list's landing is the second poll.
- The sheet's `scrollY`: the CDK release hit a host that did not hold.

Fix the code, not the expectation.

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e
git commit -m "test(#2038): the article handoff on phone and wide, across the split, under a sheet"
```

---

### Task 9: Record the convention, live check, simplify, PR

**Files:**
- Modify: `docs/design-language.md` (section `### Sticky and scroll`, the "reader scrolls the page" paragraph)

- [ ] **Step 1: Record the convention**

In `docs/design-language.md`, in the paragraph that starts `**The reader scrolls the page (#2038).**`, replace

```markdown
frames and the full-screen article take that box from `page-chrome.list-column-box`
(`theme/_page-chrome.scss`).
```

with

```markdown
frames take that box from `page-chrome.list-column-box` (`theme/_page-chrome.scss`), and a
pinned page layer takes it from the same two properties.
```

Directly after that paragraph, insert:

```markdown
**A full-screen article takes the page from the list (#2038).** The shell's `PageScrollOwner`
keeps one page and a stack of layers (`appPageLayer`: the list column, each `.article-overlay`).
The top layer is in the flow and owns the document's offset; every layer beneath carries
`.page-pinned` (`position: fixed` in the list column's box, shifted up by `--page-pin-top`), so
it looks exactly as it did, and its `PageScrollHost` reports the pin, hears no scroll and moves
the pin on a write. Back pins the leaving layer, unpins the one beneath and writes its offset in
one call, before the next paint. The article layer's chrome sits in two zero-height **sticky**
wrappers (`.chrome-head`, `.chrome-foot`), never `fixed` boxes: the frame's swipe and the
overlay's slide transform their ancestors, which would trap a fixed child (#100). While pinned,
they turn absolute at `--page-pin-top`. Split view's reader pane has no handoff and keeps its
element scroller; a split ↔ full-screen switch remounts both panes, which land on their
remembered offsets.
```

Commit:

```bash
git add docs/design-language.md
git commit -m "docs(#2038): page layers in the sticky-and-scroll conventions"
```

- [ ] **Step 2: Live check on `:4200`**

Docker serves this checkout. Hard-reload first (memory: `:4200` can serve a stale chunk).

Sign in with the demo account, and check and note each result.

**Phone, 375×812, touch emulation on:**
1. Scroll the list.
2. Open an article. The page starts at 0. Scrolling retracts the toolbar while the nameplate stays put, the rail fills at the right edge with no native scrollbar, and the to-top and back discs sit at the foot clear of the audio bar (with a track loaded).
3. Swipe-back shows the list at its offset, and lands exactly.
4. End-pull returns exactly.
5. A link card opens the link reader at 0. Back to the article lands on its offset, and back again lands the list on its own.

**Wide, 1440×900, list and magazine layouts:**
1. The article opens beneath the app bar and scrolls the page.
2. The sidebar stays put. Hiding or showing it while an article is open keeps the pinned list in its column after back.
3. Back lands exactly.
4. With the browser's own back button, the slide-out plays with the toolbar in place.

**Pane layout:**
1. The split reader is unchanged: wheel at its end, and over a short article, leaves the list still.
2. With an article open, drag the window across 900px both ways. Both offsets carry.

**Dialogs over an open, scrolled article (Global §4):**
1. The share sheet (touch emulation).
2. A CDK dialog: the big player, opened from the audio bar while the article is open.
3. Nothing jumps, the toolbar and the app bar do not flip, the rail does not repaint full, and the offset is exact on close.

**Leaving:** with an article open, go to Settings. Settings opens at the top, and coming back restores the list.

**iOS simulator, Safari** (memory: `ios-simulator-webkit-debugging`):
1. Article open, scroll, swipe-back and end-pull.
2. Sticky chrome during the slide-in and the swipe. This is the WebKit risk in decision 2.
3. If the head or foot misplaces while transformed, report it with a screen recording. Do not patch it silently.

Scan the browser console for errors.

- [ ] **Step 3: `/simplify`, the gate, the build**

Run `/simplify` over the branch diff and apply what it finds. Then, from `frontend/`:

Run: `npm run check`
Expected: all green: ESLint, Prettier, Stylelint, tsc for app, spec and e2e, and Jest.
Run: `npm run build`
Expected: success.

- [ ] **Step 4: Push and open the PR**

First, `git fetch origin && git merge origin/develop` if `develop` moved since 01a09048b. Rerun Step 3 if it did.

```bash
git push -u origin feature/2038-page-scroll-phase-3
gh pr create --base develop --title "feat(#2038): a full-screen article scrolls the page" --body "$(cat <<'EOF'
Phase 3 of #2038 (spec: docs/superpowers/specs/2026-11-05-2038-page-scroll-on-narrow-design.md).

- `PageScrollOwner` keeps one page and a stack of layers (`appPageLayer`: the list column and
  each full-screen article or link reader). The top layer is in the flow and owns the document's
  offset, so a mobile browser collapses its bar while you read; every layer beneath is pinned at
  its offset (`.page-pinned`), and its `PageScrollHost` reports the pin, hears no scroll and
  moves the pin on a write.
- Back (button, swipe-back, end-pull, browser back) pins the leaving article so `article-leave`
  plays in place, unpins the layer beneath and writes its offset in one call, before paint.
- The article layer's chrome moves into two sticky wrappers that ride the swipe and slide
  transforms; split view keeps its element scroller. A split ↔ full-screen switch remounts both
  panes, which land on their remembered offsets (the list now does on a remount).
- The CDK lock is the drawer's alone; a sheet or dialog over an article holds and returns the
  page exactly. The audio bar sits above a narrow article (z 13).

Device check in Brave on the iPhone is phase 4.

Refs #2038
EOF
)"
```

The body says `Refs`, not `Closes`: #2038 stays open for phase 4.

---

## Self-review against the spec

| Spec requirement | Where |
|---|---|
| §2 `PageScrollOwner`: the layer that owns the page in flow, the others pinned `fixed; top: -offset` | Tasks 1–2, Task 6 Step 5 (column box instead of `inset-inline: 0`, decision 1) |
| Open: record the list offset, pin it, article in flow, page to 0 or the restore target | `PageScrollOwner.enter`; `ArticleScrollRestore` unchanged (it writes through the page host); Task 2 tests; Task 8 "opens at the top" |
| Back: pin the leaver so `article-leave` plays in place, unpin the list, scroll in the same task, before paint (Risk 2) | `PageScrollOwner.leave` in `PageLayerDirective`'s `DestroyRef` (decision 10); Task 2 "one call" test; `article-slide-out` in Task 6 Step 8 |
| Swipe-back over the pinned list, end-pull, the leave animation | Gestures unchanged; chrome rides the frame (decision 2); Task 8 swipe and pull tests |
| Link reader follows the same rule | Second `appPageLayer` overlay with its own injector; Task 2 three-layer test; Task 8 link test |
| Stacking: in-flow layer `position: relative`, z above the pinned list, opaque background on the sliding frame | Task 6 Step 5 (`.article-overlay`); `.reader` keeps the background; Task 4 `min-height: 100dvh` |
| Split view has no handoff; the pane stays an inner scroller | No `PageLayer` reaches the pane (decision 3); Task 6 swap test checks the pane has no `on-page` |
| Split ↔ full-screen swap carries both offsets (Risk 3) | Decision 4; Task 5; Task 6 swap test; Task 8 swap test |
| Drop the `articleFullscreen` term; what replaces it | Task 6 Step 3; decision 5; Task 6 CDK-lock test |
| CDK checks: article plus a sheet and a dialog, exact restore, hide-on-scroll and rail unmoved | Task 1 pinned-under-block test; Task 2 hand-back-under-block test; Task 8 sheet test; Task 9 live (sheet and dialog) |
| Browser bar collapses while reading | Follows from the article scrolling the document; Task 9 live check; device check NEEDS LARS (decision 14) |
| Reading focus and sections against the viewport top (Risk 4) | Decision 11; `scrollPaddingTop()` reads `content` (Task 1) |
| §5: the rail replaces the page scrollbar | Decision 6 (`page-overlaid`, Task 2 Step 6); rail full height on the page (Task 4) |
| `fixed` chrome trapped by a transformed ancestor (Risk 5) | Decision 2 (sticky wrappers); `.page-pinned` has no transformed ancestor; checked in WebKit live |
| Jest: `PageScrollOwner` pin, unpin, handoff, ordering; every consumer change | Tasks 1, 2, 4, 5, 6 |
| Playwright, phone and wide | Task 7 (moved specs), Task 8 (new spec) |

**Placeholder scan.** No "TBD", "similar to" or unwritten test remains. The one conditional is Task 5 Step 2: if the test already passes, the step says exactly what to do instead.

**Type consistency.** The names and signatures match across tasks:
- `PageLayer`: `pinAt`, `moveTo`, `unpin`, `pinned`, `pinnedTop`, `element`.
- `PAGE_PINNED_CLASS = 'page-pinned'` and `PAGE_PIN_TOP = '--page-pin-top'`, used in Tasks 1, 2, 4 and 6.
- `PAGE_OVERLAID_CLASS = 'page-overlaid'`, in Task 2 (`.ts` and `_base.scss`).
- `ScrollHosts.forPage(content, layer = null)`, in Tasks 1 and 4.
- `PageScrollOwner.enter` and `leave`, in Tasks 2 and 6.
- `PageLayerDirective.injector`, exported as `appPageLayer`, in Tasks 2 and 6.
- `on-page`, in Tasks 4, 6 and 8.
- The e2e helpers `scrollPageToEnd` (Task 7), and `rowTitleInView`, `touchDrag` and `liftTouch` (Task 8), are each defined before they are used.
