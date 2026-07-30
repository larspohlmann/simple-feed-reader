# Sidebar Favicon Transparency

- **Status:** Approved
- **Date:** 2026-07-30
- **Issue:** <!-- GitHub issue number will be filled after creation -->

## Problem

Feed favicons in the sidebar are displayed at full opacity, making them visually compete with the feed titles. A slight transparency would make them recede and let the text hierarchy come forward.

## Design

Set `opacity: 0.3` on `<app-favicon>` elements inside the sidebar's `.nav` links. This is scoped to the sidebar only — favicons remain fully opaque in entry rows, magazine view, and reader view.

### Files changed

| File | Change |
|---|---|
| `frontend/src/app/reader/sidebar/sidebar.component.scss` | Add `opacity: 0.3` to existing `.nav app-favicon` block (line 74) |

## Selected value

30% opacity was chosen via visual comparison in the browser (options at 65%, 55%, 45%, 30% were presented; 30% was selected).

## Scope

- **Sidebar** — both tagged feed rows (line 169) and untagged feed rows (line 233) use `<app-favicon>` inside `.nav` anchors, so the CSS selector covers both.
- **Other locations** — entry rows, magazine source groups, kicker lines, and reader view are **not** affected.