# #832 Sidebar brightness control — design

Issue: https://github.com/larspohlmann/simple-feed-reader/issues/832

## What

A brightness stepper in the sidebar foot, in its own row directly under the
Organise switch and above the layout/theme row: a small-sun button, a
seven-cell bar, a big-sun button. It shifts the lightness of the active theme's surfaces,
borders and text, and re-solves the text and status hues so every step holds
the contrast ratio the palette has today. Negative steps also dim images,
video and embeds; positive steps leave media alone.

## Decisions (all settled with the user, 2026-09-03)

### Mechanism — build-time Sass, `data-brightness` on `<html>`

- The Graphite palette becomes two Sass maps (`graphite.$light`, `graphite.$dark`).
- A new `src/app/theme/_brightness.scss` derives one palette per (theme, step).
  `tokens.scss` emits one block per step, selected by
  `:root[data-theme='<theme>'][data-brightness='<step>']`, next to the existing
  `data-theme` blocks. **Step 0 has no block: it is the base block, so it is
  byte-identical to today.**
- Lightness moves in OKLCH, **4 points per step** (`$lightness-per-step: 4%`),
  via `color.adjust(…, $space: oklch)` and `color.to-gamut(…, rgb, clip)`.
  Sass 1.90 (installed) supports both; verified under Jest.
- **Shifted tokens** (moved by the step): `surface-0`, `surface-1`, `surface-2`,
  `surface-read`, `border`, `border-strong`, `accent-soft`, `bg-danger`,
  `bg-success`, `bg-warning`.
- **Solved tokens** (contrast held): `text-primary`, `text-secondary`,
  `text-muted`, `accent`, `danger`, `success`, `warning`. Each one's target is
  **its own step-0 ratio against the weakest of the four surfaces** (WCAG
  2.x ratio). At a step ≠ 0 a binary search over OKLCH lightness, hue and
  chroma kept, finds the lightness nearest the surfaces that still meets the
  target. So text dims with the surfaces on the way down and brightens with
  them on the way up. The saturated hues are solved, not fixed: measured, the
  light accent would fall to 2.8:1 against the canvas at −3 if fixed.
- **Partner rule for the accent:** a solved accent is accepted only if
  `on-accent` keeps `min(4.5, today's ratio)` on it; otherwise the base accent
  is kept for that step. (At light +1 the held-ratio accent would drop white
  text on it from 4.30:1 to 3.96:1.)
- **Fixed tokens:** `on-accent`, `favicon-backdrop`, `panel-shadow`.
- **Easy to change later (Q21):** `$contrast-targets: ()` in `_brightness.scss`
  is a per-token override map, e.g. `(text-muted: 3)`. Empty holds today's
  ratios (muted text is 2.9:1 today and stays so). Overrides apply to steps
  other than 0; step 0 is the palette itself.
- Today's ratios for the record (weakest surface): light primary 13.16,
  secondary 5.87, muted 2.98, accent 3.94; dark primary 8.70, secondary 5.50,
  muted 2.86, accent 5.41.

### Range

- Dark: −3 … +3. Light: **−3 … +1** — light panels are already white
  (OKLCH L 100), so a second plus step would change nothing. The bar always
  draws seven cells; in light mode the two unreachable cells render as
  unavailable and the plus button disables at +1.
- Constants live in `src/app/theme/brightness.ts` (`BRIGHTNESS_MIN = -3`,
  `BRIGHTNESS_MAX = { light: 1, dark: 3 }`, `BRIGHTNESS_CELLS = [-3..3]`) and
  are **mirrored by the no-flash script in `index.html`**, which cannot import
  them.

### Media

- Every step block emits `--media-brightness`: `1` at step ≥ 0 (also in the
  base blocks), `0.94 / 0.88 / 0.82` at −1 / −2 / −3 (6 % per step).
- One global rule in `src/styles/_base.scss`:
  `:root[data-brightness^='-'] :is(img, video, iframe) { filter: brightness(var(--media-brightness)); }`
  The attribute guard keeps `filter` off the default render.

### Storage and boot

- Per device, per theme: `localStorage['sfr.brightness.light']` and
  `['sfr.brightness.dark']`, plain integers. Corrupt or out-of-range values
  clamp; a missing key is 0.
- `ThemeService` gains a public `resolved` signal (`'light' | 'dark'`).
  `BrightnessService` (`src/app/theme/brightness.service.ts`) reads both keys
  once, exposes `step`, `min`, `max`, `set/increase/decrease/reset`, and writes
  `data-brightness` from an `effect`. Under "system" mode the control edits the
  theme resolved right now; when the OS flips, the other theme's step applies.
- The inline no-flash script in `index.html` reads the resolved theme's key,
  clamps it, and sets `data-brightness` before Angular boots, so a reload at
  −3 paints at −3 from the first frame.
- No colour transition on change (the theme switch has none either).
- Applied at the root, so login, settings and admin follow it.

### The control

- `BrightnessControlComponent`, selector `app-brightness-control`, local to
  `src/app/reader/sidebar/` (one use; not a shared component). Styled like the
  view-controls `.seg` groups; **not** `<app-button>` — added to the design
  language's opt-out list.
- Markup: `role="group"` labelled "Brightness"; darker button (small sun,
  Material Symbol `brightness_low`, label "Darker"), bar button (label/title
  "Reset to default", click resets to 0), brighter button (big sun,
  `brightness_high`, label "Brighter"). Buttons disable at the ends.
- Bar: seven `.cell` spans filled from the left up to the current step
  (`cell <= step`): 1 filled at −3, 4 at 0, 7 at +3. The 4th cell carries a
  notch (`::after`) marking today's look. Filled = `--text-secondary`,
  empty = `--border-strong`, unavailable = `--border`.
- Assistive tech: a visually hidden `<output class="sr-only" aria-live="polite">`
  reads "Brightness default" at 0, otherwise "Brightness +2" / "Brightness -2".
- Placement: inside the foot's `@if (!organising())` block, before
  `<app-view-controls>`, with class `brightness`. Foot order becomes
  organise, brightness, controls, trial, meta. Shown on every pointer; hidden
  while organising. No global keyboard shortcut.

### i18n

`reader.brightness.{aria, brighter, darker, reset, readout, readoutDefault}`
in `public/i18n/en.json` and `de.json`: Brightness / Brighter / Darker /
Reset to default / "Brightness {{value}}" / "Brightness default"; Helligkeit /
Heller / Dunkler / Auf Standard zurücksetzen / "Helligkeit {{value}}" /
"Helligkeit Standard".

### Docs

`docs/design-language.md`: §1 Tokens gets a "Brightness steps" subsection
(`data-brightness`, what shifts, what is solved, where the override map is);
the `<app-button>` opt-out list gets the stepper; §3 Conventions gets a
"Brightness control" entry (bar, notch, asymmetric light range).

## Tests

- `src/app/theme/brightness-steps.spec.ts` compiles `tokens.scss` with the
  `sass` package, parses every `[data-theme][data-brightness]` block, and
  asserts: the emitted steps are exactly the agreed ones; every solved token
  meets its step-0 ratio (tolerance 0.02) at every step; every step block
  redefines every shifted and solved token plus `--media-brightness`; the
  canvas moves in the step's direction; `on-accent` keeps `min(4.5, today)`
  on the accent; `--media-brightness` per step; and the two base blocks equal
  today's hex values exactly.
- `brightness.spec.ts` (clamp, key, cells), `theme.service.spec.ts` (resolved
  signal), `brightness.service.spec.ts` (per-theme keys, clamp, attribute,
  theme switch), `brightness-control.component.spec.ts` (labels, fill count,
  disabled ends, reset, readout, light unavailable cells),
  `sidebar-foot.component.spec.ts` (order, hidden while organising).
- Playwright `e2e/brightness.spec.ts`: dark key −3 set before load → `<html>`
  has `data-brightness="-3"` and an `img` computes `filter: brightness(0.82)`;
  light key 3 → clamped to `1` pre-boot.

## Out of scope

Server-side storage, a keyboard shortcut, brightening media, a contrast audit
of today's palette (muted text stays 2.9:1), any change to step 0.
