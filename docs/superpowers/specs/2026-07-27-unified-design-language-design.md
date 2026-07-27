# Unified design language

Issue: [#126](https://github.com/larspohlmann/simple-feed-reader/issues/126)

## Problem

The app's visual conventions exist, but only implicitly — encoded in whichever
component happened to establish them first. A new feature area is consistent
only if its author remembers to read a sibling component and copy it. Building
the onboarding feed catalog (#99) showed what happens when nobody does: three
different treatments for the same category icon, a cramped rail next to roomy
lists, a rail that scrolled away where it should have pinned.

Two of the specific complaints in #126 have since been fixed by the #127
consistency pass: `admin-catalog` now uses the shared icon picker rather than
raw glyph-name entry, and `category-rail` already renders a tinted glyph and
stays pinned. What remains is the underlying cause. The measured state of
`frontend/src/app` today:

| Symptom | Measured |
|---|---|
| Ad-hoc `px` literals | 296 across 42 SCSS files |
| Breakpoints | 7 distinct values (560/720/800/820/899/900/960) in 2 syntaxes |
| Tinted-glyph-or-dot re-implementations | 5 (`sidebar`, `source-tags`, `add-feed-dialog`, `category-rail`, `category-chips`) |
| Icon-picker implementations | 2 (`shared/icon-picker`, and an inline grid in `tag-form-dialog`) |
| Dialogs declaring their own frame CSS | 4, none responsive, none sharing a `panelClass` |
| `shared/button` adoption | `auth/` only; every other surface hand-rolls button CSS |

## Goal

Make the conventions explicit, shared, and mechanically enforced, so a new
feature area is consistent by construction. Success means: a component author
cannot write an ad-hoc spacing value without failing `npm run check`, and cannot
render a category icon inconsistently without deliberately avoiding the shared
component.

## Scope

One full sweep across all six areas of #126, in a single branch. This was a
deliberate choice over decomposing into sequenced sub-projects; the trade-off is
a large diff with real regression surface, mitigated by the staged sequencing
and manual verification pass described below.

Out of scope: any change to what the app *does*. Onboarding stays a `/discover`
route with its guard rather than becoming a `Dialog.open` call. No new themes.
No backend change.

---

## 1. Token layer

Tokens live where they already do — `frontend/src/app/theme/tokens.scss` for
mode-invariant values, `theme/themes/_graphite.scss` for colour roles. The scale
below is derived from the histogram of values actually in use, not invented.

### Spacing

Keep `--space-1..6` (4/8/12/16/24/32). Add:

- `--space-0: 2px` — 19 uses today. A real half-step for tight gaps (rail row
  gaps, icon-grid padding), not a stray.
- `--space-7: 48px` — for the section rhythm that currently reaches for `32px`
  twice or an ad-hoc `44px`.

The off-scale strays — 3, 6, 7, 9, 10, 20px, roughly 25 sites — **snap to the
nearest step**. This moves those surfaces by 1–3px. That is intended: keeping
them would re-encode today's drift as tokens.

### Radii

- `--radius: 8px` (exists)
- `--radius-sm: 4px` — for nested/inner elements currently reusing the 8px
- `--radius-pill: 999px` — 6 hand-written uses today

### Structural sizing

- `--control-h: 40px` (exists)
- `--bar-h: 56px` — **the fallback source, not a replacement.** `--app-bar-h` is
  measured at runtime by `ReaderShellComponent` and written to the host; the
  literal `56px` appears 13 times as the pre-measurement fallback in
  `var(--app-bar-h, 56px)`. Those become `var(--app-bar-h, var(--bar-h))`. Two
  further uses are genuine static heights (`reader-header`, `settings`) and take
  `var(--bar-h)` directly.
- `--tap-target: 44px` — the documented minimum touch target. Applies to
  `to-top-button` today. Note the other three `44px` occurrences
  (`entry-list`'s pull-to-refresh chip offset, `reader-header`'s secondary bar
  offset) are *positioning offsets that happen to share the number*; they are
  not tap targets and must not be rewritten to this token.

### Icon sizes

`--icon-sm: 16px`, `--icon-md: 20px`, `--icon-lg: 24px`. Today 18/20/22/26px are
used interchangeably across surfaces. `IconComponent`'s `size` input changes from
a free `number` to `'sm' | 'md' | 'lg'`, defaulting to `md`, so an off-scale icon
size becomes a type error rather than a judgement call.

### Type

`--fs-sm/base/lg/xl` exist and are used consistently. Add:

- `--fs-xs: 11px` — counts, badges, metadata
- `--lh-tight: 1.25`, `--lh-normal: 1.5` — line-heights are currently ad hoc or
  inherited

### Breakpoints

Custom properties **do not work inside `@media`**, so breakpoints cannot be CSS
variables. A new SCSS partial `theme/_breakpoints.scss` exports:

```scss
$bp-sm: 560px;  // phone
$bp-md: 720px;  // small tablet / large phone landscape
$bp-lg: 900px;  // desktop layout switch
```

Components `@use '…/theme/breakpoints' as bp;` and write
`@media (width <= bp.$bp-md)`. The seven current values collapse onto these
three and the two syntaxes unify on the range syntax.

**Known consequence:** the two `800px` queries (`category-rail`,
`discover`) and the `820px`/`899px`/`960px` ones move by up to 80px. Layout
switch points will differ. This is a visible change and is called out for the
manual verification pass.

---

## 2. Enforcement

Stylelint rules in `.stylelintrc`, blocking `npm run check` exactly as
`color-no-hex` already does. Boundary is the same one `color-no-hex` draws:
`src/app/theme/` and `src/styles/` are exempt — that is where literals belong.

1. **No `px` for spacing and sizing.** A
   `declaration-property-unit-allowed-list` rule covering `padding*`, `margin*`,
   `gap`, `row-gap`, `column-gap`, `font-size`, `border-radius`, `width`,
   `height`, `min-*`, `max-*`.
2. **Hairlines stay literal.** `border-width` and `outline-*` are carved out.
   The 88 `1px` borders remain as they are — tokenising a hairline buys nothing
   and would make every border declaration longer for no gain.
3. **No raw `px` in media queries.** Enforced so components must `@use` the
   breakpoint partial.

Escape hatch: a `/* stylelint-disable-next-line */` with a comment saying why,
matching the house rule already applied to `@phpstan-ignore` on the backend.
Genuinely structural one-offs (the 220px rail width, the 1040px discover panel)
use this rather than being forced into the spacing scale.

---

## 3. Shared components

All live in `frontend/src/app/shared/`. All standalone, `OnPush`, signal inputs.

### `<app-tag-glyph>`

The canonical way to render a tag or catalog category. Inputs: `name`
(`string | null`), `color` (`string | null`), `size` (icon size union).
Renders the Material Symbol tinted with `color`; falls back to a colour dot when
`name` is absent; falls back to `--text-muted` when `color` is absent.

Replaces five re-implementations. Two of them — `category-rail` and
`category-chips` — have **no dot fallback at all** today, so a category with a
colour but no icon renders an empty box. That defect disappears by construction
rather than being separately fixed.

### `<app-field>`

Content-projection wrapper, not a `ControlValueAccessor`:

```html
<app-field label="Name" [error]="nameError()">
  <input formControlName="name" />
</app-field>
```

Owns the label, the required marker, the hint slot, the error slot and the
vertical rhythm. The native control stays in the consumer's template with its own
`formControlName`, so `type`, `autocomplete`, `inputmode` and friends need no
re-exposure. Global CSS in `src/styles/` styles the projected
`input`/`select`/`textarea` — replacing the current `.field` global class, which
is auth-only.

Consumers: the five auth forms, `admin-catalog`'s 18 raw controls, the three
reader dialogs, `settings`.

### `<app-color-field>`

Swatch row + native colour input + clear button. Currently inline in
`tag-form-dialog`; `admin-catalog` has its own separate colour field. Both adopt
this.

### `icon-picker` (existing, adopted)

`shared/icon-picker` already exists and `admin-catalog` already uses it.
`tag-form-dialog` still hand-rolls its own icon grid (`.icons` / `.icon`, ~30
lines of SCSS). It adopts the shared component; the second implementation is
deleted.

### `<app-overlay-panel>`

Title / body / footer projection. Owns the responsive frame:

- Desktop: centred card, `width: min(var(--panel-w), 92vw)`, where the consumer
  sets `--panel-w` on the panel (460px for the reader dialogs, 1040px for
  discover) — the one dimension that legitimately varies per consumer
- Mobile (`<= $bp-sm`): full screen, `--panel-w` ignored
- `max-height: 90dvh` with `vh` fallback, body scrolls, `overscroll-behavior: contain`

A global `.app-dialog` class passed as CDK Dialog's `panelClass` carries the
sizing. Consumers: the four reader dialogs (`add-feed`, `edit-subscription`,
`tag-form`, `confirm`) and `discover`, which stops hand-rolling its own
scrim + panel while keeping its route.

### `shared/button` (existing, extended and adopted)

Today: `variant: 'default' | 'primary'`, decorator `@Input()`s, used only in
`auth/`. Changes:

- Convert to signal `input()`s, matching the rest of the codebase
- Add `danger` and `ghost` variants, and a `size: 'sm' | 'md'`
- Adopt across `sidebar`, `admin`, the dialogs, `view-controls`,
  `settings`

---

## 4. Density and sticky conventions

These two areas are documentation plus a handful of fixes, not new components.

**Density.** Two tokens — `--row-pad-y: var(--space-2)` and
`--row-pad-x: var(--space-3)` — plus the documented rule that every list, rail
and picker row derives its padding from them. This is what closes the "picker
rail reads as cramped next to the reader's lists" gap: both then come from one
place, so they cannot drift apart again without editing the token.

**Sticky and scroll.** No new code. A documented rule recording what
`category-rail` learned the hard way, and which its own comment already states:

- Stickiness lives on the **flex-child host**, never on an inner wrapper — a
  sticky element inside a content-height host has no room to stick and scrolls
  away with it.
- Internal scrollers get `overscroll-behavior: contain`.
- Content beneath the floating bars offsets by the measured bar height plus
  `--bar-gap`, never a literal.

Existing sticky surfaces are audited against this and corrected where they
differ.

---

## 5. Documentation

`docs/design-language.md`, structured as:

1. Token tables (the sections above, as reference tables)
2. Component catalog — each shared component, its API, and when to use it
3. Conventions — density, sticky/scroll, overlay, form layout
4. "Adding a new surface" checklist

`CLAUDE.md`'s frontend conventions section gets a short pointer to it, alongside
the existing `color-no-hex` rule. Two stale lines in that section are corrected
while there: component styles are no longer inline in the `.ts` (commit
`496d06d` extracted them into `.scss` files).

---

## 6. Sequencing

Each step is independently reviewable and leaves the tree green except where
noted.

1. **Tokens + breakpoint partial.** Additive only, no consumer changes, no
   visual change.
2. **Stylelint rules, landing red.** The rules go in; `npm run check` fails.
   This step is not committed alone — it opens step 3.
3. **Migration, area by area:** `theme`/`styles` → `shared` → `auth` →
   `settings` → `admin` → `discover` → `reader`. `reader` is last and largest
   (`entry-list` 31, `sidebar` 24, `reader-view` 18, `reader-header` 18). Green
   again at the end of this step.
4. **Shared components,** each landing with its consumers converted:
   `tag-glyph` → `field` + `color-field` → `overlay-panel` → `button`.
   `tag-form-dialog` drops its inline icon grid when `icon-picker` is adopted.
5. **Documentation** and the CLAUDE.md pointer.

---

## 7. Testing and risk

**What is mechanically verifiable:**

- Stylelint proves no ad-hoc `px` survives outside the exempt directories — this
  is the guarantee that the language holds going forward.
- Jest unit tests for each shared component's contract: `tag-glyph` renders a
  dot when `name` is absent and a tinted glyph when present; `field` associates
  its label with the projected control and exposes the error to assistive tech;
  `overlay-panel` projects its three slots and traps focus; `button` renders
  each variant and blocks clicks while loading.
- Existing specs for the five converted glyph sites are updated rather than
  deleted, so the conversion is proven not to change rendered output.

**What is not.** Jest cannot catch a visual regression, and this sweep produces
three categories of intentional visual change: ~25 spacing values snapping by
1–3px, layout switch points moving by up to 80px as seven breakpoints collapse
to three, and icon sizes normalising from 18/22/26px onto 16/20/24px. Green CI
therefore does **not** mean the sweep is safe.

The compensating control is a manual pass over `reader` (list, magazine and
article views), `discover`, `settings`, `admin` and the auth forms, at each of
the three breakpoints, in both light and dark mode. This is a required step, not
a nicety — it is the only thing standing between this change and a shipped
visual regression.

**Residual risk, stated plainly:** a 42-file CSS sweep in one branch is the
scope decision most likely to be regretted. It was chosen knowingly. If review
of the reader step in sequence 3 proves unmanageable, the fallback is to split
sequence 4 (shared components) into its own follow-up PR, leaving the tokens and
migration as a self-contained change.
