# Reader sidebar — mobile redesign

Design spec for [#185](https://github.com/larspohlmann/simple-feed-reader/issues/185).
Agreed through a visual brainstorming round on 2026-08-01. This document is the
contract the implementation plan is written from; it fixes *what* and *why*, not
the task order.

## 1. Problem

The reader sidebar (`frontend/src/app/reader/sidebar/`) is one component that
serves both layouts. Above 720px it is an in-flow 260px column; at or below 720px
the shell turns it into a fixed swipe-in drawer. On a phone it has six problems,
recorded in the issue:

1. Touch targets are ~32–34px (`--row-pad-y: 8px`), under the 44px minimum.
2. A tag row crowds three targets — expand chevron, navigation link, `⋯` menu —
   into one small row.
3. The `⋯` menu (`.pop`, 140px min) is absolutely positioned to the right of a
   row inside an 84%-wide drawer, so it can spill off-screen.
4. Drag-and-drop retag/reorder uses a 180ms touch long-press that competes with
   the drawer swipe-to-close and the list scroll, and needs a precise drop.
5. The column is long; the view controls at the bottom are only reachable by
   scrolling past every feed.
6. Two breakpoint sources: `LayoutService.NARROW_QUERY` (`max-width: 720px`) for
   the swipe behaviour and `bp.$bp-md` (720px) for the drawer layout. They agree
   by coincidence, not by construction.

## 2. Shape of the solution

Keep **one adaptive component**, not two. The redesign separates two independent
axes that were previously entangled:

- **Presentation is a viewport-width decision.** Narrow → the drawer; wide → the
  in-flow column. Unchanged in spirit; the *source* of the boundary is unified
  (§6).
- **Density and organisation are a pointer decision.** Coarse pointer → 44px
  targets and an explicit *Organise* mode. Fine pointer → today's compact rows
  and today's always-on inline drag.

A tablet with a coarse pointer in the wide column layout therefore gets 44px
rows and the Organise toggle while staying a column — which is correct.

The mobile drawer becomes **navigation-first**: a read-only list by default, with
destructive and structural editing moved behind an explicit Organise toggle.

## 3. Navigation mode (the default)

The drawer top-to-bottom:

- **Actions row** — Refresh, Add feed (unchanged).
- **Global views** — All items, Favorites, Kept. Each is one 44px row, one tap
  target.
- **Tags** — each tag is one row built on the **Model 2** anatomy:
  - The **name area** (glyph + name + count) is a navigation target: it selects
    the tag, exactly as today.
  - A dedicated **44px trailing chevron zone** toggles the tag's feed list open
    or closed. It is a separate, full-height target, well separated from the name
    area, so a thumb never lands between them. This is the honest answer to
    problem 2: two large targets, not three cramped ones.
  - Expanded, the tag's feeds nest beneath it; each feed row is a 44px navigation
    target.
- **Feeds** — the untagged bucket, one 44px row per feed.
- **Foot (grouped meta controls, in this order):**
  1. **Organise** toggle.
  2. Layout + theme selectors (`<app-view-controls>`).
  3. Version link.

The foot rides `margin-top: auto` as today, so it sits at the bottom and flows
off only when the list is long — the view-controls placement is deliberately
left "at the bottom, in flow" (problem 5 accepted as-is; the meta grouping is the
improvement).

No `⋯` menu appears on any row in Navigation mode. The mode is read-only.

## 4. Organise mode

Toggling **Organise** on (foot of the drawer) switches the drawer to a structural
editing surface:

- **Hidden while organising:** the Actions row (Refresh, Add feed), the global
  views (All items / Favorites / Kept), **and** the layout/theme selectors. What
  remains is only the organisable structure — the tag list and the untagged Feeds
  list — plus the Organise toggle (to switch back) and the version line at the
  foot.
- **Each tag and feed row gains:**
  - A leading **≡ drag handle**. Drag reorders a tag among tags, or a feed within
    its list. Dragging a feed's handle onto a tag header retags it; dragging it to
    the untagged **Feeds** bucket clears its tags. This is the existing
    `cdkDropList` machinery, re-triggered from an explicit handle instead of a
    long-press.
  - A trailing **⋯ menu** button that opens a bottom action sheet (§5).
- **Expanding a tag has no chevron.** Because navigation is disabled in Organise
  mode, the whole tag **row body** (between the handle and the `⋯`) is the
  expand/collapse target. Tapping it opens the tag; its feeds nest beneath with
  their own handles and menus.
- **Drag no longer competes (problem 4):** drag starts from the explicit handle,
  not a 180ms long-press, and the drawer's **swipe-to-close is disabled while
  Organise is on**. A drag can never be misread as a close gesture or a scroll.

Organise is a coarse-pointer-only affordance. On a fine pointer the toggle is not
rendered and the desktop keeps its current always-on inline drag. Organise state
is local to the drawer and resets when the drawer closes.

## 5. Row menu — bottom action sheet

The `⋯` button opens a **bottom action sheet**: a panel that rises from the
bottom of the *viewport* (not anchored to the row), titled with the row's name,
listing the row's actions (Edit / Delete tag; Edit / Unsubscribe feed). It is
dismissed by a backdrop tap or a downward swipe.

This replaces `.pop`, the right-anchored 140px popover that spills off the drawer
edge (problem 3). A viewport-anchored sheet can never clip, sits under the thumb,
and is a pattern a future native iOS client uses natively too.

The sheet is a small shared component (`shared/`), reusable by any future
row/long-press menu, and is documented in the design language (§8). It is **not**
`<app-overlay-panel>` — that is a modal dialog (`role="dialog"
aria-modal="true"`); this is a non-modal action menu (`role="menu"`).

## 6. One breakpoint source (problem 6)

Make **`LayoutService.isNarrow()` the single authority** for the drawer boundary:

- The shell binds a class from `screen.isNarrow()` (e.g. `.body.is-narrow`).
- The drawer/backdrop rules in `reader-shell.component.scss` key off that class
  instead of declaring their own `@media (width <= bp.$bp-md)` block. That media
  block is removed.
- The 720px value is then declared **once**, in `NARROW_QUERY`. Nothing else
  hardcodes the drawer boundary.

This respects the design-language rule that `@media` cannot read custom
properties: we do not try to share a value *into* a media query — we remove the
media query for this boundary and drive the layout from the observed class, so
there is exactly one declaration of "narrow". `bp.$bp-lg` (the article overlay's
wide switch) is untouched.

## 7. Density (coarse pointers only)

Enlarge targets to 44px **only** under `@media (pointer: coarse)`, scoped inside
`sidebar.component.scss`. The desktop keeps today's compact rows.

Do **not** change the global `--row-pad-*` compact tokens — they are shared by the
discover rails and the admin catalog (design-language §1). The sidebar raises its
own row min-height to `--tap-target` and enlarges the chevron zone, the `⋯`
button and the drag handle to 44px hit areas locally, under the coarse-pointer
query.

## 8. Files and components

| File | Change |
|---|---|
| `reader/sidebar/sidebar.component.{ts,html,scss}` | Model-2 tag rows; `organising` signal + Organise toggle (coarse only); Navigation vs Organise templates; foot grouping; coarse-pointer density; `⋯` opens the action sheet instead of `.pop` |
| `reader/reader-shell.component.{ts,html,scss}` | Bind `is-narrow` class from `screen.isNarrow()`; replace the `@media (width <= bp.$bp-md)` drawer/backdrop block with class-keyed rules; disable drawer swipe while Organise is on |
| `reader/layout.service.ts` | `NARROW_QUERY` becomes the single source of the drawer boundary (documented as such) |
| `reader/drawer-swipe.directive.ts` | Honour an "organising" disable input (swipe-to-close paused in Organise mode) |
| `shared/action-sheet/` (new) | Bottom action-sheet component for row menus |
| `frontend/src/app/theme/` | Any new density/touch token if one proves needed (prefer existing `--tap-target`) |
| `docs/design-language.md` | Record the action-sheet component, the coarse-pointer density pattern for the sidebar, and the class-driven breakpoint as a recorded exception/pattern |

## 9. Testing

- **Sidebar unit tests** — Navigation vs Organise rendering; Organise hides
  Actions/global views/view-controls; tag row body toggles expand in Organise and
  the chevron zone toggles expand in Navigation; `⋯` opens the action sheet;
  toggle is absent on a fine pointer. Follow the repo rule: tests must fail under
  mutation, not merely pass.
- **Shell unit tests** — `is-narrow` class tracks `isNarrow()`; swipe disabled
  while Organise is on.
- **Action-sheet unit tests** — opens titled with the row, backdrop/swipe
  dismiss, actions fire.
- **Playwright mobile smoke** — a mobile viewport: open the drawer, expand a tag,
  enter Organise, confirm the global rows and view-controls disappear, open a row
  action sheet and confirm it is fully within the viewport. Outside the CI gate,
  like the other smokes.

## 10. Quality bars (from the issue)

- 44px targets on coarse pointers.
- No overlay leaves the viewport (the action sheet is viewport-anchored).
- One breakpoint source (§6).
- Tokens only: no hex, no raw px outside `src/app/theme/`.
- `npm run check` green.
- A Playwright mobile smoke.
- `docs/design-language.md` updated.

## 11. Out of scope / notes

- The header's existing mobile tag-chip row (`reader-header`) stays. It overlaps
  the drawer's tag navigation but is a separate, already-shipped affordance; the
  issue does not ask to remove it. Flag for a later decision, do not touch here.
- The wide-screen column keeps today's appearance and today's always-on inline
  drag; only coarse-pointer behaviour changes there.
- No new refresh/scheduler behaviour; unrelated to this issue.
