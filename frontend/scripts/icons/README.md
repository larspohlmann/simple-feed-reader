# Icon ink shifts

Two commands, run from `frontend/`:

- `npm run icons:measure` renders every icon name the source uses in headless Chromium and writes the ink-centring shifts to `src/app/shared/icon/icon-ink-shifts.ts`. Run it when `icon-ink-shifts.spec.ts` fails: after adding or removing an icon name. It needs Playwright's chromium (`npx playwright install chromium`) and stops early if `glyph-names.txt` does not match the installed `material-symbols`.
- `npm run icons:glyph-names` lists the font's glyph names from its ligature table into `glyph-names.txt`. Run it only after updating `material-symbols`, then run `icons:measure`. This is the only step that needs Python 3 with `fonttools` and `brotli` (`pip install fonttools brotli`).

Commit the generated files.
