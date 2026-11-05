# The reader scrolls the page (#2038)

## Problem

Mobile browsers (Brave, Safari, Chrome) collapse their address bar only when the
**document** scrolls. The reader never scrolls the document: the shell is locked to
`100dvh` with `overflow: hidden`, and scrolling happens in inner boxes — the list's
`.rows` and the article layer's `.scroller`. So the browser bar never hides, and on a
phone it permanently takes a strip of the screen.

There is no API that asks a browser to hide its bar. The only lever is to let the
document scroll.

## Goal and scope

At **every width**, the list and any full-screen article (the overlay over the list) scroll
the page, so the browser bar collapses on scroll-down and returns on scroll-up, exactly as
on a static page. One mechanism everywhere instead of a narrow-only fork (decided
2026-11-05, after phase 1; the issue's "wide layout unchanged" is superseded).

The one inner scroller left is **split view's reader pane**: one page offset cannot drive
two panes side by side, so the list owns the page and the reader pane is pinned beside it
with its own scroller (§2). The sidebar column is pinned the same way.

Kept unchanged in behaviour: the app bar's and list header's hide-on-scroll, the
article toolbar's hide-on-scroll, list scroll memory and restore, article scroll
restore, above-the-fold and reading focus, the green progress rail **with its
all-entries estimate** (#1392), to-top, pull-to-refresh, swipe-back, the article's
end-pull back.

Out of scope: an installable PWA (manifest, standalone display). Considered and set
aside for this issue; it removes the bar entirely but only for a home-screen launch.

## Design

### 1. The `ScrollHost` seam

Every scroll consumer today takes its scroller as `() => HTMLElement | undefined` (or a
`Signal` of it) and reads `scrollTop`, `clientHeight`, `scrollHeight`,
`getBoundingClientRect()`, `scrollTo()` and listens for `scroll`. That raw element is
replaced by a `ScrollHost`:

- the scroll offset, the visible height, the content height;
- `scrollTo(options)`;
- a scroll listener registered outside the Angular zone (#501), returning its removal;
- the content's origin in viewport coordinates (what `EstimatedListBottom.measure` and
  the reading-focus/section measurements compute today as
  `scroller.getBoundingClientRect().top - scroller.scrollTop`);
- the element to query rows and attach touch listeners to.

Two implementations:

- **`ElementScrollHost`** wraps an element — split view's reader pane (and, until phase 2
  lands, every pane).
- **`PageScrollHost`** reads `document.scrollingElement`, listens for `scroll` on
  `window`, takes `window.innerHeight` as the visible height (it grows while the browser
  bar collapses; `clientHeight` of the root does not), and measures from viewport top.

The list always takes the page host. A full-screen article takes the page host while it
owns the page (§2); the reader pane in split view takes an element host. Switching between
split and full-screen article (a layout change or the breakpoint) swaps the article's host
and carries its offset across.

Consumers to move onto the seam: `ListScrollState`, `PullToRefresh`,
`EstimatedListBottom`, `ListAboveFold`/`above-fold`, `ListReadingFocus`,
`ScrollProgressRail` + `ScrollProgressDirective`, `ArticleLayerComponent`,
`ArticleGestures`, `ArticleScrollRestore`, `ReadingScope`, `ReadingFocusApplier`,
`ReadingSections`, `ArticlePageComponent`, the to-top button,
`ScrollOutsideZoneDirective`, `reader-gestures` (`ScrollMetrics`, `atBottom`),
`blank-screen-probe`. The phase-1 PR moves them all with no behaviour change: every
pane still gets an `ElementScrollHost`.

### 2. `PageScrollOwner`: one page, two layers

The list stays mounted beneath a full-screen article (at every width that shows one), but
the document has one scroll offset. Rule: **the layer that owns the page is in normal flow; any other
layer is pinned** with `position: fixed; top: -<its offset>px; inset-inline: 0`, so it
looks exactly as it did and keeps its offset.

- **Open an article:** record the list's page offset, pin the list, put the article in
  flow, scroll the page to 0 (or to `ArticleScrollRestore`'s target).
- **Back** (button, swipe-back, end-pull): pin the leaving article at its current
  offset so its `article-leave` animation plays in place, unpin the list, and scroll
  the page to the list's recorded offset **before the next paint** (synchronously after
  the class change, in the same task) — no frame of the list at the top.
- **Swipe-back:** the article frame slides over the pinned list, which shows through at
  its offset, as today.
- **Link reader** (`linkTarget`) is a second article-shaped layer and follows the same
  rule.
- **Stacking:** a `fixed` layer paints above unpositioned in-flow content, so the
  in-flow article layer is `position: relative` with a `z-index` above the pinned list,
  and its opaque background sits on the frame that slides, not on the layer box —
  otherwise the pinned list paints over the article, or a swipe-back uncovers the
  article's own background instead of the list (both seen in the spike).

**Split view** has no handoff: the list owns the page for good. The reader pane is pinned
beside it (`position: sticky; top: 0` with the viewport's height, or `fixed` to the list's
right) and keeps an element scroller with `overscroll-behavior: contain`, so a wheel or
trackpad that reaches the pane's end does not scroll the list underneath. A link reader in
the pane covers the pane, as today.

`PageScrollOwner` owns only this handoff; it is the one place that writes the page
offset on a layer switch. `ListScrollMemory` keeps owning what offset a selection
restores to.

Rejected alternatives: unmounting the list while reading (loses the list showing
through a swipe-back) and keeping inner scrollers while syncing a fake page scroll
(fights the browser every frame).

### 3. Chrome is `position: fixed`

Everything positioned against the shell today moves to `position: fixed`: the app bar
(hide-on-scroll), the list's collapsing header, the corner buttons and to-top, the
article toolbar, its mini nameplate and corner actions, the progress rail, the audio
player bar, the `.under-header` banners. The shell drops `height: 100dvh` and
`overflow: hidden` so the page can grow.

Pinned columns: the **sidebar column** (721 px and up) and, in split view, the **pane
divider** and **reader pane** are `position: sticky; top: 0` with the viewport's height
(`100dvh`), so they stay put while the list scrolls the page. The sidebar keeps its own
scroller with `overscroll-behavior: contain`. The narrow **drawer** becomes `fixed` (its
"anchored to the shell host" rule existed for iOS rubber-banding of the page, which
`overscroll-behavior-y: none` now prevents) and locks the page while open (§4). The list's
horizontal extent stays the `.list` column's; fixed list chrome (header, rail, corner
buttons) is positioned against that column's box — its left edge and width published as
custom properties by the shell, since `fixed` measures from the viewport.

A `fixed` element is trapped by any ancestor with a transform, filter or containment.
`.main` already avoids being one (#100). The article frame's swipe transform **is** one,
so the article's fixed chrome must sit outside the frame that moves (or move with it on
purpose, as the toolbar does today). Each new `fixed` element is checked for this.

Bottom chrome anchored with `fixed` follows the visible viewport, so it moves with the
browser toolbar as it collapses — intended.

**Accepted trade-off (#1332 returns):** on page scroll, iOS spends the first tap after
a flick on stopping the coasting page, and fixed chrome is now inside that scroller. A
tap on the app bar, a corner button or the article toolbar while the page coasts only
stops it; a second tap acts. #1332 kept chrome outside the scroller to avoid exactly
this; it is how every ordinary page behaves in Safari and Brave, and the collapsing
browser bar was judged worth it. Do not try to work around it.

### 4. Gestures and browser behaviour

- **Overscroll:** the reader sets `html { overscroll-behavior-y: none }`. Brave/Chrome on
  Android otherwise run their own pull-to-reload (a full app reload) and iOS rubber-bands
  the page edge; either would steal the list's pull-to-refresh and the article's
  end-pull back. Both gestures keep their trigger ("touch starts at top / at end"),
  read through the `ScrollHost`. On iOS the pull's non-passive `preventDefault` already
  suppresses the bounce (spike: 300 px pull, `scrollY` never below 0, with and without
  the rule); the rule is there for Android and for edges no handler holds.
- **History:** `history.scrollRestoration = 'manual'`, so back/forward and reload never
  restore a stale document offset over `ListScrollMemory`/`ArticleScrollRestore`.
- **CDK dialogs:** their default `block` strategy pins `<html>` at the current offset and
  restores it on close. With page scroll that becomes real behaviour (no scrolling under
  a dialog) — wanted. The spike showed the restore is exact (2538 → 2538) and the list
  stays visually in place, **but** while blocked the page reports `scrollY` 0 and no
  overflow, and the restore on close reads as a scroll: the rail painted full and the
  app bar flipped. So `PageScrollHost` reports no scroll while `<html>` carries
  `cdk-global-scrollblock` and re-baselines (offset, last offset) silently on release;
  hide-on-scroll and the rail never see the block. The browser bar re-expands while a
  dialog is open — acceptable. Checked again in the app with an open article plus an
  action sheet and a dialog. Toasts already use `noop`.
- **Sidebar drawer** on narrow locks the page while open, the same way.
- **Wheel and trackpad** (desktop): pinned scrollers — the sidebar column and split view's
  reader pane — set `overscroll-behavior: contain`, so scrolling them to their end never
  scrolls the list. A wheel over pinned chrome that is not a scroller (app bar, list
  header) scrolls the page, i.e. the list — the same as any page with a fixed header.
- **Keyboard:** Space/PageDown/arrows scroll the page when focus is in the list, as they
  scrolled `.rows` before; with focus in the reader pane they scroll the pane.
- **Programmatic jumps:** scrolling the page to 0 on article open brings the browser bar
  back; the jump back to the list's offset leaves its state alone (spike: stayed
  collapsed). Both fine.

### 5. The green progress rail

`ScrollProgressRail` keeps its contract and its all-entries estimate; it reads through
the `ScrollHost`. At **every width** it is the vertical rail that stands in for the
scrollbar (decided 2026-11-05): on the list it sits at the `.list` column's right edge,
with the page's scrollbar hidden by `scrollbar-width: none` on `<html>`; in split view
the reader pane's rail does the same for the pane's scrollbar. The wide layout's bottom
hairline goes. The reason is split view: the page's native scrollbar would sit at the
window's far right, outside the reader pane and beside the pane's own scrollbar, yet move
the list on the left. Cost: mouse users can no longer drag a scrollbar; wheel, trackpad
and keys are unaffected. The spike confirmed `scrollbar-width: none` hides iOS WebKit's
own page scroll indicator (frame-by-frame recording against a control with the rule off,
which showed the native indicator beside the rail).

**Fallback, only if the iPhone shows otherwise:** on a coarse pointer the rail on a
`PageScrollHost` uses the `horizontal` mode (bottom hairline), so it never sits on top of
the native indicator. The `horizontal` mode stays in the code until the phase-4 iPhone
check settles this; if unused then, phase 4 deletes it.

## Risks

1. A real iPhone may still show its own page scroll indicator (the simulator hid it) →
   rail fallback above.
2. The offset restore on back must land before paint, or the list flashes at the top.
   The spike measured it exact on the first frame; the app's Angular render path must
   keep the pin/unpin and the `scrollTo` in one task.
3. Rotation/resize across the breakpoint (or a split ↔ full-screen layout switch) with an
   article open must carry both offsets.
6. Fixed list chrome must follow the `.list` column's box (sidebar shown/hidden, the
   split divider dragged) — a stale left edge or width misplaces the header and rail.
7. Desktop scroll feel: wheel over the sidebar or reader pane must not leak into the
   list; a short reader pane that does not overflow is still a scroll container, so
   `contain` holds there too.
4. Reading focus/sections measure against the scroller's top; on the page that is the
   viewport top, partly covered by fixed chrome — the existing reserved-height
   variables (`--reader-top`, `$header-clear`) must feed the measurement.
5. `fixed` chrome trapped by a transformed ancestor (§3).

## Delivery

A series of PRs off `develop`, each shippable:

1. **Seam** — `ScrollHost` + `ElementScrollHost`; every consumer moved; no behaviour
   change.
2. **List on the page** — `PageScrollHost`; the list scrolls the document at every
   width; shell and list chrome fixed; sidebar column, divider and split reader pane
   pinned with `contain`; the vertical rail everywhere; overscroll, history, drawer lock.
   Full-screen articles still inner-scroll (a `fixed` overlay with `contain`).
3. **Article handoff** — `PageScrollOwner`; full-screen article and link reader on the
   page at every width; split ↔ full-screen host swap; swipe-back, end-pull, leave
   animation; CDK checks.
4. **Polish** — whatever the iPhone Brave live check turns up (the rail fallback only if
   the device shows the native indicator).

The assumptions above were checked in a throwaway prototype in the iOS 27 simulator's
Safari on 2026-11-05 (Brave on iOS shares the WebKit view, not the toolbar, so the
device check stays).

## Testing

- **Jest:** both `ScrollHost` implementations; `PageScrollOwner` pin/unpin/offset
  handoff; existing consumer specs moved onto a fake host (phase 1 must keep them
  green unchanged in meaning).
- **Playwright, phone viewport:** scrolling the list moves `window.scrollY`; opening an
  article starts at 0; back returns to the list's exact offset; the rail fills;
  pull-to-refresh fires. Data via stubbed routes (an e2e spec owns its data).
- **Playwright, wide viewport:** scrolling the list moves `window.scrollY`; the sidebar
  and split reader pane stay in place; wheel at the reader pane's end leaves
  `window.scrollY` unchanged; the vertical rail sits at the list column's right edge
  and follows a divider drag.
- **Live check in Brave on the iPhone** (the point of the issue): bar collapses in list
  and article, back lands exactly, swipe-back and end-pull work, rail vs. native
  indicator. Docker's `:4200` serves the main checkout, not this worktree, so the
  worktree build is served separately for it.
