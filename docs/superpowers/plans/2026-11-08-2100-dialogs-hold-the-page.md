# #2100 Dialogs hold the page without shrinking it

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** opening and closing a CDK dialog on iOS Brave leaves a collapsed address bar
collapsed, the page does not scroll behind the dialog, and it is exactly where it was after.

**Architecture:** one root `PageHold` service owns `html.page-held { overflow: hidden }`
(the #2088 drawer rule, made app-wide) and reference-counts its holders. A
`PageHoldScrollStrategy` takes a hold while its overlay is attached; it replaces CDK's block
strategy as the `DIALOG_SCROLL_STRATEGY` default. The drawer's `holdReaderPage` takes the same
hold. Nothing puts `cdk-global-scrollblock` on `<html>` any more, so `PageScrollHost` and
`PageScrollOwner` drop their block handling.

**Tech Stack:** Angular 20, `@angular/cdk` 20.2 (`dialog`, `overlay`), Jest (jsdom), Playwright.

**Spec:** issue #2100; background in `docs/superpowers/plans/2026-11-08-2088-keep-collapsed-address-bar.md`.

## Why

CDK's `BlockScrollStrategy` makes `<html>` `position: fixed` with `top: -offset`. The page's
content height drops to the viewport, and Brave expands its bar whenever the content gets that
short (`ToolbarVisibilityViewModel.contentSizeChanged`, brave-core). `overflow: hidden` on
`<html>` stops user scrolling and keeps both offset and height; #2088 proved it on device for
the drawer.

## Global Constraints

- Frontend tests run in the container: `docker compose exec -T frontend npx jest <paths>`;
  the gate is `docker compose exec -T frontend npm run check`.
- Component styles stay in `.scss`; this change only touches `src/styles/_base.scss`.
- `ToastService` keeps its explicit `noop()` strategy (a toast must not hold the page).
- Comments: one line, only where a reader would get the code wrong without it.
- Commits: `type(#2100): …`, no attribution lines.

## Behaviour change to accept

- A script write to the page offset while a dialog is open (e.g. a list reload behind a
  confirm) now lands immediately instead of being deferred to the release. `overflow: hidden`
  still allows script scrolling; the end state is the same.
- On a desktop with classic (non-overlay) scrollbars, the page scrollbar disappears while a
  dialog is open, so content may shift by its width. CDK's block kept it with
  `overflow-y: scroll`. The reader page already hides its scrollbar (`page-scrollbar-hidden`)
  and macOS/iOS/Android use overlay scrollbars, so this is left as is. Open question for
  review: add `scrollbar-gutter: stable` to the rule if Lars wants it (that adds a gutter on
  short pages instead).

## Files

| File | Responsibility |
|---|---|
| Create `frontend/src/app/shared/page-hold/page-hold.ts` | `PageHold` service + `PAGE_HELD_CLASS` |
| Create `frontend/src/app/shared/page-hold/page-hold.spec.ts` | its spec |
| Create `frontend/src/app/shared/page-hold/page-hold-scroll-strategy.ts` | `PageHoldScrollStrategy`, `provideHeldPageDialogs()` |
| Create `frontend/src/app/shared/page-hold/page-hold-scroll-strategy.spec.ts` | its spec, with a real `Dialog` |
| Modify `frontend/src/app/app.config.ts` | register `provideHeldPageDialogs()` |
| Modify `frontend/src/styles/_base.scss:48-51` | rule becomes `html.page-held` |
| Modify `frontend/src/app/reader/shell/reader-page.ts` (+ spec) | drawer takes a `PageHold` hold |
| Modify `frontend/src/app/shared/scroll-host/page-scroll-host.ts` (+ spec) | drop block handling |
| Modify `frontend/src/app/reader/shell/page-scroll-owner.ts` (+ spec) | drop the arming listener |
| Modify `frontend/src/app/reader/shell/reader-shell.component.spec.ts:1003-1004` | drop block cleanup |
| Modify `frontend/e2e/article-page-scroll.spec.ts:380-410`, `frontend/e2e/page-scroll.spec.ts:118` | page-held instead of block |

---

### Task 1: `PageHold`, app-wide rule, drawer on it

**Files:**
- Create: `frontend/src/app/shared/page-hold/page-hold.ts`, `page-hold.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-page.ts`, `reader-page.spec.ts`, `frontend/src/styles/_base.scss`

**Interfaces:**
- Produces: `PAGE_HELD_CLASS = 'page-held'`; `@Injectable({ providedIn: 'root' }) class PageHold { hold(): () => void }` —
  the returned release is idempotent; the class stays while any hold is unreleased.

- [ ] **Step 1: Write the failing spec** `page-hold.spec.ts`

```ts
import { TestBed } from '@angular/core/testing';
import { PAGE_HELD_CLASS, PageHold } from './page-hold';

describe('PageHold', () => {
  const root = document.documentElement;
  let pageHold: PageHold;

  beforeEach(() => {
    pageHold = TestBed.inject(PageHold);
  });

  afterEach(() => root.classList.remove(PAGE_HELD_CLASS));

  it('holds the page until released', () => {
    const release = pageHold.hold();
    expect(root.classList).toContain(PAGE_HELD_CLASS);

    release();
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });

  it('holds while any holder is left, so a dialog closing over the drawer keeps it held', () => {
    const releaseDrawer = pageHold.hold();
    const releaseDialog = pageHold.hold();

    releaseDialog();
    expect(root.classList).toContain(PAGE_HELD_CLASS);

    releaseDrawer();
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });

  it('counts a release once, however often it is called', () => {
    const releaseDrawer = pageHold.hold();
    const releaseDialog = pageHold.hold();

    releaseDialog();
    releaseDialog();
    expect(root.classList).toContain(PAGE_HELD_CLASS);

    releaseDrawer();
  });
});
```

- [ ] **Step 2: Run, expect FAIL** (module not found)

`docker compose exec -T frontend npx jest src/app/shared/page-hold/page-hold.spec.ts`

- [ ] **Step 3: Implement** `page-hold.ts`

```ts
import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';

/** On `<html>` while anything holds the page. Not CDK's block strategy: its fixed `<html>`
 *  shrinks the page to the viewport, which expands Brave's collapsed toolbar (#2088, #2100). */
export const PAGE_HELD_CLASS = 'page-held';

@Injectable({ providedIn: 'root' })
export class PageHold {
  private readonly root = inject(DOCUMENT).documentElement;
  private holders = 0;

  /** Stops the page scrolling where it stands, keeping its height; returns the release. */
  hold(): () => void {
    this.holders++;
    this.root.classList.add(PAGE_HELD_CLASS);
    let released = false;
    return () => {
      if (released) return;
      released = true;
      this.holders--;
      if (this.holders === 0) this.root.classList.remove(PAGE_HELD_CLASS);
    };
  }
}
```

- [ ] **Step 4: Run, expect PASS** (same command)

- [ ] **Step 5: Drawer on `PageHold`.** In `reader-page.ts` remove `PAGE_HELD_CLASS` and its
  comment, and replace the lock handling:

```ts
import { DOCUMENT } from '@angular/common';
import { DestroyRef, EffectRef, Signal, effect, inject } from '@angular/core';
import { pageScrollingElement } from '../../core/page-scrolling-element';
import { PageHold } from '../../shared/page-hold/page-hold';

/** On `<html>` while the reader owns the page; `styles/_base.scss` keys the page rules to it. */
export const READER_PAGE_CLASS = 'reader-page';

export function holdReaderPage(locked: Signal<boolean>): EffectRef {
  const document = inject(DOCUMENT);
  const pageHold = inject(PageHold);
  const root = document.documentElement;
  const history = document.defaultView?.history;
  const restoration = history?.scrollRestoration;
  root.classList.add(READER_PAGE_CLASS);
  if (history) history.scrollRestoration = 'manual';
  inject(DestroyRef).onDestroy(() => {
    root.classList.remove(READER_PAGE_CLASS);
    if (history && restoration) history.scrollRestoration = restoration;
    pageScrollingElement(document).scrollTop = 0;
  });
  return effect((onCleanup) => {
    if (locked()) onCleanup(pageHold.hold());
  });
}
```

  In `reader-page.spec.ts`: import `PAGE_HELD_CLASS` from `../../shared/page-hold/page-hold`,
  drop the `PAGE_SCROLL_BLOCK_CLASS` import and use the literal `'cdk-global-scrollblock'` in
  the `#2088` test's negative assertion. The existing "lets go of a held page with the reader"
  test now proves the effect's cleanup releases the hold on destroy.

- [ ] **Step 6: App-wide rule.** In `src/styles/_base.scss` replace

```scss
/* PAGE_HELD_CLASS (reader-page.ts): stops the page scrolling and keeps its height. */
html.reader-page.page-held {
  overflow: hidden;
}
```

  with

```scss
/* PAGE_HELD_CLASS (shared/page-hold): stops the page scrolling and keeps its height. */
html.page-held {
  overflow: hidden;
}
```

- [ ] **Step 7: Run** `docker compose exec -T frontend npx jest src/app/shared/page-hold src/app/reader/shell/reader-page.spec.ts src/app/reader/shell/reader-shell.component.spec.ts` — expect PASS.

- [ ] **Step 8: Commit** `refactor(#2100): one reference-counted page hold, app-wide; the drawer takes it`

### Task 2: Dialogs hold the page

**Files:**
- Create: `frontend/src/app/shared/page-hold/page-hold-scroll-strategy.ts`, `page-hold-scroll-strategy.spec.ts`
- Modify: `frontend/src/app/app.config.ts`

**Interfaces:**
- Consumes: `PageHold.hold(): () => void`, `PAGE_HELD_CLASS` (Task 1).
- Produces: `class PageHoldScrollStrategy implements ScrollStrategy`; `provideHeldPageDialogs(): Provider`.

- [ ] **Step 1: Write the failing spec.** It opens a real CDK `Dialog`, so it proves the
  token wiring, not only the strategy. (jsdom's page never overflows, so CDK's block would not
  add its class here anyway — the positive `page-held` assertion is what fails today.)

```ts
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { PAGE_HELD_CLASS, PageHold } from './page-hold';
import { provideHeldPageDialogs } from './page-hold-scroll-strategy';

@Component({ template: 'held' })
class HeldDialogComponent {}

describe('provideHeldPageDialogs', () => {
  const root = document.documentElement;
  let dialog: Dialog;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHeldPageDialogs()] });
    dialog = TestBed.inject(Dialog);
  });

  afterEach(() => {
    dialog.closeAll();
    root.classList.remove(PAGE_HELD_CLASS);
  });

  it('holds the page while a dialog is open, never pinning <html> (#2100)', () => {
    const ref = dialog.open(HeldDialogComponent);
    expect(root.classList).toContain(PAGE_HELD_CLASS);
    expect(root.classList).not.toContain('cdk-global-scrollblock');

    ref.close();
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });

  it('keeps a held drawer held when a dialog over it closes', () => {
    const releaseDrawer = TestBed.inject(PageHold).hold();
    dialog.open(HeldDialogComponent).close();

    expect(root.classList).toContain(PAGE_HELD_CLASS);
    releaseDrawer();
  });

  it('holds until the last of two stacked dialogs closes', () => {
    const below = dialog.open(HeldDialogComponent);
    const above = dialog.open(HeldDialogComponent);

    above.close();
    expect(root.classList).toContain(PAGE_HELD_CLASS);

    below.close();
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });
});
```

  If `ref.close()` releases only after the container's exit animation in jsdom, use
  `fakeAsync` + `flush()` after each close rather than weakening the assertions.

- [ ] **Step 2: Run, expect FAIL** (module not found)

`docker compose exec -T frontend npx jest src/app/shared/page-hold/page-hold-scroll-strategy.spec.ts`

- [ ] **Step 3: Implement** `page-hold-scroll-strategy.ts`

```ts
import { Provider, inject } from '@angular/core';
import { DIALOG_SCROLL_STRATEGY } from '@angular/cdk/dialog';
import type { ScrollStrategy } from '@angular/cdk/overlay';
import { PageHold } from './page-hold';

/** Holds the page while its overlay is attached, instead of pinning `<html>` (#2100). */
export class PageHoldScrollStrategy implements ScrollStrategy {
  private release: (() => void) | null = null;

  constructor(private readonly pageHold: PageHold) {}

  attach(): void {}

  enable(): void {
    this.release ??= this.pageHold.hold();
  }

  disable(): void {
    this.release?.();
    this.release = null;
  }
}

export function provideHeldPageDialogs(): Provider {
  return {
    provide: DIALOG_SCROLL_STRATEGY,
    useFactory: () => {
      const pageHold = inject(PageHold);
      return () => new PageHoldScrollStrategy(pageHold);
    },
  };
}
```

- [ ] **Step 4: Run, expect PASS** (same command)

- [ ] **Step 5: Register.** In `app.config.ts` import `provideHeldPageDialogs` from
  `./shared/page-hold/page-hold-scroll-strategy` and add `provideHeldPageDialogs(),` after the
  `LocationStrategy` provider, next to the other #2088 measure.

- [ ] **Step 6: Run** `docker compose exec -T frontend npx jest src/app/shared` — expect PASS.

- [ ] **Step 7: Commit** `fix(#2100): dialogs hold the page instead of pinning <html>, so Brave keeps its bar collapsed`

### Task 3: Drop the CDK-block handling

Nothing puts `cdk-global-scrollblock` on `<html>` any more (the toast is `noop`, connected
overlays reposition), so the deferred-write machinery is dead code.

**Files:**
- Modify: `frontend/src/app/shared/scroll-host/page-scroll-host.ts`, `page-scroll-host.spec.ts`
- Modify: `frontend/src/app/reader/shell/page-scroll-owner.ts`, `page-scroll-owner.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.spec.ts`

- [ ] **Step 1: `PageScrollHost`.** Delete `PAGE_SCROLL_BLOCK_CLASS`, `pendingTop`,
  `landPendingTop`, `blocked()`, the `root` getter and the `MutationObserver` in `listen`.
  `held()` becomes `this.layer?.pinned ?? false`. The class comment becomes
  `/** While its layer is pinned beneath another (#2038), it reports the pinned offset, stays silent, and moves the pin on writes. */`.
  Resulting members:

```ts
  listen(listener: () => void): () => void {
    const view = this.view;
    const onScroll = (): void => {
      if (!this.held()) listener();
    };
    this.outsideZone(() => view.addEventListener('scroll', onScroll, { passive: true }));
    return () => view.removeEventListener('scroll', onScroll);
  }

  private get pageTop(): number {
    if (this.layer?.pinned) return this.layer.pinnedTop;
    return this.scrollingElement.scrollTop;
  }

  private set pageTop(top: number) {
    if (this.layer?.pinned) {
      this.layer.moveTo(top);
      return;
    }
    this.announceWrite(top);
    this.scrollingElement.scrollTop = top;
  }

  private held(): boolean {
    return this.layer?.pinned ?? false;
  }
```

- [ ] **Step 2: Its spec.** Delete `describe('under a CDK block')`, the two pinned-layer
  tests that use `blockAt` ("reads its pin, not the block…", "lands a write held under a CDK
  block…"), the `blockAt`/`releaseTo`/`mutationsDelivered` helpers, and the
  `PAGE_SCROLL_BLOCK_CLASS`/`root.style.top` lines in `afterEach`.

- [ ] **Step 3: `PageScrollOwner`.** Delete the constructor's `listen` and its comment; the
  destroy hook keeps only the class removal:

```ts
  constructor() {
    inject(DestroyRef).onDestroy(() =>
      this.document.documentElement.classList.remove(PAGE_OVERLAID_CLASS),
    );
  }
```

  In its spec delete the test "lands the hand-back after a CDK dialog that holds the page
  lets go", the `PAGE_SCROLL_BLOCK_CLASS` import, and the two block lines in `afterEach`.

- [ ] **Step 4: Reader shell spec.** In `reader-shell.component.spec.ts` (~line 1003) delete
  `root.classList.remove('cdk-global-scrollblock');` and `root.style.top = '';`. Keep the
  "never with a CDK block" assertion at ~1058: it still guards the drawer.

- [ ] **Step 5: Verify nothing references the block any more**

`grep -rn "PAGE_SCROLL_BLOCK_CLASS\|landPendingTop\|pendingTop" frontend/src` — expect no output.

- [ ] **Step 6: Run** `docker compose exec -T frontend npx jest src/app/shared/scroll-host src/app/reader/shell` — expect PASS.

- [ ] **Step 7: Commit** `refactor(#2100): drop PageScrollHost's CDK-block handling; nothing blocks the page any more`

### Task 4: e2e and the gate

**Files:**
- Modify: `frontend/e2e/article-page-scroll.spec.ts:380-410`, `frontend/e2e/page-scroll.spec.ts:118`, `frontend/e2e/article-page-scroll.spec.ts:136`

- [ ] **Step 1: Action sheet holds the page at its height.** In the test "holds the page while
  open and gives it back exactly, with nothing reacting": record
  `const height = await pageScrollHeight(page);` (add `pageScrollHeight` to the existing
  import from `./support/scroll`) before opening the sheet; replace
  `toHaveClass(/cdk-global-scrollblock/)` with `toHaveClass(/page-held/)` and add after it

```ts
    // A page that shrinks to the viewport expands Brave's collapsed toolbar (#2100).
    expect(await pageScrollHeight(page)).toBe(height);
    await page.mouse.wheel(0, 600);
    expect(await pageScrollY(page)).toBe(ARTICLE_OFFSET);
```

  and replace the post-Escape `not.toHaveClass(/cdk-global-scrollblock/)` with
  `not.toHaveClass(/page-held/)`.

- [ ] **Step 2:** The two `not.toHaveClass(/cdk-global-scrollblock/)` checks at
  `page-scroll.spec.ts:118` and `article-page-scroll.spec.ts:136` now guard nothing; delete them.

- [ ] **Step 3: Run** (Docker stack up, from this checkout)
  `cd frontend && npx playwright test e2e/article-page-scroll.spec.ts e2e/page-scroll.spec.ts --trace off` — expect PASS.

- [ ] **Step 4: Gate** `docker compose exec -T frontend npm run check` — expect PASS.

- [ ] **Step 5: Commit** `test(#2100): e2e holds the page at its height under a dialog`

### Task 5: Device check (Lars)

Docker `:4200` serves the branch. On an iPhone in Brave, with the bar collapsed: open a
dialog (add feed, confirm, action sheet, composer) and close it. The bar stays collapsed, the
page does not scroll behind the dialog, and the list is exactly where it was. Also try a
dialog with a text field (composer): the keyboard opening must not move the page behind it.
