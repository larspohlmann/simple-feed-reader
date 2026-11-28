# Icon ink shifts

`npm run icons:measure` (from `frontend/`) lists the font's glyph names into `glyph-names.txt` (Python 3 with `fontTools` and `brotli`), then renders every icon name the source uses in headless Chromium and writes the ink-centring shifts to `src/app/shared/icon/icon-ink-shifts.ts`.

Run it when `icon-ink-shifts.spec.ts` fails: after adding an icon name, removing one, or updating `material-symbols`. Commit both generated files.
