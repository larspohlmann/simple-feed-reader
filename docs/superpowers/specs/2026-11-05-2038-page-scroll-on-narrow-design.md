# Narrow layout scrolls the page (#2038)

## Problem

Mobile browsers (Brave, Safari, Chrome) collapse their address bar only when the
**document** scrolls. The reader never scrolls the document: the shell is locked to
`100dvh` with `overflow: hidden`, and scrolling happens in inner boxes — the list's
`.rows` and the article layer's `.scroller`. So the browser bar never hides, and on a
phone it permanently takes a strip of the screen.

There is no API that asks a browser to hide its bar. The only lever is to let the
document scroll.

## Goal and scope

On the **narrow layout**, the list and the full-screen article scroll the page, so the
browser bar collapses on scroll-down and returns on scroll-up, exactly as on a static
page.

The **wide layout** (split pane, full-pane article beside the sidebar) keeps its inner
scrollers. One page scroll cannot drive two panes, and desktop browsers do not collapse
their bar.

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

- **`ElementScrollHost`** wraps an element — today's behaviour, used by the wide layout.
- **`PageScrollHost`** reads `document.scrollingElement`, listens for `scroll` on
  `window`, takes `window.innerHeight` as the visible height (it grows while the browser
  bar collapses; `clientHeight` of the root does not), and measures from viewport top.

The pane chooses its host from `LayoutService.isNarrow()`; crossing the breakpoint
swaps hosts and carries the current offset across.

Consumers to move onto the seam: `ListScrollState`, `PullToRefresh`,
`EstimatedListBottom`, `ListAboveFold`/`above-fold`, `ListReadingFocus`,
`ScrollProgressRail` + `ScrollProgressDirective`, `ArticleLayerComponent`,
`ArticleGestures`, `ArticleScrollRestore`, `ReadingScope`, `ReadingFocusApplier`,
`ReadingSections`, `ArticlePageComponent`, the to-top button,
`ScrollOutsideZoneDirective`, `reader-gestures` (`ScrollMetrics`, `atBottom`),
`blank-screen-probe`. The phase-1 PR moves them all with no behaviour change: every
pane still gets an `ElementScrollHost`.

### 2. `PageScrollOwner`: one page, two layers

On narrow the list stays mounted beneath a full-screen article, but the document has
one scroll offset. Rule: **the layer that owns the page is in normal flow; any other
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

`PageScrollOwner` owns only this handoff; it is the one place that writes the page
offset on a layer switch. `ListScrollMemory` keeps owning what offset a selection
restores to.

Rejected alternatives: unmounting the list while reading (loses the list showing
through a swipe-back) and keeping inner scrollers while syncing a fake page scroll
(fights the browser every frame).

### 3. Chrome on narrow is `position: fixed`

Everything positioned against the shell today moves to `position: fixed` on narrow: the
app bar (hide-on-scroll), the list's collapsing header, the corner buttons and to-top,
the article toolbar, its mini nameplate and corner actions, the progress rail, the
audio player bar. The shell drops `height: 100dvh` and `overflow: hidden` on narrow so
the page can grow; it keeps them on wide.

A `fixed` element is trapped by any ancestor with a transform, filter or containment.
`.main` already avoids being one (#100). The article frame's swipe transform **is** one,
so the article's fixed chrome must sit outside the frame that moves (or move with it on
purpose, as the toolbar does today). Each new `fixed` element is checked for this.

Bottom chrome anchored with `fixed` follows the visible viewport, so it moves with the
browser toolbar as it collapses — intended.

### 4. Gestures and browser behaviour

- **Overscroll:** narrow sets `html { overscroll-behavior-y: none }`. Brave/Chrome on
  Android otherwise run their own pull-to-reload (a full app reload) and iOS rubber-bands
  the page edge; either would steal the list's pull-to-refresh and the article's
  end-pull back. Both gestures keep their trigger ("touch starts at top / at end"),
  read through the `ScrollHost`.
- **History:** `history.scrollRestoration = 'manual'`, so back/forward and reload never
  restore a stale document offset over `ListScrollMemory`/`ArticleScrollRestore`.
- **CDK dialogs:** their default `block` strategy pins `<html>` at the current offset and
  restores it on close. With page scroll that becomes real behaviour (no scrolling under
  a dialog) — wanted — and must coexist with a pinned list. Checked with an open article
  plus an action sheet and a dialog. Toasts already use `noop`.
- **Sidebar drawer** on narrow locks the page while open, the same way.

### 5. The green progress rail

`ScrollProgressRail` keeps its contract and its all-entries estimate; it reads through
the `ScrollHost`. On narrow it stays the right-edge rail that stands in for the
scrollbar, with the page's scrollbar hidden.

**Fallback:** if iOS WebKit will not hide its own page scroll indicator, the rail on a
`PageScrollHost` switches to its existing `horizontal` mode — the bottom hairline the
wide layout uses — so it never sits on top of the native indicator. Decided by the
iPhone live check, not up front.

## Risks

1. iOS may show its own page scroll indicator regardless → rail fallback above.
2. The offset restore on back must land before paint, or the list flashes at the top.
3. Rotation/resize across the breakpoint with an article open must carry both offsets.
4. Reading focus/sections measure against the scroller's top; on the page that is the
   viewport top, partly covered by fixed chrome — the existing reserved-height
   variables (`--reader-top`, `$header-clear`) must feed the measurement.
5. `fixed` chrome trapped by a transformed ancestor (§3).

## Delivery

A series of PRs off `develop`, each shippable:

1. **Seam** — `ScrollHost` + `ElementScrollHost`; every consumer moved; no behaviour
   change.
2. **List on the page** — `PageScrollHost`; the narrow list scrolls the document; shell
   and list chrome fixed; overscroll, history, drawer lock. Articles still inner-scroll
   on narrow.
3. **Article handoff** — `PageScrollOwner`; article and link reader on the page;
   swipe-back, end-pull, leave animation; CDK checks.
4. **Rail and polish** — iOS indicator decision and rail fallback if needed; any
   leftovers from the live check.

## Testing

- **Jest:** both `ScrollHost` implementations; `PageScrollOwner` pin/unpin/offset
  handoff; existing consumer specs moved onto a fake host (phase 1 must keep them
  green unchanged in meaning).
- **Playwright, phone viewport:** scrolling the list moves `window.scrollY`; opening an
  article starts at 0; back returns to the list's exact offset; the rail fills;
  pull-to-refresh fires. Data via stubbed routes (an e2e spec owns its data).
- **Playwright, wide viewport:** split pane unchanged, `window.scrollY` stays 0.
- **Live check in Brave on the iPhone** (the point of the issue): bar collapses in list
  and article, back lands exactly, swipe-back and end-pull work, rail vs. native
  indicator. Docker's `:4200` serves the main checkout, not this worktree, so the
  worktree build is served separately for it.
