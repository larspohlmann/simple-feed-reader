# Icon optical centring (#2613)

## Intent

Every Material Symbol carries its ink at a different height in its 1em box
(#2563: `home` +1.06px low at 16px, `chat_bubble` −0.85px high). Boxes are
centred exactly; the ink is not. Shift each glyph so its ink, not its box,
sits on the box centre. Offsets are measured by a script, never by hand, and a
guard keeps the table complete as glyphs are added.

## Goals

- Every `app-icon` gets the correction, standalone buttons included. Placements
  that look wrong afterwards are fixed later, on their own (Lars).
- Adding an icon anywhere in `frontend/src`, or bumping `material-symbols`,
  fails `npm run check` until the measuring script is rerun.

## Non-goals

- Horizontal ink centring (my call: the report is about height only).
- Favicons, avatars and images. The two `vertical-align: super` link icons
  stay; they are a footnote-mark design, not an ink fix. A search found no
  existing per-glyph nudges to remove.
- Names that only arrive at runtime from outside the source (backend data).
  They render with no offset, exactly as today.

## Approaches

- **A. Measure the whole font** (3,894 names × outlined/filled). Never misses a
  glyph; about 20 KB gzipped in the main bundle, or icons jump if loaded lazily.
- **B. Measure the glyphs the source can render (picked).** A few hundred
  entries, a few KB; a missed name falls back to no offset.
- **C. Runtime canvas measurement.** Rejected: waits on font load, measures the
  bounding box rather than the centroid, and can't be pinned in a unit test.

## Design

- **Name scan.** Every string literal in `frontend/src/**/*.ts` and `*.html`
  (specs excluded) that matches `/^[a-z0-9_]+$/` and appears in the font's own
  name list (`node_modules/material-symbols/index.d.ts`). This covers
  `icon-choices.ts`, template `name="…"` and names picked in TypeScript.
  Over-inclusion (a non-icon word like `home`) is harmless. One
  implementation, shared by the script and the guard test.
- **Measuring script**, `npm run icons:measure`. It renders each scanned name in
  headless Chromium (Playwright is installed) with the bundled
  `material-symbols-outlined.woff2`, under exactly `app-icon`'s settings:
  `opsz` 20, weight as shipped, `FILL` 0 and `FILL` 1. It runs at a large size
  for precision. It pixel-scans the ink, the method #2563 used, and records the
  vertical **centroid** of the ink relative to the em box centre, as a fraction
  of the em, rounded to 0.001em. The centroid is the measure #2563 reported and
  what the eye reads as weight; the bounding box would let thin tails drive it.
- **Generated table**, `frontend/src/app/shared/icon/icon-ink-offsets.ts`:
  name → `[outlined, filled]` em offsets, plus the `material-symbols` version it
  was measured against. It is marked generated and committed.
- **Application.** `app-icon` looks up its name and `fill` and applies
  `translateY(<−offset>em)` to the glyph span. The translate changes no layout
  and scales with every size token. An unknown name gets no transform.
- **Guard (Jest).** It fails when the table's names differ from the scan's
  (missing or stale), or when its version differs from the installed
  `material-symbols`. The message says to run `npm run icons:measure`.

## Left to the builder

The script's file location and language, its page and pixel-scan mechanics,
the render size, and how the scan module is shared between the Node script and
Jest.
