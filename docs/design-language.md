# Design language

One vocabulary for the SPA: a token set, a small catalog of shared components, and
the conventions that keep new surfaces consistent with the ones already shipped.
Introduced by #126, which found the same list row implemented four different ways
and seven breakpoints where three were meant.

Everything here is enforced mechanically where it can be (Stylelint, via
`npm run check`) and written down where it cannot be. If a rule below looks
arbitrary, it is almost certainly recorded in [§6 Deliberate exceptions](#6-deliberate-exceptions)
or in a comment at the definition site — read that before changing it.

---

## 1. Tokens

All tokens are CSS custom properties declared in
`frontend/src/app/theme/tokens.scss`. Colour tokens are mode-dependent and come
from a theme mixin (`theme/themes/_graphite.scss`); everything below is
mode-invariant.

### Spacing

`--space-0` is a real half-step for tight gaps, not a stray. Anything off this
scale fails Stylelint outside `theme/` and `styles/`.

| Token | Value | For |
|---|---|---|
| `--space-0` | `2px` | hairline gaps — rail row gaps, icon-grid padding |
| `--space-1` | `4px` | label-to-control, icon-to-label |
| `--space-2` | `8px` | compact row padding-y, footer button gap |
| `--space-3` | `12px` | control padding-x, comfortable row padding-y |
| `--space-4` | `16px` | comfortable row padding-x, field bottom margin, `--bar-gap` |
| `--space-5` | `24px` | panel head/body/footer padding |
| `--space-6` | `32px` | section separation |
| `--space-7` | `48px` | page-level blocks |

### Radii

| Token | Value | For |
|---|---|---|
| `--radius-sm` | `4px` | an element nested inside an already-rounded container |
| `--radius` | `8px` | controls — buttons, inputs, selects, row hit areas |
| `--radius-lg` | `12px` | a large surface that carries content: card, dialog, panel |
| `--radius-pill` | `999px` | chips |

The `lg` step is not invented. The magazine hero, the magazine source group, the
discover panel and the auth card each arrived at 12px independently.

### Structural sizing

| Token | Value | For |
|---|---|---|
| `--control-h` | `40px` | the height of a button, input or select |
| `--bar-h` | `56px` | **fallback** for the floating app bar's height; also the wide list toolbar band's height (#1749) |
| `--tap-target` | `44px` | the documented minimum touch target |
| `--focus-ring` | `1px solid` accent at 60%; `none` on a coarse pointer | the `outline` of every `:focus-visible` rule (#1723) |
| `--list-bar-h` | `0px` | the entry list's own bar; overwritten on its host once measured |
| `--reader-bar-h` | `0px` | the article's toolbar; overwritten on the reader view's host once measured |
| `--bar-gap` | `var(--space-4)` | breathing room between a floating bar and the content under it |
| `--magazine-measure` | `680px` | the magazine column's content measure. A token rather than a literal in `entry-list` because `<app-feed-intro>` needs the same width, and it cannot inherit it: the list's `.rows.magazine > *` rule carries the list's encapsulation attribute, while the block is created by the reader shell |
| `--feed-logo-max-h` | `28px` | the ceiling for a feed's own logo in `<app-feed-intro>`; sized as a wordmark beside one line of text, not as a hero — a channel `<image>` is a brand logo on only about half the feeds that carry one, and the rest ship a favicon, a generic RSS glyph or an article photo |

**`--bar-h` is a fallback, not the height.** `ReaderShellComponent` measures the
real app bar at runtime and writes it to `--app-bar-h`. Every consumer must read
it as:

```scss
top: calc(var(--app-bar-h, var(--bar-h)) + var(--list-bar-h) + var(--bar-gap));
```

`--list-bar-h` is declared at `:root` with a `0px` default rather than written as
a `var()` fallback at each use site, so the `calc()` above reads as arithmetic.

### Sidebar geometry (#1749)

The grouped-card sidebar's own measures. They sit on `:root`, not in the sidebar's stylesheet, because the CDK drag preview leaves the sidebar for `<body>` and still reads them.

| Token | Value | For |
|---|---|---|
| `--sidebar-nav-w` | `316px` | the desktop card column; the column adds an 8px gutter each side (332px) |
| `--sidebar-drawer-nav-w` | `336px` | the phone sheet's card column; with its 12/12/12/10 padding and right border the sheet is 359px, inside a 390pt iPhone |
| `--sidebar-row-h` | `36px` | a row straight on a card: the smart views, a root tag |
| `--sidebar-sub-row-h` | `32px` | a section head, a row in a section (feeds, saved searches, a network's feeds), a child tag, a group's own feeds |
| `--sidebar-leaf-row-h` | `30px` | a child tag's feeds, inside its box; the saved-search "Show more" |
| `--sidebar-slot` | `28px` | the ⋯ and chevron columns |
| `--sidebar-frame-h` | `36px` | the foot's stepper and segmented frames |
| `--sidebar-card-pad` | `calc(var(--space-1) + var(--space-0))` | a card's padding, a group's bottom padding, the inner box's right and bottom margin |
| `--sidebar-row-inset` | `calc(var(--space-2) + var(--space-0))` | a row's left padding on its own surface |
| `--sidebar-row-deep-inset` | inset + `--icon-md` + `--space-3` | a row stepped in under a lead: it starts where its parent's label starts |
| `--drawer-shadow` | `6px 0 24px rgb(0 0 0 / 18%)` | the phone sheet's cast |
| `--drawer-scrim` | `rgb(0 0 0 / 32%)` light, `55%` dark | the backdrop behind the phone sheet |
| `--hidden-favicon-opacity` | `0.35` light, `0.55` dark | a hidden feed's greyed favicon |

Every row height is `--tap-target` on a coarse pointer.

### Icon sizes

Seven steps. The same tag glyph runs from a 12px pill to a 20px sidebar lead, and the sidebar's 28px buttons, its segmented controls and a child tag's lead carry an 18px glyph (#1749).

| Token | Value | Named size |
|---|---|---|
| `--icon-xs` | `12px` | `xs` |
| `--icon-sm` | `16px` | `sm` |
| `--icon-dense` | `18px` | `dense` |
| `--icon-md` | `20px` | `md` (default) |
| `--icon-lg` | `24px` | `lg` |
| `--icon-xl` | `32px` | `xl` (the big player's skip glyphs) |
| `--icon-2xl` | `36px` | `2xl` (the big player's play glyph) |
| — | `0.85em` | `text` |

Never write these directly in a component if an `<app-icon>` / `<app-tag-glyph>`
`size` input will do: `ICON_SIZE_TOKEN` in `shared/icon/icon.component.ts` maps
the named size onto the token, so no consumer writes a px.

**`text` is not a step on the scale.** It means "match the text you sit in", and
it is the right choice for any icon inline in a line of copy — the open-in-new
after a link, the glyph inside a tag pill. Such an icon has to agree with the
surrounding type, not with the scale, and it must keep agreeing when that type
changes. Snapping these to the nearest step is what made both of them look
oversized during #126: the open-in-new went 14px → 16px next to 13px text, and
the pill glyph held at 12px while `--fs-xs` pulled the pill's own text to 11px.

It resolves to **`0.85em`, not `1em`**, and that is deliberate. A Material
Symbol fills its em box; the lowercase text beside it reaches barely half of it.
Matching the two font sizes numerically still leaves the glyph looking about
twice the weight of the letters, so matching what the eye sees means going
under. Note this makes inline icons smaller than they were before #126 — the
open-in-new was 14px on 13px text on `develop`, i.e. already above `1em`.

The rule: **an icon inside a run of text uses `text`; a standalone icon — a
button's glyph, a list row's lead, a rail item — uses a step on the scale.**

### Row density

Two named pairs. Every list, rail and picker row derives its padding from one of
them — that is what keeps the picker and the reader's lists from drifting apart
again.

| Token | Value | |
|---|---|---|
| `--row-pad-y` | `var(--space-2)` | **compact** |
| `--row-pad-x` | `var(--space-3)` | |
| `--row-pad-comfy-y` | `var(--space-3)` | **comfortable** |
| `--row-pad-comfy-x` | `var(--space-4)` | |

**Compact** — navigation rails, chip strips, row menus, sidebar entries:
surfaces where scanning many items at once matters more than breathing room.
In use by `discover/category-rail`, `discover/category-chips`,
the `reader/sidebar` row menu, `admin/admin-catalog`. The sidebar's rows
themselves are fixed heights since #1749 (see §3 *Sidebar cards*).

**Comfortable** — the primary content lists a reader actually reads, and the
settings/admin rows that carry a control per line. In use by
`reader/entry-row`, `settings/organise-feed-row`, `admin/admin-users`. The reader's
entry list is the standard the picker was measured against in #126, so it is the
one that must never get tighter.

### Type

| Token | Value | For |
|---|---|---|
| `--font-sans` | `system-ui, -apple-system, 'Segoe UI', roboto, sans-serif` | the only family |
| `--fs-xs` | `11px` | badges, counts |
| `--fs-sm` | `13px` | secondary text, dense rows, `size="sm"` buttons |
| `--fs-dense` | `14px` | the sidebar's nested rows and its row menu (#1749) |
| `--fs-base` | `15px` | UI chrome — the `body` size |
| `--fs-read` | `16px` | **article body** |
| `--fs-lg` | `18px` | panel and section headings |
| `--fs-xl` | `24px` | page titles |
| `--lh-tight` | `1.25` | headings |
| `--lh-normal` | `1.5` | body copy |

The `--fs-*` values live in `theme/_type-scale.scss`; `text-scaled` containers multiply them (see *Text-size control*).

**`--fs-read` is deliberately a step above `--fs-base`.** Long-form reading wants
more than UI chrome does. Article headings size in `em` against it, so changing
this one value rescales the whole article.

**`overflow-wrap: anywhere` is set on `body` and inherited everywhere**
(`src/styles/_base.scss`). Feed text is arbitrary — a title can be an
80-character compound noun, a summary a base64 id — and at the CSS default such
a token may not break at all, so it paints straight out of its card and off the
screen (#292). Do not repeat the declaration in a component; two components had
already patched themselves that way and every other block had not, which is how
the bug survived. A surface that must hold one line says so with
`white-space: nowrap` (the magazine kicker line, the sidebar rows), and the
inherited rule is inert there.

### Breakpoints

Three steps only. The seven values that existed before (560/720/800/820/899/900/960)
were drift, not intent.

| Variable | Value | Meaning |
|---|---|---|
| `bp.$bp-sm` | `560px` | phone |
| `bp.$bp-md` | `720px` | small tablet, phone landscape |
| `bp.$bp-lg` | `900px` | desktop layout switch |

**These are SCSS variables, not custom properties, and that is not an oversight.**
`@media` cannot read custom properties: `@media (width <= var(--bp-md))` is not
an error, it simply never matches — the media query is silently dead and the
mobile layout never appears. So the breakpoints live in a SCSS partial and are
resolved at build time.

Usage — `@use` the partial with the relative path from the stylesheet:

```scss
@use '../../theme/breakpoints' as bp;

@media (width <= bp.$bp-md) {
  :host { display: none; }
}
```

Stylelint's `media-feature-name-unit-allowed-list: { "width": [] }` forbids any
unit inside a `width` media feature, so a literal `@media (width <= 720px)` is a
lint failure. The variable is the only way through.

`@container` queries take their thresholds from `_breakpoints.scss` too:
Stylelint's media-feature rules do not walk `@container`, so a literal there
lints clean but is exactly the drift the rule exists to prevent.

**The list header's compact form is container-driven (#1127).** `.list-header` is
the `list-header` inline-size container; its compact form keys to
`bp.$container-list-header-compact`, so a narrow split column gets the same icon
form as a phone. A rule for a list-header action goes in that `@container`, not
in a viewport `@media`. Every `appListAction` switches form together — all
labelled in the wide form, all icon-only in the compact form, no per-action
short label or opt-out (the global rule lives in
`frontend/src/styles/_list-action.scss`); the reader-view nav is not covered and
keeps its per-action `mobile-icon-only` modifier and a viewport `@media`. Dart
Sass does not resolve a variable inside an `@container` prelude — interpolate
it: `@container list-header (width <= #{bp.$container-list-header-compact})`. A
bare `bp.$…` compiles to an invalid condition that silently never matches
(#1132). Stylelint's local/no-bare-variable-in-container-prelude rejects the
bare form.

**The reader drawer's 720px boundary is class-driven, not media-driven.**
`LayoutService.NARROW_QUERY` is its single declaration; the shell binds
`.is-narrow` from that signal and `reader-shell.component.scss` keys the drawer
rules to the class. Do not add a `bp.$bp-md` media block for the drawer — that
would restore the two-sources drift #185 removed. `bp.$bp-*` remains correct
for purely presentational media queries that have no TS twin.

### Utility classes

Global, non-token CSS declared in `frontend/src/app/theme/_utilities.scss`
(`@use`d once from `src/styles.scss`, so it applies everywhere) rather than in
any one component's stylesheet.

| Class | For |
|---|---|
| `.sr-only` | Visually hides an element while keeping it in the accessibility tree — for a label assistive tech must expose that has no visible slot of its own (e.g. the freshness label on the admin user-detail feed rows: `<span class="sr-only">Last refresh:</span>` before the visible date). `aria-label`/`title` on a plain element are not a substitute: ARIA forbids naming a `role=generic` node, so most screen readers ignore both attributes on a bare `<span>`/`<div>`. |

### Media scrim

`--media-scrim` / `--on-media-scrim` (`theme/tokens.scss`) are the background and text colour of a label laid over a picture, whose colours are the picture's, not the theme's: the Short badge and the reader's play glyph.

### Brightness steps (#832)

`<html>` carries `data-brightness` next to `data-theme`: dark spans `-3`…`3`
(0 today), light spans `-6`…`0` (0 the brightest — light panels start at full
brightness, so light only dims). Step 0 is the palette in
`themes/_graphite.scss` verbatim; every other step is derived at build time by
`theme/_brightness.scss` and emitted as its own `:root[data-theme][data-brightness]`
block. A step is a lightness dial over three planes, each moving in OKLCH with
hue and chroma kept: **surfaces** (canvas, panels, borders, soft status
backgrounds) scale multiplicatively, fast when dimming and gently when
brightening; **colours** (accent, status hues, on-accent) scale at a gentler
rate so they stay themselves and only soften; **text** interpolates toward an
extreme, black when dimming and white when brightening, reaching it exactly at
the range end (light reaches black at `-6`; dark stays short of black so text
holds up as the surface sinks, and reaches white at `+3`). Contrast eases as you
dim instead of being pinned. `--media-brightness` is per theme (#1691): light
has none at and above the default and `0.92…0.52` (8 % per step) when dimming;
dark rests at `0.85` at every step and dims 20 % per step to `0.25`, or a white
picture outshines the dimmed text. One guarded rule in `styles/_base.scss`
applies it as a `filter` to `img`, `video` and `iframe`, and squared to `audio`,
whose light native chrome must recede harder than a photo. Dimming steps also
emit `--user-colour-brightness` (8 % per step, both themes) for any element
carrying `.user-colour` (an inline colour the palette cannot reach, e.g. a tag
glyph). To retune, edit the rate constants and `$ranges`
(the Sass mirror of `brightness.ts`) in `_brightness.scss`; never hand-tune a
step block. Each step also emits `--rim-light`, white scaled like a surface, so
the [glass rim](#glass-rim-1402) highlight dims with the panels.

### Glass rim (#1402)

Every full border — all four sides — is a Liquid Glass rim: a 1px border
painted as a 135° gradient over its base colour, brightest at the top-left
corner, a fainter glint at the bottom-right, a hair darker at the other two.
Single-side dividers stay flat.

**Tune it in one place: `theme/_glass-rim.scss`.** Its knobs — `$strength`,
`$control-boost`, `$faint-ratio`, the per-mode `$modes` amounts and the gradient
geometry (`$angle`, `$base-from`, `$shade-at`, `$base-to`) — derive every token
at build time; `tokens.scss` only calls `glass-rim.tokens(light)`,
`glass-rim.tokens(dark)` and `glass-rim.gradients()`. Never hand-edit one of
the tokens below.

| Token | Derivation |
|---|---|
| `--rim-light` | the highlight colour: white at step 0; each brightness step scales it like a surface, so the highlight dims with the panels (in light it equals `--surface-1` at every step) |
| `--rim-glint` | the mode's glint × `$strength`, mixed toward `--rim-light` at the top-left |
| `--rim-glint-faint` | `--rim-glint` × `$faint-ratio`, toward `--rim-light` at the bottom-right |
| `--rim-shade` | the mode's shade × `$strength`, toward black at the middle stop |
| `--rim-control-*` | each of the three amounts × `$control-boost` |
| `--rim-gradient`, `--rim-control-gradient` | the painted gradient of each tier, built from the amounts above and `--rim-base` |

Each amount is rounded to one decimal and mixed with `color-mix(in oklab, …)`
from the element's own border colour, so the brightness steps carry over.

**The gradient is declared once.** `glass-rim.gradients()` emits a single
`:where(*) { --rim-gradient: …; --rim-control-gradient: … }` rule (zero
specificity, from `tokens.scss`), so every element resolves `var(--rim-base)`
against its own value; `filled` and `ring` only paint
`var(--rim[-control]-gradient)`. A copy of the gradient at every site blew the
component-style budget. Never declare the gradient in a component — set
`--rim-base` and let the global rule resolve it.

**Two tiers.** `surface` (the default): magazine cards, source groups, settings
cards and panels, popovers and menus, dialogs (shadow unchanged), toasts, article
boxes, tiles, list containers. `control`: bordered buttons, inputs, selects and
textareas, the search field, icon buttons and glyph boxes, pills, chips, badges,
the count pill, unread dots, segmented frames — a small element shows the same
shift over a few pixels, so it carries `$control-boost` times the amount. When
in doubt, an element at or below control height is a `control`.

**Two mixins.**

| Mixin | For | Where in the rule |
|---|---|---|
| `glass-rim.filled($fill, $base: var(--border), $tier: surface)` | an element with a solid fill: the fill rides the padding box, the rim the border box beneath it | first |
| `glass-rim.ring($base: var(--border), $tier: surface)` | a see-through element: a masked `::after` paints the rim | last (it nests `&::after`) |

`ring` sets `position: relative` and owns `::after`, so its host must not be
`overflow: hidden` (the ring sits in the border area and would be clipped) or
need an `::after` of its own. A clipped see-through element takes `filled` with
the fill of the surface it sits on (organise's `.seg`, add-feed's
`.preview-rows`), and so does an `<input>`, which renders no `::after` (the
colour field's `.picker`, the global field rule). `<app-segmented-choice>` sits on
varying surfaces, so it dropped its clip and rounds its end buttons instead. An
element with a translucent fill or border (the loading card's fill, the toast's
40%-accent border) rings too: under `filled` the rim layer would show through a
translucent fill.

**States set the variables, never the properties.** Hover, focus, error and
selected states write `--rim-base` (the border colour) or `--rim-fill` (the
fill). A `background` on a `filled` element replaces the rim and leaves a
transparent border; a `border-color` paints it flat. A component tunes a
field's (input, select, textarea) resting border through `--field-rim-base`,
never `--rim-base`: the global field rule maps it, so `:focus` and
`[aria-invalid]` still win. A component never sets a select's `background` or
`padding`: the global rule draws its token-coloured chevron as `glass-rim.layers`
overlays inset by `--select-inset`, so it tunes that and `--field-rim-base`.

**Stays flat:** single-side dividers; solid-filled buttons and pills —
`<app-button>`'s `primary`, `danger` and `ghost`, add-feed's `.subscribe`,
discover's active category chip (their own `background` and
`border-color` replace the rim); the sidebar dropzone's dashed hint and the tag
picker's dashed suggestion pills;
the colour field's `.swatch` and the about chart's `.chart-marker` (2px rings).

**Magazine.** Blocks and source groups share `magazine-card.chrome`
(`reader/list/magazine/_magazine-card.scss`). Airy stays borderless:
`--card-border-width: 0` drops the border and `--card-bg: none` invalidates the
cards' whole background, rim included — `transparent` would let the rim fill
the card. With no card rim left, airy's hero, wide, split and thumb images
carry the boxed card's rim themselves (`magazine-card.image-rim`).
Every chrome block but quote takes its states from `magazine-card.card-states`: the
hover rim, the title's hover underline and a
post's `.title.post`. Quote keeps its own pull-quote underline, compact its hover
background. Every block and the list row open their entry through `[appOpensEntry]`
(`reader/list/opens-entry.directive.ts`).

**Admin.** Stylesheets under `app/admin/` and `app/settings/admin/` keep their
flat borders; shared components rendered there carry the rim.

**Enforced** by `theme/glass-rim-coverage.spec.ts` (Jest, part of
`npm run check`): it fails, naming `file:line [selector]`, on any
`border: <n>px solid …` outside admin. Each exception is listed in its `EXEMPT`
array with a reason.

---

## 2. Component catalog

All live in `frontend/src/app/shared/` and are standalone, `OnPush`, and use
signal `input()`s. `app-entry-meta` and `app-entry-actions` are the exceptions:
they live in `frontend/src/app/reader/` instead, because they know `EntryDto`
and the reader's i18n keys, and neither sets `OnPush`.

### `<app-icon>`

A Material Symbol at a named size.

| Input | Type | Default |
|---|---|---|
| `name` | `string` (required) | — |
| `size` | `'text' \| 'xs' \| 'sm' \| 'md' \| 'lg'` | `'md'` |

```html
<app-icon name="delete" size="sm" />
```

The size lands on the host's `font-size`, not on an inner span, so the two
consumers with a genuinely fluid pixel box — `app-favicon` and `app-user-avatar`
— can override it with a template style binding (which outranks a host binding).

**Not for:** a tag's or category's glyph — that is `<app-tag-glyph>`, which also
owns the colour fallback and the square footprint.

### `<app-masked-logo>`

An SVG file drawn as a coloured mask, sized `--icon-sm`, hidden from assistive
technology: the label beside it carries the meaning.

| Input | Type | Default |
|---|---|---|
| `src` | `string` (required) | — |
| `color` | `string` | `var(--text-primary)` |

```html
<app-masked-logo src="share-targets/bluesky.svg" color="#1185fe" />
```

**Not for:** a connected social network's mark, which is `<app-network-logo>`
with its inline path and brand token.

### `<app-tag-glyph>`

The one way to render a tag or catalog category. With an icon it renders the
glyph tinted; without one it falls back to a colour dot, so an icon-less tag is
still identifiable at a glance.

| Input | Type | Default |
|---|---|---|
| `name` | `string \| null` | `null` |
| `color` | `string \| null` | `null` (→ `var(--text-muted)`) |
| `size` | `'text' \| 'xs' \| 'sm' \| 'md' \| 'lg'` | `'md'` |

```html
<app-tag-glyph [name]="node.tag.icon" [color]="node.tag.color" size="md" />
```

The host is a square of the named size whichever branch renders, because a dot is
far smaller than a glyph and lists mix the two freely. Owning the footprint here
is what lets every consumer drop the fixed-width slot it used to need to keep tag
names on one left edge. Callers highlighting a selected row pass the highlight
colour in `color` (`'currentColor'`, say) — both branches honour it.

**Not for:** a surface that wants the dot **and** the glyph side by side. That is
a different design, and no surface here asks for it — every caller takes the
either/or.

### `<app-field>`

Form field layout: label, optional required marker, the projected control, an
optional hint and an optional error.

| Input | Type | Default |
|---|---|---|
| `label` | `string` (required) | — |
| `error` | `string \| null` | `null` |
| `hint` | `string \| null` | `null` |
| `info` | `string \| null` | `null` — an already-translated explanation; renders an `<app-info-tip>` in the label row (#372) |
| `required` | `boolean` | `false` |

```html
<app-field [label]="'dialog.tagForm.name' | transloco">
  <input id="tag-name" formControlName="name" maxlength="100" cdkFocusInitial />
</app-field>
```

**Deliberately not a `ControlValueAccessor`.** The native control stays in the
consumer's template with its own `formControlName`, so `type`, `autocomplete`,
`inputmode` and the rest need no re-exposure as inputs. This component owns only
what was being retyped: the label, the rhythm and the error slot. The projected
control is styled globally by `styles/_controls.scss`, because
`ViewEncapsulation` does not reach projected content.

**Not for:** a dense data grid whose controls have no visible label. The stacked
label would triple the row height — see `admin-catalog` in
[§6](#6-deliberate-exceptions).

### `<app-password-input>`

Wraps a password control and adds a button that reveals the secret while the
user types. Takes no inputs — wrap the existing `<input type="password">`.

```html
<app-field [label]="'auth.password' | transloco">
  <app-password-input>
    <input type="password" formControlName="password" autocomplete="current-password" />
  </app-password-input>
</app-field>
```

**Projects the control, like `<app-field>` — never owns it.** The input keeps
its own `formControlName` or `[value]` binding, its `autocomplete`, `name` and
`data-testid`; the component reaches the projected input once and only ever
flips its `type`. That is deliberate: the auth forms recover browser autofill by
reading `input[formControlName]` straight from the DOM (`auth/autofill.ts`), so
the `formControlName` must stay on the real `<input>`. The projected input's
right padding (room for the button) and width live in `styles/_controls.scss`
for the same reason `<app-field>`'s do. Works uniformly for the reactive-form
fields and the signal-driven settings secrets (AI key, proxy password); the
proxy field carries its width class on the wrapper so the inner input can fill
it.

**Not for:** a field with no secret to hide — a plain `<input>` in `<app-field>`
needs no reveal button.

### `<app-color-field>`

Colour chooser: a row of presets, a native picker for anything else, and an
optional clear button for "no colour". The presets come from
`shared/icon-choices.ts`, which stays the single place the palette is defined.

| Input / output | Type | Default |
|---|---|---|
| `value` | `string \| null` | `null` |
| `valueChange` | `output<string \| null>` | — |
| `clearable` | `boolean` | `true` |

The clear button's label is projected as content.

```html
<app-color-field [value]="color()" (valueChange)="color.set($event)">
  {{ 'dialog.tagForm.none' | transloco }}
</app-color-field>
```

Not a `ControlValueAccessor` either: both consumers drive it from a signal, and
`value`/`valueChange` keeps it usable with either. Pass `[clearable]="false"`
where the value is mandatory (a catalog category always has a colour).

**Not for:** a bare `<input type="color">` that needs no presets — that is a
native control and takes the global styling in `styles/_controls.scss`.

### `<app-icon-picker>`

A selector over the curated Material Symbols in `shared/icon-choices.ts` — the
same set the reader's tag form and the admin catalog both offer, so a tag and a
category are picked from one palette.

| Input | Type | Default |
|---|---|---|
| `value` | `model<string>` (two-way) | `''` — the empty string is "no icon" |
| `color` | `string \| null` | `null` — tints the trigger glyph |
| `inline` | `boolean` (attribute) | `false` |

**Two framings of one grid, chosen with `inline`:**

- *popover* (default) — a compact trigger that sits between other controls in a
  dense row. Used by the admin catalog.
- *inline* — the grid takes a permanent place and selection costs one click.
  Used by the tag form, which has room to spare.

```html
<!-- popover -->
<app-icon-picker [(value)]="category.icon" [color]="category.color" />

<!-- inline -->
<app-icon-picker inline [value]="icon() ?? ''" (valueChange)="icon.set($event || null)" />
```

Escape dismisses an open popover and is swallowed, so it does not also reach the
CDK dialog listening on `body` and close the whole form. Inline mode never opens,
so Escape passes through untouched and still belongs to the dialog.

**Not for:** picking an arbitrary Material Symbol. The set is curated on purpose.

### `<app-overlay-panel>`

The frame every interrupt surface renders inside: a centred card, capped at 90dvh
with its body scrolling, at every width. Owns the heading, the scrolling body and
the footer row, so a dialog's own stylesheet carries only what is specific to it.

A dialog stays a card on a phone too — a short confirm has no business opening as
a mostly-empty full page. The one exception is a route-level picker that *is* the
page rather than a modal over it (discover): it passes `fillOnMobile` to keep the
full-screen phone layout.

| Input | Type | Default |
|---|---|---|
| `heading` | `string` (required) | — |
| `headingLevel` | `1 \| 2` | `2` |
| `fillOnMobile` | `boolean` | `false` |

Content slots: default content becomes the scrolling body; `[headerActions]`
lands beside the heading; `[subheader]` lands between the heading and the body,
outside the scroller, for controls that steer the body (the social import
dialog's tabs and filters) and so must never scroll away or swallow a tap mid-fling;
`[footer]` lands in the footer row. The subheader and the footer hide themselves
when empty.

```html
<app-overlay-panel [heading]="data.title" cdkTrapFocus>
  <p class="msg">{{ data.message }}</p>

  <app-button footer (click)="ref.close(false)">{{ 'dialog.cancel' | transloco }}</app-button>
  <app-button footer focusInitial variant="danger" (click)="ref.close(true)">
    {{ data.confirmLabel }}
  </app-button>
</app-overlay-panel>
```

`heading`, not `title`: an input called `title` on a component host collides with
the native attribute and renders a stray browser tooltip over every dialog.

`headingLevel` is an outline decision, not a typographic one — both render at
`--fs-lg`. A dialog opens over a page that already has an `h1`, so `2` is right
for every one of them. A panel that *is* the page (discover, a route rather than
an overlay) passes `1`.

Width and max-height are per-consumer and come from `--panel-w` / `--panel-max-h`
set on the consumer's host — not from inputs, so they stay in the stylesheet with
the rest of the sizing. In use: 400px (confirm), 440px (edit subscription), 460px
(tag form, and the component default), 520px (add feed), 1040px (discover).

Two further knobs exist, and both are **opt-in and useless apart** (#561):
`--panel-h` gives the panel a definite height, and `--panel-body-container: size`
makes its body a size container. Set them only when a descendant must size itself
against the body — the price is a panel that is always that tall, empty space
included, so no content-sized dialog should ever set them. Discover sets both, so
its category rail can cap itself with `100cqh`; see "Sticky and scroll" for why a
percentage cannot do that job.

The add-feed dialog is the wide variant: `--panel-w: 520px`, sized so a preview
row's 88×66 thumbnail and four-line snippet sit comfortably. It sets
`fillOnMobile`, so on a phone it becomes the full screen rather than a 92vw card
— a card would squeeze the row's title to ~125px. This was the first non-default
`--panel-w`; keep new panels on the 460px default unless their content needs the
room, and record the exception here. The social import dialog (#1611) is the
second: 520px for an avatar, a name and a state label per row, and `fillOnMobile`
because a follow list runs to thousands of rows and its footer must stay in reach.

**Not for:** a non-modal popover or dropdown menu. The panel declares
`role="dialog" aria-modal="true"`.

### `<app-confirm-dialog>` (via `Dialog.open(ConfirmDialogComponent, { data })`)

The app's one confirmation prompt: a title, a message and a Cancel/confirm pair,
built on `<app-overlay-panel>`. Every destructive action that isn't a delete-tag-
from-a-row-menu case routes through this rather than growing its own dialog.

| `ConfirmData` field | Type | Default |
|---|---|---|
| `title` | `string` (required) | — |
| `message` | `string` (required) | — |
| `confirmLabel` | `string` (required) | — |
| `danger` | `boolean` | `false` — weights the confirm button `danger` instead of `primary` |
| `requireText` | `string` | `undefined` — see below |

```ts
const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
  data: { title, message, confirmLabel, danger: true, requireText: user.email },
  role: 'alertdialog',
  panelClass: 'app-dialog',
});
```

`requireText` gates the confirm button behind a typed match: the dialog renders a
text field under the message, and the button stays disabled until the field's
value equals `requireText` exactly (case-sensitive, no prefix match). Use it for
a deletion that takes content with it and cannot be undone — account deletion,
not a single tag. A click is cheap; typing the thing you are about to lose is
not. `focusInitial` moves from the confirm button to this field when it is
present, because a disabled button cannot hold initial focus — without the move,
the dialog opens with focus on nothing.

**Not for:** confirmations that leave the door open to undo, or where the cost of
a wrong click is low (reordering, unpinning). Those take a plain `ConfirmData`
with no `requireText` and keep the one-click confirm.

### `<app-button>`

The app's one ordinary button: a label, optionally a leading icon, one of five
weights.

| Input | Type | Default |
|---|---|---|
| `type` | `'button' \| 'submit'` | `'button'` |
| `variant` | `'default' \| 'primary' \| 'accent-outline' \| 'danger' \| 'danger-outline' \| 'ghost'` | `'default'` |
| `size` | `'sm' \| 'md'` | `'md'` |
| `loading` | `boolean` | `false` — swaps the label for a spinner and disables |
| `disabled` | `boolean` | `false` — renders off in every variant: no fill, a faint outline, muted text; `loading` keeps the variant |
| `block` | `boolean` (attribute) | `false` — stretch to the container's width |
| `focusInitial` | `boolean` (attribute) | `false` |

| Variant | Weight | Use for |
|---|---|---|
| `default` | bordered, surface fill | the ordinary action |
| `primary` | accent fill | the one action the surface exists for |
| `accent-outline` | accent border, accent text | live, but not that one action — a secondary action on a card whose save bar already owns `primary` |
| `danger` | filled danger | **confirming** a destructive action |
| `danger-outline` | danger border, danger text | **initiating** a destructive action |
| `ghost` | no chrome at rest | the quiet way out — Cancel, Skip |

`size="sm"` is for dense rows (a settings list, an admin table) where a 40px
control would set the row height instead of the content; its height comes from
padding, so the label and any leading icon still decide it.

Bind `(click)` on `<app-button>` as usual: while `disabled` or `loading`, the
component swallows clicks on its host before any consumer listener sees them,
so the handler need not check the state again (#1610).

`focusInitial` puts `cdkFocusInitial` on the real `<button>`. The CDK's focus
trap calls `focus()` on the element carrying that attribute, and this component's
host is not focusable — put the attribute on the host and the dialog opens with
nothing focused.

`block` is opt-in. It used to be unconditional, which is why no surface outside
the auth forms could adopt this component.

#### Two rules that were decided the hard way

**1. `<app-button>` is for ordinary action buttons, and nothing else.** It is
*not* for icon-only affordances that carry their own interaction semantics.
These deliberately stay out and own their markup and styles:

- the sidebar's `.dots` row-menu triggers
- the entry row's read toggle
- the view-controls segmented control
- the sidebar's brightness stepper (`<app-brightness-control>`)
- `<app-to-top-button>`
- `<app-icon-picker>`'s trigger

Forcing those through `<app-button>` would turn it into a grab bag: each needs a
different hit area, a different pressed/active state, or an `aria-*` contract
this component does not model.

**2. Destructive weight is a two-step scale.** Filled `danger` *confirms* a
destructive action — it is the moment of destruction and should carry the weight
of one. `danger-outline` only *initiates* one: the Delete sitting on every row of
a list, which opens a confirmation rather than destroying anything itself.
Flattening the two would make every Delete in a list shout as loudly as the
confirmation.

### `<app-error-banner>`

The app's one error banner: a message in a `role="alert"` region, and an
optional single action button — retry a failed load, dismiss a failed row
action, or neither for a plain inline failure message.

| Input / output | Type | Default |
|---|---|---|
| `message` | `string` (required) | — |
| `actionLabel` | `string \| null` | `null` — omits the button |
| `action` | `output<void>` | — |

```html
@if (error()) {
  <app-error-banner
    [message]="error()!.detail || error()!.title"
    [actionLabel]="'admin.retry' | transloco"
    (action)="load()"
  />
}
```

`actionLabel` takes an already-translated string, not an i18n key — the
component lives in `shared/` and must not hardcode a feature's translation
keys (`admin.retry` / `admin.dismiss` today). Extracted in #180 when the
markup and styles behind three admin screens' error banners had drifted into
byte-identical copies.

**Not for:** a toast or a non-blocking notification — this is a static block
that occupies layout where it renders, not an overlay.

---

### `<app-warning-box>`

The app's one warning callout: a neutral card on `--surface-1` ringed in amber
(`--border-warning`, #854). The sibling of `error-banner` — an error is a filled
danger banner, a warning is a ring. It projects its content, so each surface
keeps its own body; it owns only the ring, surface, radius and padding, never
the layout or wording inside.

```html
<app-warning-box class="paywall-note">
  <span class="warning-glyph" aria-hidden="true">⚠</span>
  <span class="warning-text">… <a class="note-link" [href]="url">…</a></span>
</app-warning-box>
```

The host is a `display: flex` row: a leading marker (the paywall glyph) sits in
a gutter with the body hanging indented beside it, while a lone child (the
unhealthy-feed row's mono error) just fills the box. The projected glyph, prose
and in-sentence link or retry button take the global `.warning-glyph`,
`.warning-text` and `.note-link` classes (`theme/_utilities.scss`); a
`.note-link` is amber inside the box and accent outside it. Spacing to its
neighbours and the wording belong to the consumer — the box holds no i18n. Used
by `app-paywall-notice` (#785, #855), the reader's fallback note, the throttled
comments note (#1140) and the unhealthy-feed error box (#847).

**Not for:** a danger message with an action — that is `error-banner`. The
amber ring reads as "heads up", not "this failed".

---

### `<app-toast>` (via the `ToastService`)

The app's one toast: a surface pinned to the bottom of the viewport, with an
optional single action, auto-dismissing after `durationMs` (default 6000ms).
Rendered through the CDK overlay, never `position: fixed` — a transformed
ancestor (an open drawer, a dialog) would re-anchor a fixed child to the wrong
containing block (#85, #100). `hasBackdrop: false`, `autoFocus: false`,
`restoreFocus: false`: a toast must never steal focus from whatever the user
is doing, unlike every other surface in this catalog.

```ts
private readonly toast = inject(ToastService);

this.toast.show({
  message: this.transloco.translate('reader.recommendations.applied'),
  actionLabel: this.transloco.translate('reader.recommendations.undo'),
  action: () => this.undo(),
});
```

A toast carries **either** a `message` or a `content` component, never both.
`ToastData` is a union of the two, so the compiler rejects a toast that tries
to be both at once.

| `ToastData` field | Type | Default |
|---|---|---|
| `message` | `string` — the message mode | — |
| `content` | `Type<unknown>` — the content mode | — |
| `actionLabel` | `string` | `undefined` — omits the button |
| `action` | `() => void` | `undefined` — runs before the toast closes |
| `durationMs` | `number \| null` | `6000`; `null` never auto-dismisses |
| `width` | `'fit' \| 'fixed'` | `'fit'` — the pane sizes to its content |

`ToastService` also exposes `visible: Signal<boolean>` — whether a toast is on
screen at all. It is there for the persistent mode: a feature that raises a
long-lived toast needs to know the user closed it.

`message` and `actionLabel` are already-translated strings — the component
lives in `shared/` and must not know any feature's i18n keys. `content` is a
component built through `NgComponentOutlet`, so it injects and reads its own
feature's services and resolves its own translations; nothing is threaded
through `ToastData`. `show()` replaces whatever toast is already visible,
clearing its timer; there is no queue. There is only one toast, so
`ToastService` is injected directly rather than opened against a template
reference.

**The persistent modes.** `durationMs: null` and `width: 'fixed'` exist for one
caller, and adding a second is a design decision, not a convenience: the
For-You run pill (#398). A recommendation run takes minutes and the user
navigates away from it, so its progress readout is `ForYouProgressComponent`
hosted as `content` with no dismiss timer, and `RecommendationsService` puts the
ready message in the same slot when the run ends. `width: 'fixed'` pins the pane
to `22rem` across that handover — the pane is otherwise content-sized and
centre-anchored, so the box would shrink from both edges at the moment of
completion. Whoever raises a persistent toast owns taking it down: the run's
`finish()` is the single exit from every end state and calls `dismiss()` there.

A persistent toast must also be recoverable, because the ✕ is always available
and a minutes-long surface will get closed. `ToastService.visible` is a signal
for exactly that: the run derives `pillHidden` from `running() && !visible()`
and the reader's list header offers the pill back beside the Stop button.

**Accessibility.** The toast shell owns the `role="status" aria-live="polite"`
region. A `content` component must not declare a second one inside it, and must
hide any value that changes faster than the user can read it — the run pill's
ETA is `aria-hidden`, so only the batch count is announced.

**Not for:** a failure that blocks the surface it reports on — use
`<app-error-banner>` instead, which stays in the document until its own
action or a reload clears it. The message mode is for a transient, dismissible
confirmation of something that already happened (a background refresh
finished, a bulk action applied) that the user does not have to act on.

---

### `<app-disclosure>`

The one wrapper for a native `<details>`/`<summary>` collapsed-content pattern:
a summary line and the projected body. No open/closed signal, no animation, no
ARIA reimplementation — `<details>` already gives all three for free.

| Input / output | Type | Default |
|---|---|---|
| `label` | `string` | `''` — an already-translated summary line |
| `appearance` | `'pill' \| 'row' \| 'drill-in'` | `'pill'` |
| `startOpen` | `boolean` | `false` — one-way; the caller's state decides the initial open state |
| `opened` | `output<void>` | — fires when the body is revealed, and only then |

```html
<app-disclosure [label]="'Show the fixed prompt' | transloco">
  <pre class="fixed">…</pre>
</app-disclosure>
```

`label` takes an already-translated string, not an i18n key — the component
lives in `shared/` and must not hardcode a feature's translation keys. A caller
that needs a richer summary projects its own markup into the `[summary]` slot
and leaves `label` unset. Extracted in #321 from the two places this shape had
been hand-rolled (`recommendation-settings-card`'s fixed-prompt panel,
`recommendation-debug-log`'s panel shell). It owns only the toggle line
(cursor, colour, size, full-width block); a host that needs panel-level
layout — margin, base font-size, the bordered entry rows — keeps that on its
own wrapping class, the same way `recommendation-debug-log.component.scss`'s
`.debug-panel` still does.

`appearance` picks the summary chrome. `pill` (default) is the bordered toggle
button. `row` is a flat, full-width list row for one disclosure per list item.
`drill-in` (#541) is a full-width Grouped list row: the projected heading/label
sits on the left, a trailing chevron rotates when the `<details>` opens. It
reuses the same `startOpen`/`opened`/`label`/`[summary]` API. Use `drill-in`
for an advanced or Expert section that expands in place inside a settings
group.

---

### `<app-info-tip>`

The one info affordance (#372): a small ⓘ icon button that toggles an
explanation panel. The panel is a **floating popover** (#541): it is
`position: absolute`, anchored to the ⓘ trigger, right-aligned and
viewport-clamped (`max-width: min(20rem, calc(100vw - var(--space-4)))`), so it
never clips on a phone. Opening it does not shift the sibling layout. Click/tap
to toggle; Escape or a press outside dismisses (via `DismissOnOutsideDirective`).
Only one tip is open at a time — a module-level reference closes the previous
one when a new one opens. The trigger is a real button with
`aria-expanded`/`aria-controls`. Under `pointer: coarse` the trigger grows to
`--tap-target`.

`.wrap` is the positioning context: an inline anchor holding the trigger, with
the panel absolutely positioned against it. This supersedes the earlier in-flow
arrangement (#433/#372), where host and wrapper were `display: contents` and the
panel claimed a full-width line below the row; #541 clamps the popover in CSS
instead, so a settings row no longer grows a full-width block when a tip opens.

| Input | Type | Default |
|---|---|---|
| `text` | `string` (required) | — |
| `label` | `string` (required) | — |

```html
<app-info-tip
  [text]="'settings.ai.info.rowActions' | transloco"
  [label]="'settings.ai.info.actionsLabel' | transloco"
/>
```

`text` and `label` take **already-translated strings** (shared component, no
feature keys). `label` is the accessible name of the trigger — pass the label
of the control being explained.

A tip inside a `<summary>` or a wrapping `<label>` is fine — both the trigger
and the panel call `preventDefault` and `stopPropagation`, so no click of
theirs reaches the container to collapse the `<details>` or toggle the control.
`<app-field>` relies on this: its tip sits in the label row inside the
`<label>` that names the control.

**Not for:** validation or state messages (that is `app-field`'s `error`/
`hint`), or anything that must be visible without interaction — a danger
zone keeps its always-visible note.

### `<app-segmented-choice>`

A small segmented control over a fixed set of string options, each labelled
by the translation key `<labelPrefix><option>`. It emits `pick` with the
clicked option; the consumer owns the selection and passes it back as
`selected`.

| Input | Type | Default |
|---|---|---|
| `options` | `readonly T[]` (required) | — |
| `selected` | `T` (required) | — |
| `ariaLabelKey` | `string` (required) | — the group's accessible name, as a translation key |
| `labelPrefix` | `string` (required) | — |
| `disabledOptions` | `readonly T[]` | `[]` — options that cannot be picked now |
| `describedBy` | `string \| null` | `null` — the id of the text that says why an option is disabled |
| `counts` | `Partial<Record<T, number>>` | `{}` — a number shown after an option's label, such as how many items a tab holds |

```html
<app-segmented-choice
  [options]="languages"
  [selected]="language.lang()"
  ariaLabelKey="lang.label"
  labelPrefix="lang."
  (pick)="language.set($event)"
/>
```

A disabled option stays visible at the shared disabled look; say why in a
hint beside the control (the AI model picker's "This provider offers no
LLMs.") and pass that hint's id as `describedBy`, since a disabled button
takes no focus and a keyboard user would otherwise never hear the reason.

The control sizes to its labels; a consumer that wants it full width makes
the host `display: flex` and the buttons share the width (the social import
dialog's tabs).

**Not inside `<app-field>`.** The field wraps its control in a `<label>`, and a
click on the label's text presses the first button of the group. Name the group
with `ariaLabelKey` and put a hint in a paragraph under it.

---

### Settings design system

These primitives live in `frontend/src/app/shared/settings/` and are the
canonical building blocks every settings and admin section composes. The
"Grouped" look lives inside them and in the tokens — never in a feature `.scss`.
A feature section stacks these components; its own stylesheet holds only layout
glue. Introduced by #541.

### `<app-settings-stack>`

The vertical rhythm of one settings or admin page: a flex column that stacks the
page's groups with the one canonical gap. It is the template root of every
settings and admin route component, and it takes no inputs.

```html
<app-settings-stack>
  <app-settings-group …>…</app-settings-group>
  <app-settings-group …>…</app-settings-group>
</app-settings-stack>
```

**Why a container and not an adjacent-sibling rule.** The gap it replaces was
`app-settings-card + app-settings-card` in `src/styles/_base.scss`. A sibling
selector cannot cross a component host boundary, so the moment one card was
rendered from inside another component the gap vanished and the child carried a
compensating `margin-block-start` (#454). A stack's children are flex items, so
a child that is another component's host element is spaced identically to a
group written inline. **A section must never carry spacing to sit correctly in a
stack** — if it seems to need one, the stack is missing, not the margin.

`min-width: 0` on the host keeps a wide descendant (a scrolling table, the #409
run-history grid) from widening the page instead of scrolling inside its own
container.

### `<app-settings-group>`

One grouped-settings section: a header (a tinted icon chip, a title and an
optional caption) above a card surface that projects the group's rows or
disclosures.

| Input | Type | Default |
|---|---|---|
| `icon` | `string` | `''` — a Material Symbol name, rendered via `<app-icon>` |
| `title` | `string` | `''` |
| `caption` | `string` | `''` — omits the caption line when empty |

```html
<app-settings-group
  [icon]="'smart_toy'"
  [title]="'settings.ai.forYou.title' | transloco"
  [caption]="'settings.ai.forYou.caption' | transloco"
>
  <app-settings-row …>…</app-settings-row>
</app-settings-group>
```

`icon`, `title` and `caption` take already-translated strings, not i18n keys —
the component lives in `shared/` and must not hardcode a feature's translation
keys. The body (rows, disclosures) projects through the default `<ng-content>`
into a `.panel` card surface: `--surface-1`, `1px --border`, `--radius-lg`, and
the new `--panel-shadow` token (defined for both modes in `theme/tokens.scss`).

**`.panel` is unpadded by design.** `app-settings-row` pads itself, so a group
that stacks only rows needs nothing here. Any *other* direct panel child — a
banner, a skeleton, a loading state, a bordered sub-block — is not a row and
must supply its own inset by reading `--panel-inset-y`/`--panel-inset-x`
(`theme/tokens.scss`, mode-invariant, narrowing at the same `bp.$bp-sm` step
`app-settings-row` does) rather than writing a padding literal. That pair is
the inset's one owner; a feature stylesheet that reaches for
`padding: var(--space-4) var(--space-5)` instead is the twelfth section
about to forget it (#547).

**Not for:** nothing. Every settings and admin section composes a group; the
flat `app-settings-card` it replaced was deleted in #547.

A named `<ng-content select="[groupActions]">` slot sits at the trailing edge of
the header and takes one element — a "New" button, a filter group. The header
wraps, so actions that do not fit drop to their own line instead of crushing the
title. Projection matches only a **direct** child of the group (one `@if` deep
is tolerated, two silently fall through to the panel), so keep the marked
element at the top of the group's content.

### `<app-settings-row>`

One settings row: a title and an optional description stacked on the left, a
projected control on the right, vertically centred. It is the primitive a group
stacks.

| Input | Type | Default |
|---|---|---|
| `title` | `string` | `''` |
| `description` | `string` | `''` — omits the `.row-desc` element when empty |
| `stackable` | `boolean` | `false` |
| `labelFor` | `string` | `''` |
| `error` | `string \| null` | `null` — mirrors `app-field`'s error slot; renders a `role="alert"` message below the row |

```html
<app-settings-row
  [title]="'settings.ai.length.title' | transloco"
  [description]="'settings.ai.length.desc' | transloco"
  [stackable]="true"
>
  <app-info-tip rowTitleTip [text]="…" [label]="…" />
  <select>…</select>
</app-settings-row>
```

The control projects through the default `<ng-content>`. A named
`<ng-content select="[rowTitleTip]">` slot places an inline adornment
immediately after the title text inside `.row-title` — an `<app-info-tip>`, or a
small badge such as the preferences page's "Experimental" chip. The slot is
positional, not typed. The inset hairline divider between rows is
automatic — `:host(:not(:last-child))` draws it, so the parent group supplies
only the box. When `stackable`, on a narrow viewport (`bp.$bp-sm`) a select or
number control fills the row width while a toggle keeps its natural size.

`labelFor` takes the `id` of the control the row holds; the title text then
renders as a `<label for>`, so clicking it operates the control. A toggle row
should always set it — the row title is the larger click target, and a switch
alone is a small one.

### `<app-settings-save-bar>`

The shared save/reset affordance for a settings surface: an "unsaved changes"
indicator, a ghost Reset and a primary Save.

| Input / output | Type | Default |
|---|---|---|
| `dirty` | `boolean` | `false` |
| `saving` | `boolean` | `false` |
| `saveLabel` | `string` | `''` |
| `resetLabel` | `string` | `''` |
| `unsavedLabel` | `string` | `''` |
| `save` | `output<void>` | — |
| `reset` | `output<void>` | — |

```html
<app-settings-save-bar
  [dirty]="dirty()"
  [saving]="saving()"
  [saveLabel]="'settings.save' | transloco"
  [resetLabel]="'settings.reset' | transloco"
  [unsavedLabel]="'settings.unsaved' | transloco"
  (save)="persist()"
  (reset)="revert()"
/>
```

`saveLabel`, `resetLabel` and `unsavedLabel` take already-translated strings, not
i18n keys. The unsaved indicator shows only when `dirty`. Save is disabled unless
`dirty` and shows a spinner while `saving`. This bar does **not** own the success
confirmation: the consumer decides when a persist succeeded and fires the global
`shared/toast` itself. Coupling the toast in here would make the bar guess at an
outcome it never sees.

---

### `<app-action-sheet>` (via the `ActionSheet` service)

The row-menu surface for coarse pointers: a sheet pinned to the bottom of the
viewport, titled with the row it acts on. Opened through the `ActionSheet`
service — never instantiated in a template — because the open drawer carries a
transform, which would re-anchor any `position: fixed` child; the CDK overlay
escapes that.

```ts
this.sheet
  .open({ title: tag.name, actions: [{ id: 'edit', label: editLabel }] })
  .subscribe((choice) => { /* undefined on dismiss */ });
```

Labels and title are **already-translated strings** (shared component, no
feature keys). `danger: true` renders the action in the danger colour. An
action may lead with a `symbol` (a Material symbol) or a `logo: { url, color? }`
(drawn through `<app-masked-logo>`), and `ACTION_SHEET_DIVIDER` among the
`actions` renders a separator between groups. The head (grabber, title, the
round close button) stays pinned while a long list scrolls. Dismissed by the
close button, a backdrop tap, Escape, or a downward pull on a list at its top —
all resolve `undefined`.

**Not for:** fine-pointer surfaces (the sidebar keeps its inline `.pop`
popover on desktop) or anything with form controls — that is a dialog in
`<app-overlay-panel>`.

---

### `<app-spinner>`

The RSS mark, animating, as the app's loading indicator. Used for a fetch whose
result is not a list — a list should show `<app-skeleton>` instead, so the
layout does not jump when rows arrive.

| Input | Type | Default |
|---|---|---|
| `size` | `number` (px) | `18` |
| `decorative` | `boolean` | `false` — keeps `role="status"` and the "Loading" label |
| `animate` | `boolean` | `true` |

```html
@if (loading()) {
  <app-spinner />
}
```

`decorative` is for the brand mark in the top bar, which is not announcing a
load. `animate: false` holds the mark still in the signal colour — the brand
mark only animates while a refresh is actually running.

**Not for:** a list load. Use `<app-skeleton>`.

---

### `<app-skeleton>`

Placeholder rows for a list that is still loading. Sized from the comfortable
row-density tokens, so the layout does not shift when the real rows arrive.

| Input | Type | Default |
|---|---|---|
| `label` | `string` (required) | — |
| `rows` | `number` | `3` |

```html
@if (store.loading()) {
  <app-skeleton [label]="'settings.tags.loading' | transloco" [rows]="4" />
}
```

`label` takes an already-translated string, not an i18n key. The placeholder
rows are `aria-hidden`; the `role="status"` label is what gets announced. The
pulse animation is disabled under `prefers-reduced-motion`.

The pulse is the app's one loading idiom. It lives in `theme/_loading-pulse.scss`, so
anything else that is still loading pulses the same way. The link-card placeholder
of a link share still being completed (#1502) is the second user.

The pulse fills with a tint of `--text-primary` rather than a surface token, so
the rows read on the page, on a card and inside an `<app-overlay-panel>` alike
(#1623). A dialog needs no wrapper around the skeleton.

**Not for:** a non-list fetch — use `<app-spinner>`, which does not pretend to
know the shape of what is coming.

---

### `<app-avatar>`

A social author's picture, round, falling back to their initials when there is no
picture or it fails to load. The picture goes through the image proxy and is asked
for without a referrer.

| Input | Type | Default |
|---|---|---|
| `name` | `string` (required) | — the initials come from it |
| `url` | `string \| null` | `null` — initials straight away |
| `toneSeed` | `string \| null` | `null` — neutral initials on `--surface-sunken` |
| `size` | `'sm' \| 'md'` | `'sm'` — `--icon-md`; `'md'` is `--space-6` |

```html
<app-avatar [url]="post.authorAvatarUrl" [name]="post.authorName" [toneSeed]="post.authorHandle" />
```

The tones are the four in `theme/_avatar-tone.scss`; a seed (the handle, where there
is one) gives the same author the same tone everywhere. Used by the comments, the
quoted post and the social import dialog.

**Not for:** the signed-in user. Use `<app-user-avatar>`, which reads Gravatar.

---

### `<app-search-field>`

The entry-search input. It owns every timing rule the search has, so no caller
repeats one: a 300 ms debounce, the three-character floor, the too-short hint,
the trailing clear-or-close button, and `Escape`.

| Input | Type | Default |
|---|---|---|
| `term` | `string` | `''` — the term currently in the URL |
| `dismissible` | `boolean` | `false` — whether this mount can be left |

| Output | Type | Fires |
|---|---|---|
| `search` | `string` | a settled term, or `''` when cleared |
| `dismissed` | `void` | the user asked to leave a field that was already empty |

```html
@if (!screen.isNarrow()) {
  <app-search-field [term]="selection().term ?? ''" (search)="search.emit($event)" />
}
```

The field emits; it never navigates. The shell owns the navigation, so both
mount points — the desktop sidebar and the mobile header bar — drive one code
path.

In the desktop sidebar the field sits in the search card as `<app-search-field class="inset">`: the card draws the rim, and the field becomes a `--surface-0` well 38px tall with its left corners following the card's (#1749).

`search` emits the settled term after the debounce, but a **clear** emits `''`
at once: waiting 300 ms to leave a search makes the view feel stuck. The
component tracks the term already in effect and moves it from both directions —
its own emissions and the `term` input — so a term retyped after clearing, or
after a Back navigation, still emits.

`dismissed` exists so the mobile bar can implement the two-step exit (first
step clears, second closes) without the field knowing a bar exists, or the bar
reading the field's text. Both steps run through one control and one contract:
`Escape`, and the field's own trailing ✕.

`dismissible` decides only whether that ✕ survives an empty field. The mobile
bar sets it, because on a phone the ✕ is the whole exit — there is no `Escape`
key, and the bar deliberately carries no close button of its own beside the
field's (#550: two ✕ side by side, one clearing and one closing, read as one
control that behaved differently depending on where it was tapped). The
sidebar's permanent mount leaves it `false`: there, the ✕ appears with text and
goes with it. On a coarse pointer the button's hit box grows to `--tap-target`
while the glyph stays put.

A bare `/` anywhere in the document focuses the field. It is ignored when a
modifier is held or when the event target is an `input`, `textarea` or
`contenteditable` — including this field's own input, where `/` types a slash.

**Not for:** any other filter. It is bound to the entry search's rules.

---

### `<app-marked-text>`

Renders text with the matched search terms wrapped in `<mark>`.

| Input | Type | Default |
|---|---|---|
| `text` | `string` | `''` |
| `terms` | `string[]` | `[]` |

```html
<app-marked-text [text]="entry().title" [terms]="terms()" />
```

The marking is a pure function returning text segments, which the template
renders as elements. **There is no `innerHTML` anywhere in this path, and there
must never be** — an entry title is feed-supplied, so a title containing markup
has to render as visible text.

Used on the entry row's title and lead paragraph, which are the two fields the
search actually matches on. Do not mark the feed name or the date; they were
never searched, and a mark there claims a match that does not exist.

Matching is case-insensitive and preserves the original casing. Regular
expression metacharacters in a term match literally, so a search for `c++`
behaves.

**Not for:** general emphasis. Use it only where a term genuinely matched.

---

### `<app-marquee>`

One line of text that, when wider than its box, loops sideways without end
(24 px/s, holding the start still briefly each lap): a hidden second copy follows
the first, so the end runs into the start. The line is an `<ng-template>` child,
since projected content renders only once. A line that fits stays put; under
`prefers-reduced-motion` it ends in an ellipsis instead. No inputs.

```html
<app-marquee class="title">
  <ng-template>{{ chapter.title }} · {{ track.title }}</ng-template>
</app-marquee>
```

Used for the audio player bar's title line (#1829). **Not for:** list rows,
where many moving lines at once compete for attention; truncate those.

---

### `<app-entry-meta>`

The line a magazine card or a list row ends on: `app-entry-pills` on the left,
`app-entry-actions` right-aligned against them. Six of the seven magazine
blocks use it — hero, wide, split, thumb, kicker, quote — and so does the list
layout's `entry-row`.

| Input | Type | Default |
|---|---|---|
| `entry` | `EntryDto` (required) | — |
| `tags` | `SubscriptionTagDto[]` | `[]` |
| `imageShown` | `boolean` | `false` — whether the host draws the entry's image, which decides the Short pill |
| `size` | `'sm' \| 'md'` | `'sm'` — passed to `app-entry-actions`; the list row uses `md` |

```html
<app-entry-meta
  [entry]="entry()"
  [tags]="tags()"
  [imageShown]="showImage()"
/>
```

Three pieces of its geometry are load-bearing, and are why this is a component
rather than a row assembled per host. `align-items: flex-end` keeps the icons
level with the **last** line of a wrapping pill list, so the card's bottom
edge stays the reference. And every image-beside-text block (`split`, `thumb`)
places the row as a sibling *below* its image+text row rather than inside the
text column: beside an image the column cannot hold the audio action cluster
and the pills' names together (#1720), and the row's actions should align to
the card's edge, not the column's (#486). The list layout's `entry-row` ends on
the same component, below its image+text row, with `size="md"` actions (#1745).
The pills carry a floor of `min(100%, 8rem)` and the row wraps: when the action
cluster leaves less than that, the actions drop to their own line, right-aligned,
rather than shrinking the pill names away. An empty pill list has no floor.

**Not for:** `entry-compact`, which projects `app-entry-actions` directly onto
its kicker line instead of using this row — see the exception in
[§6](#6-deliberate-exceptions).

### `<app-entry-actions>`

The three per-entry actions — favorite, keep, mark read — as one control
cluster. `app-entry-meta` renders it inline; `entry-compact` uses it directly,
projected into the kicker line (see the exception in
[§6](#6-deliberate-exceptions)); the reader view renders it at `size="md"` as
the article's action row. It renders with no wrapper element — `:host`
is `inline-flex` — because a wrapper would give the HTML parser a block-level
child to choke on where the component lands inside a `<p>`.

| Input | Type | Default |
|---|---|---|
| `entry` | `EntryDto` (required) | — |
| `size` | `'sm' \| 'md'` | `'sm'` |

| Output | Type | Fires |
|---|---|---|
| `favorite` | `output<EntryDto>` | the favorite button is pressed |
| `keep` | `output<EntryDto>` | the keep button is pressed |
| `read` | `output<EntryDto>` | the mark-read button is pressed |

```html
<app-entry-actions
  [entry]="entry()"
  (favorite)="favorite.emit($event)"
  (keep)="keep.emit($event)"
  (read)="read.emit($event)"
/>
```

Clicks stop propagating: the card around the buttons is itself clickable, and
would otherwise open the entry instead of toggling the flag. Each button is a
`button[appFlagToggle]`: muted while off, accent while on, with the look owned
by `frontend/src/styles/_flag-toggle.scss`. Favourite and keep also fill their
glyph while on; the tick has no hollow form. The reader view's article row is
this component at `size="md"`, and its toolbar Keep/Favorite reuse the same
directive, so every keep and favourite toggle in the app looks the same.

On a coarse pointer the buttons grow to `--tap-target` height on negative
margins alone, so a card never gets taller on a phone, and the gap between
them widens to exactly twice a button's touch padding, so the enlarged hit
boxes tile edge to edge — a tap on the star must never land on keep.

---

### `<app-thread-line>`, `<app-thread-line-post>`, `<app-thread-count>`

An author's self-replies folded into one entry (#1668), drawn as dots on a thin
thread line. `<app-thread-line>` is the list (`role="list"`); each
`<app-thread-line-post>` (`role="listitem"`) is a two-column grid, a
`--space-3` rail and the projected content. The rail holds a `--space-2` dot,
`--text-muted`, the first post's `--text-secondary`, and, on every post but the
last, a 2px `--border-strong` line down to the next dot. `<app-thread-count>` is
the `n/N` pill: `--fs-xs`, `--text-secondary` on a `--border` fill,
`--radius-sm`, `aria-hidden`, since the list roles already announce the position.

| Component | Input | Type |
|---|---|---|
| `<app-thread-line>`, `<app-thread-line-post>` | none: content is projected | — |
| `<app-thread-count>` | `position` | `number` (required) |
| | `total` | `number` (required) |

```html
<app-thread-line>
  <app-thread-line-post>
    <p class="reply">{{ reply.text }} <app-thread-count [position]="2" [total]="3" /></p>
    <app-link-card [card]="card" layout="block" />
  </app-thread-line-post>
</app-thread-line>
```

The pill sits inline after a text post's last word, its gap owned by the
caller; a post with only a card shows it alone, above the card. The source line
stays once above the thread and the meta row once below it; a reply's card sits
under that reply.

Used by: the magazine thread block, rows, the article.

**Not for:** replies by other people. Those are the comments panel, threaded by
indent ([§5](#5-magazine-blocks)).

### `<app-scrubber>`

The seek bar the mini player bar and the big player share (#1810): a native
`<input type="range">` for keyboard and screen-reader seeking, the played and
cached fill drawn on its track (accent, `--text-muted`, `--border`), and a
decorative `aria-hidden` layer of chapter marks over it. A mark is a notch:
`--space-0` wide, the bar's height, in `--surface-1`, so it reads as a gap in the
bar whatever fills it. The first chapter's mark at 0 is omitted. Marks and fill
are drawn in the thumb's travel, `thumb / 2 + (width - thumb) * fraction`, so the
thumb's centre sits on its notch and at the end of the played fill; the range
resets the global input padding and border so its track spans the full width.

| Input / output | Type |
|---|---|
| `position`, `duration`, `buffered` | `number` (required), seconds |
| `chapters` | `readonly ChapterDto[]` (default `[]`) |
| `(seek)` | `number`, the chosen second |
| `(dragging)` | `boolean`, while the pointer holds the bar |

It labels itself (`reader.audioPlayer.seek`). Its size is the host's to set
through custom properties, with the mini bar's values as defaults:
`--scrubber-bar` (track height, `--space-1`), `--scrubber-thumb` (`--space-3`;
`0px` for none, with a unit: it enters `calc`), `--scrubber-tap` (input height, `--space-4`) and
`--scrubber-surface` (the notch colour, `--surface-1`: the surface the bar lies
on). The big player sets a `--space-2` bar, no thumb and a `--tap-target` input,
and thickens the bar while dragging.

```html
<app-scrubber
  [position]="player.position()"
  [duration]="player.duration()"
  [buffered]="player.buffered()"
  [chapters]="player.chapters()"
  (seek)="player.seek($event)"
/>
```

Used by: the audio player bar, the big player.

### `<app-audio-chapters>`

The chapter rows of the article's list (#1808, #1814) as a component (#1810). It
reads nothing from the player: `chapters` (required) and `playhead`
(`ChapterPlayhead | null`: the chapter playback is in and its progress, from
`AudioPlayerService.chapterPlayhead`) come in, a tapped row's index goes out as
`(pick)`. The current row carries `aria-current="true"`, fills to
`--chapter-progress` in `--accent-soft` and ends in its time left behind a
`schedule` icon. The row rules live in `reader/chapters/_chapter-rows.scss`, a
mixin the article's `article-chapters.scss` and this component both include, so
the two lists cannot drift apart; the rows inherit their font size from the list's
host and take their inline inset from `--chapter-row-inset` (`--space-3`), which
the queue zeroes for its nested rows and sets to `--fs-xs`.

Used by: the big player's chapter list, the queue's expanded rows.

**Not for:** the article body. Its list is decorated into the article's HTML
by `decorators/reader-chapters.ts`, which drives a `ChapterPlayer`.

---

## 3. Conventions

### Density

Every list, rail and picker row derives its padding from one of the two token
pairs in [§1](#row-density) — never from raw `--space-*`, and never from a
literal. If a new surface genuinely fits neither, that is a design conversation,
not a third pair invented in a component stylesheet.

**Touch density is a pointer decision, applied locally.** The reader sidebar
raises its rows and hit zones to `--tap-target` (44px) under
`@media (pointer: coarse)` in `_sidebar-row.scss` (#185, #1749). The compact density
tokens themselves never grow — they are shared by the discover rails and the
admin catalog, which stay compact. A new touch surface repeats the pattern
locally: key on `pointer: coarse` (capability), never on viewport width
(presentation), and size hit areas with `--tap-target`.

### Sidebar cards (#1749)

The sidebar is a column of cards on `--surface-0`: the search card (desktop) or the "Feeds" header with refresh, add feed and close (phone), then smart views, Saved searches, Tags, Feeds, one per network, and the footer. A card is `glass-rim.filled(var(--surface-1), var(--border))`, `--radius-lg`, `--sidebar-card-pad`; cards stand `--space-2` apart (`--space-3` on a coarse pointer). Only `.sidebar-body` scrolls: the search card and the Organise head sit above it, outside the scroller (#1332, #1798), while the phone's "Feeds" header scrolls away with the body. The footer card rides at the end of the body (`margin-top: auto`) and scrolls with it; its layout and theme row wraps when 44px touch targets do not fit on one line. The mixins live in `reader/shell/sidebar/_sidebar-row.scss`.

**A row** is `row-geometry`: a fixed-height flex line, `0 var(--space-2) 0 var(--row-inset)`, hover `--surface-0`, active `--accent-soft` with accent text, glyph and count. Its height, left inset and type size come from the surface it sits on, through the inherited `--row-h`, `--row-inset` and `--row-fs`, so a row component never reaches into its parent and a parent never pierces a row.

**The right edge is columns, per surface.** Every row and head ends count · ⋯ (28px) · chevron (28px) · 8px, except the smart views (All items, Favorites, Kept, Recently read, For you): they have no ⋯ or chevron, so their count sits at the card's right edge, behind only the row's 8px padding. A row without a ⋯ puts an empty `.slot` there, and `.slot` and the ⋯ host both carry `margin-right: calc(var(--space-3) * -1)`, so the count sits in the same place either way. A row without a chevron ends in an empty `.trail`. On a coarse pointer the desktop row ⋯ and `.slot` go and the chevron column is 44px. Two ⋯ stay: the network (Bluesky, Mastodon) head's, the only phone entry to account management, and organise mode's, whose rows all carry one.

**Heads** (`section-head`) are 13px/600 `--text-secondary`, sentence case, and their title still opens the combined list (#1735). When that list is showing, the whole head row is `.current`: the full-row `--accent-soft` fill with accent title and count. While organising the heads stay neutral.

**Nesting** (two levels below the top, #1736, #1790). An open tag is a grey group (`.group.open`, `--surface-sunken`). Each child tag sits in its own `--surface-1` box, and a grandchild's box is a `.tag-child` inside that one, on the same background and marked only by the compounded margin; the parent's own feeds follow the boxes. Rows inside a box start at the box's edge again, so every chevron keeps the same gap to its own surface. The drop lists stay top-level siblings: the group and the box are plain divs, and the heads and feed lists are rendered through `#tagHead` / `#tagFeeds` templates.

**Phone.** The drawer is a full-height sheet over the app bar, flush left, `--drawer-shadow` over `--drawer-scrim`. Organise mode swaps the header for "Organise" and **Done**, pinned above the body; once the body leaves the top the head takes `.collapsed` (base-size title, sub-row-height Done). It leaves Saved searches out, and lays every row out as handle (40px) · body · ⋯ · chevron, all 44px. Its tag sheet offers Edit tag, Move up, Move down and Delete tag; the moves reorder within the sibling set the drag uses and disable at its ends.

### Hidden-feed favicon

A feed hidden from All items, For you or saved searches (#688) shows it on its favicon, not with a trailing glyph. Only the feed's own exclusions mark it: a network-level saved-search exclusion is not repeated on each of the network's rows. It marks the network (Bluesky, Mastodon) head's logo instead, with the same treatment and title (`row.hidden-feed-mark` in `_sidebar-row.scss` styles both). The favicon greys and fades (`--hidden-favicon-opacity`, `grayscale(1)`), and a small eye badge sits on its bottom-right corner:

```html
<span class="favicon-slot" [class.hidden-feed]="excluded()">
  <app-favicon [url]="subscription().faviconUrl" [avatarUrl]="avatarUrl()" />
  @if (excluded()) {
    <span class="feed-exclusion-marker" role="img" [attr.aria-label]="markerTitle()" [title]="markerTitle()">
      <app-icon name="visibility_off" size="text" />
    </span>
  }
</span>
```

The rules a future marker should reuse:

- **It marks the lead, not the line.** The badge rides the favicon, so the label and the count keep their places whether or not it shows.
- **The badge is a chip of its own surface:** `--surface-1` with a 1px `--border` ring, so it reads on the card, the grey group and the inner box alike.
- **Every other favicon renders at full strength.** Only the hidden state fades.
- **A `title` and an `aria-label` name the exact condition** (`exclusionTitle()`), never a generic "hidden".

### Text-size control

`<app-text-size-control>` (local to the sidebar, #1382) sits directly above the
brightness control and shares its shape (`segmented.stepper-bar`): `text_decrease`,
a bar whose fill marks the step and whose label is the percentage, `text_increase`. Steps are 90–150 % in tens,
default 100, per device and theme-independent (`sfr.textSize`), never per account.

The setting is one root property, `--text-scale`. A reading container takes the
global class `text-scaled`, which multiplies the `--fs-*` tokens and the inherited
font size by it; today that is the list `.rows`, `.empty-wrap`, `.list-error`, the
article `<article>` and its `.placeholder`, and the duplicates popover (a CDK overlay sits
outside the page's containers, so it takes the class itself). Inside one, every `<button>` and
`app-entry-pills` keep the fixed tokens and the inherited size; any class rule
still overrides them. A button typeset as running text (TOC
entries, inline "Retry", "Also in") opts back in with `reads-as-text`. Icons,
spacing, images and widths are px tokens and never scale. The list header and the
reader toolbar sit outside every `text-scaled` container, which is why their texts
stay fixed. A new reading surface takes `text-scaled`. A control inside one stays fixed
if it sizes its text with an `--fs-*` token or has no font-size rule of its own; a class
rule like `font: inherit` with no token size outranks the reset and scales the control
with the text (that is how the text-like `.note-link` and `.also-entry` scale).

### Brightness control

`<app-brightness-control>` (local to the sidebar, #832) is a moon
(`dark_mode`), a solid progress bar and a sun (`light_mode`) in the
segmented frame it shares with the view-controls groups
(`theme/_segmented.scss`). The bar is one full-height fill over a track the
same surface as the glyph buttons; the soft accent tint is the indicator. It
fills with brightness — empty at the darkest step, full at the brightest — so
the light default sits at the full end and the dark default at the middle.
Clicking the bar resets to the default; the moon disables at the floor and the
sun at the top. A visually hidden `<output aria-live="polite">` reads the value.
Steps are per device and per theme (`sfr.brightness.light` / `.dark`), never per
account.

### Sticky and scroll

**Stickiness lives on the flex-child host, never on an inner wrapper.** A sticky
element inside a content-height host has no room to stick: the host is exactly as
tall as its content, so the sticky element hits the bottom of its containing
block immediately and scrolls away with its parent. This is exactly the
`category-rail` bug #126 reported. The fix is `position: sticky` on `:host`, with
`align-self: flex-start` and a `max-height` so the rail can scroll internally
when the categories outrun it:

```scss
:host {
  position: sticky;
  top: 0;
  display: block;
  align-self: flex-start;
  max-height: 100cqh; /* NOT 100% -- see below */
  overflow-y: auto;
  overscroll-behavior: contain;
}
```

**A percentage height inside an overlay panel silently resolves to `auto`.** The
panel is sized by `max-height` alone, and a `max-height` does not make a box's
height definite — so every `height: 100%` / `max-height: 100%` below it computes
to `auto` with no warning, no lint and no visible breakage until the content
outgrows the screen. The rail carried `max-height: 100%` for two years and it
never once applied; the cap only appeared to work because the categories
happened to fit (#561). The same fact is recorded from the other direction in
the `fill-mobile` note in `overlay-panel.component.scss`.

A descendant that must measure the panel body therefore needs the consumer to
opt in to **both** `--panel-h` (gives the panel a definite height) and
`--panel-body-container: size` (makes the body a size container), and then to
measure with `cqh` rather than `%`. Only discover does this today.

**A horizontal strip is not a navigation list once it outgrows a screen.** The
picker's category chips are the only way to reach a category on a phone — the
rail is hidden below the breakpoint — and as one nowrap strip 23 categories ran
to **eight screenfuls** of sideways swiping, with nothing on screen to say more
existed. A category sitting 13th was reported as missing from the live instance
(#561). Chips that serve as navigation wrap into rows and scroll down, capped so
a cut-off row does the "there is more" signalling a cut-off strip cannot.

**A sticky box's cap and its travel want opposite things from its parent.** Cap
it with a percentage and the parent must be one screenful — which is also the
end of the sticky range, so the box scrolls away the moment it is short enough
to fit. Give the parent its content height instead and the travel is right but
the cap is gone. `cqh` is what breaks the tie: it measures the scrollport while
leaving the containing block tall. Both directions are regression-tested in
`frontend/e2e/discover-rail-scroll.spec.ts`, one test each — a fix for either
one alone re-breaks the other.

**A sticky box only travels inside its containing block's _content_ box, so
padding on the parent strands it early.** The article's progress bar is sticky to
the bottom of the reading pane, and the reading tail used to add half a viewport
of `padding-bottom` to that same parent: the bar therefore ran out of containing
block half a screen above the true bottom and scrolled out of sight over the
tail, exactly where the reader was finishing the article (#238). The fix is to
put the space in flow instead — the tail moved onto the `<article>`, which leaves
the parent's height identical and gives the sticky child the whole range.

**A scroll cue on a pane is sticky, never fixed.** `position: fixed` resolves
against the viewport, so a bar meant for one pane runs on under its neighbours on
the split layout — and any transformed ancestor re-anchors it to that ancestor
instead (the trap #100 documents for the back-to-top button, and the reading pane
carries a transform through its return gestures). Sticky is scoped to the pane by
construction and immune to both.

**An internal scroller gets `overscroll-behavior: contain` only where chaining
would give the wrong answer.** Reaching the end of the category rail must not
hand the wheel to the panel body behind it — the sections would scroll and the
rail would appear to jump to a category the reader never picked. The panel body
contains for the same reason: the reader behind the dialog must not move. In use
by the overlay panel body, the category rail, the category chips and the icon
picker grid.

**It does not belong on a nested list whose only outer scroller is the dialog
body.** The edit-subscription tag list capped itself at 220px and contained its
overscroll; on a short phone the dialog body was still left needing a few pixels
of its own scroll, and containment swallowed exactly that remainder. Because the
list fills most of the dialog, nearly every touch starts inside it — so the last
tag was unreachable for good (#561). Chaining is the only fix: the cap cannot
adapt, because sizing the list against "what is left" would need the dialog to
have a definite height, which it does not. Stopping the gesture at the dialog is
already the panel body's job, and it still does it. The add-feed dialog's tag
list has the same shape with a 160px cap; it has not been reported, but it is
the same trap.

**Chrome a tap must reach stays outside the scroller.** On iOS a tap inside a
scroller that is still coasting only stops the scroll; no click fires. The list's
header always sat beside `.rows`, so its icons worked mid-fling, while the
article's toolbar, nameplate and back-to-top button sat inside the article's own
scroller and swallowed the tap (#1332). Float such chrome over the scroller and
reserve its height, as the list and the article both do now, or stack it as a
`flex: none` sibling above an inner scroller, as the sidebar does (#1798).

**Content beneath a floating bar offsets by the measured height plus `--bar-gap`,
never by a literal:**

```scss
padding-top: calc(var(--app-bar-h, var(--bar-h)) + var(--list-bar-h) + var(--bar-gap));
```

One value (`--bar-gap`) tunes the gap everywhere.

### Overlay

Every interrupt surface renders inside `<app-overlay-panel>`. Every
`dialog.open()` passes `panelClass: 'app-dialog'` — the class lands on the CDK's
`.cdk-overlay-pane` and is what lets `styles.scss` size the pane to the viewport
on a phone, which the full-screen panel needs to reach the screen at all.
Per-consumer width and max-height come from `--panel-w` / `--panel-max-h` set on
the consumer's host.

The CDK's structural overlay CSS is imported by `src/styles.scss`, not by
`angular.json`, so every build configuration gets it. Without it a dialog renders
as a plain block appended after the 100vh reader shell — a full viewport below
the fold (#85).

### Audio player chapters (#1810)

Chapters are view-less data on the track (`AudioTrack.chapters`), saved and
restored with the playlist; every surface reads the current one from
`AudioPlayerService`. Where they show:

- **Mini bar:** the title line leads with the chapter, the track title after a
  middle dot in `--text-muted`. Chevron buttons flank the transport while the
  track has chapters; previous/next stay track steps, as they do on the lock
  screen. Below `bp.$bp-sm` the bar is a grid of three rows beside the artwork
  (`--space-7 + --space-5` square): title with the playlist and close buttons,
  the full transport, the scrubber.
- **Big player:** a chapter strip under the transport (previous chapter, the
  current title over "Chapter n of N" and its time left, next chapter); the
  chapter list under the extras, toggled by the strip's title and its own
  heading. It opens by itself only for the only queued track; a longer queue
  starts with it closed (#1821).
- **Queue:** a chaptered row shows its count after the duration and a chevron
  that expands `<app-audio-chapters class="nested">` inside the same draggable
  row; every row, the playing one too, starts closed, so the chapters never
  show twice on the phone sheet. The nested list is never a `cdkDropList`
  ([Gotchas](../CLAUDE.md)).
- **Lock screen:** Media Session metadata puts the chapter in the title, the
  track as the artist and the feed as the album.

### Player bar under embeds (#1815)

One `.reader-player-bar` sits under a landscape video or a SoundCloud box,
shared by the decorators that fill it. A YouTube video or a SoundCloud track
leads with **Listen** (`play_circle`), which sends the embed's sound to the
audio player and pauses the visible embed, then the queue toggle
(`aria-pressed`, "Add to playlist" / "In playlist"); a landscape video ends
with the cinema toggle (`margin-inline-start: auto`). The bar shows at every
width; only the cinema toggle hides below `bp.$container-reader-cinema`.

The sound plays in a provider frame inside `.hidden-playback`
(`src/styles/_hidden-playback.scss`), the one sanctioned invisible iframe: fixed
in the bottom end corner at `opacity: 0`, never `display: none` or a zero size,
because a browser throttles or refuses media in a frame that does not render.

### Forms

`<app-field>` wraps a labelled control; the native control keeps its own
`formControlName` and its own attributes. Global control styling — border,
radius, height, padding, focus, disabled — lives in `styles/_controls.scss`,
because Angular's `ViewEncapsulation` does not style projected content, and
because a bare `<input>` outside a field should still look like the rest of the
app.

### Settings (#541)

**Save by control type.** A toggle or a select saves on change — an instant
persist confirmed by the one global toast. A text or number field is
dirty-tracked behind the `<app-settings-save-bar>`'s explicit Save, with the
"unsaved changes" indicator until the user saves. This is the settled save
model for settings; do not mix the two on one control.

**Feature sections compose the primitives, they never restyle.** The Grouped
look lives in the `shared/settings/` primitives and the tokens. A feature
settings section composes `<app-settings-group>` + `<app-settings-row>` +
`<app-disclosure appearance="drill-in">` + `<app-settings-save-bar>` +
`<app-info-tip>`. It does not re-implement the look in its own `.scss`, which
holds only layout glue. If a section needs a new visual pattern, that pattern
becomes (or extends) a `shared/settings/` primitive first.

**Per-field validation (#845).** A settings row surfaces its own error via
`<app-settings-row [error]="…">`, an already-translated string, mirroring
`<app-field>`'s error slot. The control carries
`[attr.aria-invalid]="isFieldInvalid(field)"`, which picks up the shared danger
border from `styles/_controls.scss`. One `fieldError(field)` method merges
client-side checks with the backend 422 `errors` map — keyed by the DTO
property name — into a single code path per field; an edit to that field
clears its error. See `frontend/src/app/settings/admin/mail/mail-section.component.ts`
for the reference implementation. Adopt it when a form is next touched, not as
a sweep.

---

## 4. Enforcement and escape hatches

`frontend/.stylelintrc.json`, run by `npm run check` (which is `ng lint` +
`prettier --check` + `stylelint "src/**/*.scss"` + `jest`).

| Rule | Effect |
|---|---|
| `color-no-hex` | no hex literals — colours come from tokens |
| `declaration-property-unit-allowed-list` | `padding*`, `margin*`, `gap`/`row-gap`/`column-gap`, `font-size`, `border-radius` accept only `%`/`em`/`rem` — i.e. a raw `px` is a failure, and a `var(--space-*)` is not a unit at all, so tokens pass |
| ↳ same rule, sizing props | `width`/`height`/`min-*`/`max-*` additionally accept `ch`/`vw`/`vh`/`dvw`/`dvh`/`cqi`/`fr` (`cqi` sizes a box from its container, as a portrait cover in a split does) |
| `media-feature-name-unit-allowed-list` | `@media (width …)` accepts **no** unit — forces `bp.$bp-*` |

**Exempt** (all three rules disabled): `src/app/theme/**/*.scss`,
`src/styles/**/*.scss`, `src/styles.scss`. Those files *define* the tokens and
the global chrome, so they are where the literals belong.

### The escape hatch

A tuned component dimension that is genuinely not a spacing value — a panel
measure, a rail width, a menu min-width — disables the rule for one line, with a
reason:

```scss
/* stylelint-disable-next-line declaration-property-unit-allowed-list --
   tuned component dimension, not a spacing value. */
width: 220px;
```

The reason after `--` is mandatory in practice: without it the next reader
cannot tell a considered exception from an unmigrated literal.

### Standing rule: styles go in a `.scss` file

**Stylelint cannot parse `.ts`.** No `customSyntax` is installed, so it throws on
the first line of TypeScript and never reaches an inline `styles:` block:

```
src/app/shared/icon-picker/icon-picker.component.ts
  3:3  ✖  Unknown word booleanAttribute  CssSyntaxError
```

Therefore **component styles must live in a sibling `.scss` file referenced by
`styleUrl`**, or they are silently unenforced — no hex check, no `px` check, no
breakpoint check. All 48 styled components in `src/app` do this today; keep it
that way.

And: **never pass a `.ts` glob to stylelint.** It does not "lint the inline
styles too" — it fails the whole run with a `CssSyntaxError` per file. The
`npm run stylelint` glob is `src/**/*.scss` and should stay that way.

(Inline `template:` is fine — stylelint has no opinion on templates, and
`<app-icon-picker>` keeps one.)

---

## 5. Magazine blocks

The reader's magazine list (`frontend/src/app/reader/list/magazine/`) plans entries
onto eleven single-entry block types and a source digest, introduced by #148 to replace a layout that had
collapsed to two-thirds compact rows. `magazine-block.ts`'s `BLOCK_HEIGHT`
holds the measured height each contributes to the planner's per-page budget;
`magazine-slot-fit.ts`'s `fits()` decides whether a given entry may fill a
slot of that kind. Heights are measured at a 390px viewport width and are
relative units for the budget, not a layout guarantee.

| Block | Height | Image | Fills when |
|---|---|---|---|
| **Hero** | 463px | full-width, adaptive `aspect-ratio` from the persisted dimensions (fallback 16/9) | image ≥ 500px wide, or width unknown but `imageUrl` is persisted, and the cover is not portrait |
| **Link** (#1502) | 463px | the shared link's card, under the title and dek; the card states every chrome block shares | a link share (`sharesLink`: `share` is `link`) with its card, or one still being completed — pinned, whatever its slot |
| **Media** (#1522) | 463px | under the title and dek, with the card states every chrome block shares (a post's text clamps at 6 lines), the shared media uncropped and centred in its card: one item at its own aspect ratio, height-capped by `--media-share-max-h`; 2–4 in a 16:9 grid; a video plays inline through hls.js. A YouTube Short's cover is cropped to the picture's `imageAspectRatio`; its play button loads the allow-listed nocookie player in place, which goes back to the cover when an entry opens, the card scrolls away or the block is destroyed (`media-pause`) | a media share (`sharesMedia`: `share` is `media`) with at least one image or video — pinned like Link, whatever its slot |
| **Thread** (#1668) | per entry (`threadHeight`) | the root and its replies on `<app-thread-line>`, counted `n/N`; the root keeps what its own share shows (a media or quote share's media through `<app-post-media>`, a quote's `<app-quoted-post layout="block">`, a link share's card or its placeholder), and each reply's media and card sit under that reply | an entry with its author's self-replies folded in (`sharesThread`: `thread` is not empty) — pinned ahead of Link |
| **Quoting** (#1612) | per entry (`quotingHeight`) | the post's own media through `<app-post-media>`, then the quoted post nested in `<app-quoted-post layout="block">`: avatar, name, @handle, narrow time; the quoted text clamps at 8 lines, the post's own at 6; the quoted media in the media share's grid, a video playable in place. Clicking the video plays it, the rest of the box opens the quoted post, the rest of the card the entry | a quote share (`sharesQuotedPost`: `share` is `quote` and `quote` is set) — pinned like Link |
| **Wide** | 321px | full-width band at 3:1 | image ≥ 400px wide, or width unknown but `imageUrl` is persisted, and the cover is not portrait |
| **Quote** | 180px | suppressed — first sentence set in `--font-voice` instead | snippet text ≥ 300 characters |
| **Split** | 150px | side image at 38% of the column (148px mobile / 258px desktop) | image ≥ 300px wide, or width unknown but `imageUrl` is persisted; a portrait cover keeps its shape (never narrower than 9:16), as tall as the 3:2 side box |
| **Kicker** | 140px | none — oversized title only | the entry has a summary to show as a dek |
| **Thumb** | 90px | fixed 88px box, `aspect-ratio: 4 / 3` | any persisted image; a portrait cover keeps its shape (never narrower than 9:16), 66px tall |
| **Compact** | 66px | none | always |
| **Group** (source digest) | ~300px (not in `BLOCK_HEIGHT` — it consumes entries directly, not a template slot) | none — each row inside is a `<app-entry-compact>` | a same-source run of ≥ 3 entries whose source holds under 40% of the loaded entries |

**A cover's orientation is the shape of the picture it shows** (#1466): the API's
`imageAspectRatio` when the image file letterboxes the picture (a YouTube Short's 4:3
thumbnail holds a 9:16 cover between bars), else the declared width / height. Below
1 / 1.05 it is portrait; unknown dimensions are not.

**"Width unknown" is not the same treatment everywhere.** `fits()` reads the
persisted `imageWidth` and treats `0` (unknown) differently per kind. The three
large image blocks — `hero`, `wide` and `split` — trust an unknown width only
when `imageUrl` itself is a persisted field, because an inline `<img>` with
neither a persisted URL nor a known width is, in practice, a ~148px archive
thumbnail: exactly what used to produce heroes and bands with no real picture.
`thumb` is the exception — it accepts any image regardless of width, since its
box is fixed at 88px, so even that miniature thumbnail fills it cleanly, which
is precisely why it is the demotion target for the larger image blocks.
A known width is the wider of `imageWidth` and the widest served rendition
(`entryImage()`), the height scaled to keep the lead image's aspect ratio.

An entry that cannot fill its planned slot demotes transitively:
`hero → wide → split → thumb → compact`, and `quote → kicker → compact` —
never one step, since demoting a hero straight to `wide` in an image-less
view would still leave an image block with no image.

The reverse holds too: **an entry with a picture is never left in a text block**
(#1463). A `quote`, `kicker` or `compact` slot that receives one promotes to the
tallest image block the entry fills without outgrowing the slot — `quote → split`
(or `thumb`), `kicker → thumb`, and `compact → thumb`. What counts as a picture
depends on the family: the image family counts any usable image (`thumb`), the text
family only one that fills a `split`. Wire services ship a miniature with every
entry, so promoting those would turn the text family's pull-quotes into a wall of
thumbs.

**Each image block states its rendered width as `sizes`.** An entry with a
rendition ladder (`imageRenditions`, #1330) gets a `srcset` through
`RenditionsDirective` (`reader/list/renditions.directive.ts`), and the browser
loads the smallest file that covers the box. The values are upper bounds read
off the CSS above, and live in `reader/list/rendition-sizes.ts`; a block whose width changes changes its value too:

| Block | `sizes` | Read from |
|---|---|---|
| Hero, Wide | `(max-width: 728px) calc(100vw - 24px), 680px` | a full-width image in the `--magazine-measure` column, inset `--space-3` (boxed) or `--space-5` (airy) a side |
| Split | `(max-width: 728px) calc(38vw - 18px), 259px` | 38% of that card's content box |
| Thumb, list row | `132px` | the 88×66 cover box, up to a 2:1 picture |

A failing rendition drops the `srcset` (`fallBackToSrc()`) so the browser retries the
plain `src`; only a failing `src` hides the image.

**A link card is one component** (#1502). `<app-link-card>` (`reader/link-card/`) renders a
`LinkCardDto` in two layouts: `article`, under a post in the reader, and `block`, in the
magazine's link block, with the site line first and the image at `link-card.$image-ratio`
(1.91:1). `_link-card-layout.scss` holds the frame both share with the `.link-card` that #1499
writes into stored bodies. The chrome is `theme/_link-card.scss`'s `surface`, which the
placeholder of a link share still being completed shares.
A card with `openableInReader` carries an always-visible corner button (`--media-scrim`, `--on-media-scrim`; `--tap-target` on coarse pointers) that opens the page in the reader (#1664); comment cards leave it off.

**A quoted post is one component** (#1612). `<app-quoted-post>` (`reader/quoted-post/`) renders a `QuotedPostDto` in
two layouts: `block`, in the magazine's quoting block, clamped; `article`, under the post in the reader, whole, with the
absolute time. The box opens the quoted post through a stretched link; videos and the "Quoting a post by @handle" line
sit above it. Its media and the media block's share `<app-post-media>` (`reader/media/post-media/`), whose grid lives in
`reader/media/_post-media-grid.scss`. A quote share's magazine height is computed per entry
(`magazine-block-height.ts`), calibrated at 390px like `BLOCK_HEIGHT`. A quote share can also carry link cards; the reader
view shows the quoted post first, then the cards.

**The comments panel threads replies by indent** (#1616). Each comment sets `--depth` (capped at 5) and indents by
`--depth × --space-4`, a reply carrying a 1px `--border-strong` thread line; below `bp.$bp-sm` the indent caps at two
steps of `--space-3`. A reply's avatar and initials share `theme/_avatar-tone.scss`'s `tones` with `<app-quoted-post>`.

---

## 6. Deliberate exceptions

Each of these was decided during #126 and looks like an oversight from the
outside. They are not. Read the reason before "fixing" one.

**`admin-catalog`'s feed and category rows are a dense data grid, not a form.**
Most controls there have no visible label and no translation key — they are
identified by column position and `aria-label`. They take the global control
styling from `styles/_controls.scss` but not `<app-field>`, whose stacked label
would roughly triple the row height and destroy the grid. The two genuinely
labelled controls on that page (the OPML import file input and the import-mode
select) *do* use `<app-field>`.

**`discover`'s `.mark` keeps an explicit `--icon-sm` footprint.** It reserves
space for a check icon that is usually absent. Sizing it from its content would
let the card titles reflow the moment a card is picked. The token is doing
layout, not icon sizing, which is why it stays even though no icon renders.

**`_controls.scss` scopes `width: 100%` to `app-field` descendants.** Width is
layout, not chrome, so it belongs to the field rather than to the control. Set
globally it stretched the admin grid's selects to the full row width and pushed
each onto a line of its own.

**`_controls.scss` scopes its `:focus` rule away from checkbox, radio and colour
inputs.** Only controls that actually carry a border may trade the focus outline
for an accent border. The unscoped
`input:focus { border-color: …; outline: none }` has specificity (0,1,1) and
outranks `_base.scss`'s `:focus-visible { outline: … }` at (0,1,0) — which left
checkboxes, radios and colour swatches, none of which have a border to tint, with
no visible keyboard focus at all. That is a WCAG 2.4.7 failure, not a cosmetic
one. Since #1723 every ring is `outline: var(--focus-ring)`, the global rule and
the component ones alike: a 1px accent at 60% that reads as a hint, and `none` on
a coarse pointer (outside forced colours), where focus arrives by tap or by
script, not by keyboard. `cdk-dialog-container` has no ring at all: it is a
wrapper that takes a dialog's initial focus when nothing else can, not a control.

**`accent-color: var(--accent)` is set once on `:root` (#1624).** It is inherited,
so every native checkbox, radio, range and progress takes the theme accent; no
component sets its own.

**`entry-list`'s `- 44px` and `reader-header`'s `top: 44px` are positioning
offsets that coincidentally share the `--tap-target` number.** The first is how
far the pull-to-refresh chip parks above the bars; the second is how far the
account dropdown hangs below the account button's top edge. Neither is a touch
target. Tokenising them would couple two unrelated numbers, so that the day
`--tap-target` moves to 48px the chip would silently park in the wrong place.
Both sites carry a comment saying so.

**`ButtonComponent`'s hover rules are per-variant, not a blanket
`button:hover:not(:disabled)`.** `:not()` carries its argument's specificity, so
a blanket rule scores (0,2,1) and outranks `button.danger` at (0,1,1) — it would
repaint a destructive button's border in the accent colour on hover. Each variant
gets its own hover block instead.

**`entry-thumb.component.scss`'s `.img` fixes an 88px flex-basis.** It is the
thumbnail's tuned box proportion, not a spacing value the density scale
models, hence the `stylelint-disable-next-line
declaration-property-unit-allowed-list` comment at the declaration. Tokenising
it as a `--space-*` step would couple an unrelated visual constant to the
spacing scale, so the day spacing changes, the thumbnail would resize with it
for no reason.

**`entry-split.component.scss`'s `.img` fixes a 38% flex-basis.** It is the
split block's defining proportion — the direct fix for "the medium widget
shows images too small" (#148, was 88px) — not a spacing value either. It
carries no `stylelint-disable` comment because `flex`/`flex-basis` is not one
of the properties `declaration-property-unit-allowed-list` governs at all, but
the reasoning is identical to the thumb box above and is recorded in a plain
comment at the declaration so a future reader does not migrate it to a
`--space-*` token anyway.

**`entry-compact` hangs its actions on the kicker line, not on an
`app-entry-meta` row.** A source group shows up to four compact rows; a meta
row each would add a full line per item and inflate the group, while the
right-hand end of the kicker line is already empty — it only holds the time.
So compact projects `app-entry-actions` into the kicker line and leaves its
tag row pills-only. The projected element must stay inline: the kicker is a
`<p>`, and the HTML parser closes a paragraph at a block-level child, which
drops the icons onto a second line without any error.

**Reader-body decorators use a plain `<details>`, not `<app-disclosure>`.**
They work on the article body rendered via `[innerHTML]` (#903), where an Angular
component cannot mount. So the narration player (`reader-narration.ts`), the
chapter list (`reader-chapters.ts`, #1817) and publisher FAQs (`reader-faq.ts`)
build bare `<details>`, styled in `article-content.component.scss` and
`article-chapters.scss`. A summary that drops the native marker includes
`bare-summary.marker-hidden` (`theme/_bare-summary.scss`), as `<app-disclosure>` does.

---

## 7. Adding a new surface

1. **Reach for the shared component before writing markup.** A labelled control
   is `<app-field>`. A dialog is `<app-overlay-panel>` opened with
   `panelClass: 'app-dialog'`. An action button is `<app-button>`. A tag or
   category is `<app-tag-glyph>`. An icon is `<app-icon>` at a named size.
2. **Derive every spacing value from a token.** No raw `px` for padding, margin,
   gap, font-size or border-radius. If you need a tuned dimension, use the
   documented escape hatch *with a reason*.
3. **`@use '<rel>/theme/breakpoints' as bp;`** and write `bp.$bp-sm/md/lg`. Never
   a literal in a media query — it will not lint, and a custom property will not
   match.
4. **Pick a density pair for rows** — compact or comfortable — and use it for
   every row on the surface.
5. **Put stickiness on the host**, not an inner wrapper, and give any internal
   scroller `overscroll-behavior: contain`.
6. **Styles go in a sibling `.scss` file**, never inline in the `.ts` — inline
   styles are invisible to Stylelint.
7. **Give a full border the glass rim** — `glass-rim.filled(…)` or
   `glass-rim.ring(…)` ([§1 Glass rim](#glass-rim-1402)), never a bare
   `border: 1px solid`; the coverage spec rejects one.
8. **Run `npm run check`** from `frontend/`.

## 8. Adding a new settings section

Compose the `shared/settings/` primitives; do not restyle the look (see
[§3 Settings](#settings-541)).

1. **One `<app-settings-group>` per concern** — an icon, a title, an optional
   caption, and the rows inside it.
2. **One `<app-settings-row>` per control**, with the control projected on the
   right. Add an `<app-info-tip rowTitleTip>` for help on the title.
3. **`<app-disclosure appearance="drill-in">`** for an advanced or Expert
   grouping that expands in place inside the group.
4. **`<app-settings-save-bar>`** for the typed fields (text, number). Toggles
   and selects save on change instead — confirm those with the global toast.
5. **Extend `DraftSettingsService`** (`shared/settings/draft-settings.service.ts`)
   for the section's service: it owns `state`/`busy`/`failure`/`saved`, the
   typed-field `draft` with `dirty` and `pending()`, `load()`, `saveInstant()`
   for the on-change controls and `save()` for the bar. A subclass names its
   `endpoint` and maps server truth to the writable body in `bodyFromState()`.
   Confirm success with `toastOnSaved(svc, key)` (`shared/toast/saved-toast.ts`)
   from the component's constructor. A section with a Test row and typed
   connection fields (proxy, mail) includes the `probe-section` mixin from
   `shared/settings/_probe-section.scss` as its whole stylesheet.

```html
<app-settings-group
  [icon]="'smart_toy'"
  [title]="'settings.ai.forYou.title' | transloco"
>
  <app-settings-row [title]="'settings.ai.enabled.title' | transloco">
    <app-info-tip
      rowTitleTip
      [text]="'settings.ai.enabled.info' | transloco"
      [label]="'settings.ai.enabled.title' | transloco"
    />
    <input type="checkbox" [checked]="enabled()" (change)="toggle()" />
  </app-settings-row>

  <app-disclosure
    appearance="drill-in"
    [label]="'settings.ai.expert.title' | transloco"
  >
    <app-settings-row
      [title]="'settings.ai.temperature.title' | transloco"
      [stackable]="true"
    >
      <input type="number" [value]="temperature()" (input)="onTemperature($event)" />
    </app-settings-row>
  </app-disclosure>
</app-settings-group>

<app-settings-save-bar
  [dirty]="dirty()"
  [saving]="saving()"
  [saveLabel]="'settings.save' | transloco"
  [resetLabel]="'settings.reset' | transloco"
  [unsavedLabel]="'settings.unsaved' | transloco"
  (save)="persist()"
  (reset)="revert()"
/>
```
