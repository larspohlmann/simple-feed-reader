# #2088 Keep the collapsed address bar collapsed across the list/article switch

**Goal:** opening a full-screen article, or going back to the list, leaves a collapsed
mobile address bar collapsed (Safari and Brave on iOS).

## Causes

- **Safari** shows its bar when the page is written to offset 0. `PageScrollOwner.enter`
  hands the entering layer offset 0.
- **Brave** (`ToolbarVisibilityViewModel.swift` in brave-core) never reacts to a script's
  offset write. It expands the bar when
  1. the content size changes to one not taller than `view.bounds.height - safeAreaInsets.top`,
     i.e. about the screen: an opening article is `min-height: 100dvh`, shorter than that;
  2. a drag ends with `contentOffset.y + contentInset.top < transitionDistance` (the toolbar's
     height, ~44–60 pt) — a swipe-back right after opening an article starts at 0.

## Design

A layer that enters over another one **leads**: its content starts `PAGE_LEAD_REM` (6rem,
about two toolbar heights) below its top, behind an opaque spacer. The page opens it at
that lead, never at 0.

- `PageLayer.lead` (px) and `PageLayer.leadBy(px)`: set by `PageScrollOwner.enter` on every
  layer that enters over another; the layer puts `--page-lead` on its element for the
  spacer. The list (the first layer) leads by 0.
- `PageScrollOwner.enter` hands off `{ from, to: layer.lead }` instead of `to: 0`.
- `PageScrollHost` with a layer reports in the layer's own coordinates: `scrollTop`,
  `scrollHeight`, `scrollTo` and the pinned offset are shifted by the lead, so every consumer
  (reset, to-top, rail, reading focus, scroll restore) keeps treating 0 as the article's
  start. Writes it announces stay raw page offsets, which is what `PageOffsetHold` compares.
- Article layer on the page: `.reader` pads `--page-lead` above its content, and its
  min-height becomes `calc(100vh + var(--page-lead) + 8rem)`, taller than Brave's
  threshold even while the article loads.

## Tasks

1. `PageLayer.lead` / `leadBy` with spec.
2. `PageScrollOwner.enter` leads the entering layer and hands off to the lead; specs updated.
3. `PageScrollHost` shifts by the layer's lead; spec cases for read, write, scrollTo, height,
   pinned, and the raw announce.
4. Article layer SCSS: spacer + min-height.
5. e2e specs that expect an opened article at `scrollY` 0 move to the lead.
6. `npm run check` in the frontend container; Playwright phone smokes for the article
   handoff; simulator Safari check; Lars checks Brave on the iPhone against `:4200`.
