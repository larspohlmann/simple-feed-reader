# For You: a layout-independent recommendation strip (#331)

## Problem

PR #321 added a recommendation strip to the For You list: the reason the
recommender picked an entry, plus its 0-100 score when the user's debug setting
is on. The markup lives in `entry-row.component.html` (lines 18-25). Only the
**list** and **pane** reading layouts render entries through `app-entry-row`, so
only those two layouts show the strip.

The default reading layout is `magazine` — `ReadingLayoutService.readSaved()`
returns `magazine` when nothing is stored. The magazine layout renders entries
through its own card components (`entry-hero`, `entry-compact`, `entry-split`,
`entry-wide`, `entry-thumb`, `entry-quote`, `entry-kicker`, and the multi-entry
`source-group` widget). None of those reference `recommendationReason` or
`recommendationScore`.

Result: a user on the default layout sees neither the reason nor the score on
the For You list, whatever the debug setting says.

## Goal

Make a For You entry carry its recommendation strip in every layout, with the
strip markup living in exactly one place. No individual card template should
know about recommendations.

## Design

### 1. A single shared strip component

Add `RecommendationStripComponent` (`app-recommendation-strip`) under
`frontend/src/app/reader/recommendation-strip/`. It is a standalone, signals
component with a sibling `.scss` file (per house style — no inline styles).

- Input: `entry = input<EntryDto | null>(null)`. It is nullable so the magazine
  branch can wrap `group` blocks (which have no single entry) with the same
  wrapper.
- It projects the card through `<ng-content />`, then renders the strip **below**
  the projected card.
- The strip renders **only** when `entry()?.recommendationReason` is truthy, so
  the component is inert for a null entry and in every non-For-You view.
- The score `<span>` renders only when `recommendationScore` is neither `null`
  nor `undefined`.

The reason/score markup and the `.reason` / `.score` styles move here from
`entry-row`. This is the one and only home of the strip from now on.

Host layout: `:host { display: block; width: 100%; }`. `display: contents` is
wrong here — it would let `.rows.magazine`'s `gap` fall between a card and its
own strip and detach them. A block host keeps card and strip together as one
flex item, and the container's `gap` separates entries.

### 2. Both layouts wrap through the strip

In `entry-list.component.html`:

- **List / pane branch** — wrap the row:
  ```
  @for (e of entries(); track e.id) {
    <app-recommendation-strip [entry]="e">
      <app-entry-row [entry]="e" [tags]="..." [class.open]="..." (favorite)=... />
    </app-recommendation-strip>
  }
  ```
  The `[class.open]`, `[tags]`, and the four outputs stay on the inner
  `app-entry-row`. The wrapper only reads `entry`.

- **Magazine branch** — wrap the per-block `@switch` once. Because For You no
  longer groups (see §3), every For You block is a single entry. The `group`
  blocks that other views still produce carry no single entry, and there
  `recommendationReason` is `null` anyway, so the strip must stay inert. Add a
  helper `strippableEntry(block): EntryDto | null` that returns the entry for a
  single-entry block and `null` for a group block, and let the component input
  accept `EntryDto | null` (render nothing when null). The inner `@switch` keeps
  using the existing `entryOf` / `grp` helpers to build each card as it does
  today.

### 3. For You stops grouping

`entry-list.component.ts` computes `grouping: this.selection().kind !==
'subscription'`. Extend it so For You is also non-grouping:

```
grouping: kind !== 'subscription' && kind !== 'for-you'
```

Reasons:

- A ranked recommendation list must not collapse an 8+ same-source run into a
  "show more" widget — that hides recommendations and disrupts the score order.
- It guarantees every For You entry is its own card, so the strip always
  attaches. No strip ever needs to live inside `source-group`.

Grouping `false` keeps the magazine template variety (hero/wide/thumb/…); it
only disables the same-source run-collapse, exactly as subscription views
already behave.

### 4. entry-row loses the strip

Delete lines 18-25 from `entry-row.component.html` and the `.reason` / `.score`
rules from `entry-row.component.scss`. `entry-row` becomes
recommendation-agnostic.

## Data flow (unchanged)

The backend already gates correctly: `RecommendationFeedJson` always sends
`recommendationReason` and adds `recommendationScore` only when the user's
`debugEnabled` setting is on (`ForYouFeedResponder`). Angular's `HttpClient`
casts the JSON straight onto `EntryDto`, which already types both optional
fields. This change touches rendering only; no API or DTO change.

## Testing

- **`recommendation-strip.component.spec.ts`** (new): renders nothing without a
  reason; renders the reason when set; renders the score only when it is a
  number; hides the score when `null`/`undefined`.
- **`entry-list.component.spec.ts`**: For You entries render the strip in **both**
  the list layout and the magazine layout (the regression this fixes). Assert a
  For You selection with an 8+ same-source run produces **no** `group` block
  (grouping is off).
- **`entry-row.component.spec.ts`**: remove the inline reason/score assertions
  (the markup moved).
- Frontend gate: `npm run check` (ESLint + Prettier + Stylelint + Jest).

## Verification on a real render

CSS nesting is the one real risk: the card sits one level deeper now, so confirm
on a live render that magazine cards keep full width and the strip reads as a
tight caption below each card — in both light and dark, list and magazine. Do
not rely on unit tests alone for the visual.

## Out of scope

- No backend or DTO change.
- No change to how debug mode is set or stored.
- No change to non-For-You views' appearance (the strip stays inert there).
