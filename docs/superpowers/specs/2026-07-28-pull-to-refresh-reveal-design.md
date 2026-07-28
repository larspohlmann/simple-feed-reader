# Pull-to-Refresh Reveal — Design

**Date:** 2026-07-28
**Issue:** [#158](https://github.com/larspohlmann/simple-feed-reader/issues/158)
**Branch:** `feature/158-pull-refresh-reveal`

## Problem

The mobile pull-to-refresh gesture moves only a small floating spinner **chip** —
the list content itself never moves, and on release the chip vanishes instantly
while the refresh actually runs. There is nothing held open to "snap back", so
the gesture reads as a flick rather than a pull. On desktop there is no reveal at
all; the sole feedback is the top-right Refresh button's spinning icon.

## Goal

Refreshing opens a gap at the top of the list that shows a spinner and a
"Refreshing…" label, **holds** while the refresh runs, and **snaps shut** when it
finishes. On mobile the gap is opened by the pull gesture (content follows the
finger); everywhere it is also opened by *any* refresh the user starts by
button — the list header's top-right Refresh **and** the sidebar's Refresh. The
reveal keys off the shared refresh state, not off a particular control, so both
buttons light it up on the currently-viewed list. One reveal mechanism, every
trigger.

## Non-goals

- Enabling the pull gesture on desktop. Pull stays mobile-only; desktop opens the
  reveal from the Refresh button alone.
- A multi-phase pull label ("pull to refresh" / "release to refresh"). The
  existing armed/accent colour already signals the release point; the text label
  appears only once the refresh is actually running.
- Changing the refresh threshold, the rubber-band curve, or any gesture math in
  `reader-gestures.ts` — that pure logic is untouched.
- Reveal motion under `prefers-reduced-motion`. Mobile pull is already suppressed
  there; the desktop reveal is suppressed the same way.

## Decisions

| Question | Decision |
| --- | --- |
| What physically moves | The `#rows` scroller **and** the reveal tray, both by `translateY(revealOffset)` |
| Offset while dragging (mobile) | `rubberBand(pulled)` — the existing helper — with the CSS transition **off** |
| Offset while refreshing | A fixed `REFRESH_REVEAL` height, transition **on** (holds open) |
| Offset otherwise | `0`, transition **on** — this single case is both the mobile snap-back and the desktop close |
| What opens the reveal | Any path that sets `refreshing()`: an armed pull release, the list header's Refresh (`onScopedRefresh`), or the sidebar's Refresh (`onRefresh`) — all call `RefreshService.run()` |
| No flicker bridge | Not needed — `refreshing()` is already true synchronously on release (see Mechanism) |
| Where the "Refreshing…" label shows | In the tray, only while `refreshing()`; hidden during the pre-release drag |
| Reduced motion | No reveal on either platform; the Refresh button state stays the signal |

## Mechanism

A single `revealOffset` computed signal, applied as a GPU-friendly transform to
the `#rows` scroller and to the reveal tray. It is orthogonal to the existing
`scrollTop`-based scroll-restore and settle logic — those set `scrollTop`, this
sets `transform`, so they do not fight.

```
revealOffset =
  dragging()      ? rubberBand(pulled())   // 1:1 with the finger, transition off
  : refreshing()  ? REFRESH_REVEAL         // held open, transition on
  :                 0                       // snap-back / desktop close, transition on
```

**Why no anti-flicker bridge is needed.** On an armed release, `onPullEnd` calls
`refresh.emit()`. The shell binds that output to `onScopedRefresh()`, which calls
`RefreshService.run()`, and `run()` sets `running.set(true)` **synchronously**
(`refresh.service.ts:36`) before returning. The shell passes
`[refreshing]="refreshSvc.running()"` — a direct signal read, no async wrapper
(`reader-shell.component.html:94,132`). So the emit, the state flip, and the
input update all happen inside the one touchend call stack; by the time change
detection paints, `dragging()` is false and `refreshing()` is already true, so
`revealOffset` goes straight from the pull value to `REFRESH_REVEAL` with no
intermediate 0 frame. A below-threshold release emits nothing, `refreshing()`
stays false, and the offset animates to 0 — the snap-back.

The CSS `transition` on the transform produces every animated case: the mobile
snap-back (offset → 0), the desktop slide-down (0 → `REFRESH_REVEAL` when the
button starts a refresh), and the desktop close. A `.dragging` class removes the
transition during the active pull so the content tracks the finger exactly.

Both the scroller and the tray translate by the same offset, so the tray rides
just above the first row and emerges from behind the floating bars as the content
slides — the standard pull-to-refresh look, and the same "emerge from behind the
bar" park trick the current chip already uses.

### State transitions

- **touchmove past the top** (mobile, `pullEnabled()`): `dragging` on, offset
  tracks the rubber-banded finger travel, no transition.
- **release, armed**: `dragging` off, `refresh.emit()`. `refreshing()` is now true
  (above), so the offset animates from the pull value to `REFRESH_REVEAL` and
  holds.
- **release, not armed**: `dragging` off, offset animates to 0 (snap-back).
- **`refreshing()` true→false**: offset animates to 0. Same close on both
  platforms.
- **a Refresh button** (list header *or* sidebar, any layout): no gesture;
  `refreshing()` true → offset animates open, false → animates shut. Because the
  reveal reads `refreshing()`, it is agnostic to which button ran — the sidebar's
  global refresh and the header's scoped refresh both open it on the visible list.

## Component & template

All changes live in
[`frontend/src/app/reader/entry-list/`](../../../frontend/src/app/reader/entry-list/).

- **`entry-list.component.ts`** — add a `dragging` signal and a `revealOffset`
  computed as above; keep `pulled` (finger travel) and drop the separate `pull`
  signal, since the offset is now computed rather than stored. `onPullStart` /
  `onPullMove` set `dragging`; `onPullEnd` clears `dragging` and, when armed,
  emits `refresh` (no more manual zeroing — the computed handles it).
  `REFRESH_REVEAL` is a module constant beside `MAX_PULL`, and an `effect`
  publishes it as `--refresh-reveal` on the host (mirroring the existing
  `--list-bar-h` `_publishBarHeight` effect) so the stylesheet sizes the tray
  from the same number.
- **`entry-list.component.html`** — the `#rows` scroller (list and magazine
  branches, which share the ref) carries `[style.transform]` and
  `[class.dragging]`. The current `@if (pull() > 0)` chip becomes a **reveal
  tray** rendered whenever `revealOffset() > 0`, also translated by the offset:
  the existing `<app-spinner>` plus an `@if (refreshing())` `<span>` bound to
  `reader.refreshing`. The skeleton/empty branches carry neither — you do not
  refresh during the initial load.
- **`entry-list.component.scss`** — the tray becomes a **pill** (not the 44px
  circle) so the label fits: `min-height: var(--refresh-reveal)`, token padding,
  `var(--surface-1)` / `var(--border)`, `var(--text-secondary)` (accent when
  armed, unchanged). The scroller and tray get
  `transition: transform 0.2s ease` (the same literal the sibling `.list-header`
  transition already uses — Stylelint bans hex, px-spacing and media literals,
  not time values), removed under `.dragging` and inside the existing
  `prefers-reduced-motion` block. No hex, no raw px.

## i18n

One new key `reader.refreshing` ("Refreshing…" / "Aktualisiere…") in both
`frontend/public/i18n/en.json` and `de.json`, beside the existing
`reader.refresh`.

## Testing

- **`reader-gestures.spec.ts`** — unchanged and still green; the pure gesture math
  did not move.
- **`entry-list.component.spec.ts`** — new cases:
  - `revealOffset` holds at `REFRESH_REVEAL` while `refreshing()` is true and
    returns to 0 after it flips false (the snap-back / desktop close);
  - on wide layout with no pull, `refreshing()` true opens the reveal and the
    `reader.refreshing` label renders (desktop slide);
  - an armed release emits `refresh` (the below-threshold gesture emits nothing),
    covering the two release branches.
- **`npm run check`** — ESLint + Prettier + Stylelint + Jest, the CI gate,
  including `color-no-hex` and the spacing/media-query literal rules.

## Accepted risks

- The scroller transform shifts the bottom of the list off-screen by
  `REFRESH_REVEAL` while open (~one tray height). It is a scroller, the offset is
  small, and it exists only during the refresh; acceptable.
- Under `prefers-reduced-motion` there is no reveal on either platform. This is
  intentional parity with the already-suppressed mobile pull; the Refresh button
  remains the accessible signal.
