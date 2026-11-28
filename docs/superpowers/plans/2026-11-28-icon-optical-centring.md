# Icon Optical Centring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Shift every `app-icon` glyph vertically so its ink centroid, not its box, sits on the box centre, from a script-generated table that a Jest guard keeps complete.

**Architecture:** A shared name scan (`src/testing/icon-names.ts`) finds every Material Symbol name the source can render. A Playwright script measures each one, outlined and filled, under `app-icon`'s exact settings, and writes `icon-ink-shifts.ts`. `app-icon` applies the shift as `translateY(<shift>em)`. A spec fails when the table and the scan disagree, or when the table was measured against another font version.

**Tech Stack:** Angular 20 standalone components and signals, Jest (jsdom), Playwright `chromium`, esbuild (already used by `scripts/fonts/measure-metrics.mjs`), Node 26.

**Spec:** `docs/superpowers/specs/2026-11-28-icon-optical-centring-design.md`

## Global Constraints

- Vertical correction only; no horizontal shift.
- Centroid of the ink, not its bounding box, measured relative to the 1em box centre.
- Shifts are stored as em fractions rounded to 0.001em; `-0` is written as `0`.
- Measurement uses `app-icon`'s exact rendering: the vendor `outlined.css` class `material-symbols-outlined`, `line-height: 1`, a `1em × 1em` inline-flex box centred both ways, `font-variation-settings: 'opsz' 20` (outlined) and `'opsz' 20, 'FILL' 1` (filled).
- Name scan: string literals in `frontend/src/**/*.ts` and `**/*.html`, excluding `*.spec.ts`, `src/testing/**` and the generated `icon-ink-shifts.ts` (its own keys would otherwise keep every stale name alive), matching `/^[a-z0-9_]+$/`, kept only if in `node_modules/material-symbols/index.d.ts`.
- Every icon gets the shift, standalone buttons included. An unknown name gets no transform.
- Guard failure message names the fix: `npm run icons:measure`.
- Worktree: run Jest with the local binary, `./node_modules/.bin/jest <path>` from `frontend/` (the Docker container serves the main checkout, not this worktree).
- Gate cadence (CLAUDE.md "Testing"): per task, only the specs covering the change plus `npx eslint`/`npx prettier --check` on changed files. No `npm run check` per task.
- No comments unless a future reader would get the code wrong without one; one line, three at most.

## Review Focus

1. **Font not loaded when measuring** — the script must wait for `document.fonts.load` of the glyph font and fail loudly if a glyph renders in a fallback face (a name wider than 1em). Expected: the script aborts, it never writes fallback-text centroids. Pinned in Task 2 Step 4 (a sanity check on the measured table: `home` < 0, `chat_bubble` > 0).
2. **A name that renders no ink** (blank glyph): division by zero. Expected: shift 0, not `NaN`. Pinned in Task 2's guard: every value is finite.
3. **Scan picks up non-icon words** (`home`, `search`, `label` as plain strings). Expected: harmless extra table entries. Nothing to pin; documented in the spec.
4. **Filled and outlined differ.** Expected: `fill` selects the second value. Pinned in Task 3.
5. **Spec-only names** (a name used only in a `*.spec.ts`). Expected: not in the scan, so the table doesn't grow with test fixtures. Pinned in Task 1.

---

### Task 1: Name scan

**Files:**
- Create: `frontend/src/testing/icon-names.ts`
- Test: `frontend/src/testing/icon-names.spec.ts`

**Interfaces:**
- Consumes: `listFiles(root, accept)` from `frontend/src/testing/list-files.ts`.
- Produces:
  - `fontGlyphNames(frontendRoot: string): ReadonlySet<string>` — names parsed from `node_modules/material-symbols/index.d.ts`.
  - `iconSourceFiles(frontendRoot: string): string[]` — paths relative to `src/`, as `listFiles` returns them.
  - `scanIconNames(frontendRoot: string): string[]` — sorted, unique, read from `iconSourceFiles`.
  - `installedFontVersion(frontendRoot: string): string` — `version` from `node_modules/material-symbols/package.json`.

- [ ] **Step 1: Write the failing test** (`FRONTEND = join(__dirname, '..', '..')`)

```ts
describe('scanIconNames', () => {
  const names = scanIconNames(FRONTEND);

  it('finds names picked in TypeScript and in templates', () => {
    expect(names).toEqual(expect.arrayContaining(['expand_more', 'chevron_right', 'chat_bubble']));
  });

  it('finds every user-choosable tag icon', () => {
    expect(names).toEqual(expect.arrayContaining(TAG_ICONS));
  });

  it('keeps only names the font has', () => {
    const font = fontGlyphNames(FRONTEND);
    expect(names.filter((name) => !font.has(name))).toEqual([]);
  });

  it('reads neither specs, test helpers nor the generated table', () => {
    const files = iconSourceFiles(FRONTEND);
    expect(files).toContain('app/shared/icon-choices.ts');
    expect(files.filter((path) =>
      path.endsWith('.spec.ts') || path.startsWith('testing/') || path === 'app/shared/icon/icon-ink-shifts.ts',
    )).toEqual([]);
  });

  it('is sorted and unique', () => {
    expect(names).toEqual([...new Set(names)].sort());
  });

  it('ignores names that only spec files use', () => {
    // `settings` appears in icon.component.spec.ts; pick one only specs use, verified by grep at implementation time.
    expect(names).not.toContain(SPEC_ONLY_GLYPH);
  });
});

describe('installedFontVersion', () => {
  it('reads the material-symbols package version', () => {
    expect(installedFontVersion(FRONTEND)).toMatch(/^\d+\.\d+\.\d+$/);
  });
});
```

`SPEC_ONLY_GLYPH`: the implementer greps for a font glyph name that appears in a `*.spec.ts` and nowhere else in `src`. If none exists, drop that test and say so in the report.

- [ ] **Step 2: Run it to verify it fails**

Run: `./node_modules/.bin/jest src/testing/icon-names.spec.ts`
Expected: FAIL, cannot find module `./icon-names`.

- [ ] **Step 3: Implement the three functions in `frontend/src/testing/icon-names.ts`**

The scan reads each accepted file's text and collects every quoted token, `/['"`]([a-z0-9_]+)['"`]/g`, then intersects with `fontGlyphNames`. The `index.d.ts` list is quoted strings, one per line. Relative imports carry no extension (the script bundles this file with esbuild).

- [ ] **Step 4: Run it to verify it passes**

Run: `./node_modules/.bin/jest src/testing/icon-names.spec.ts`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/testing/icon-names.ts frontend/src/testing/icon-names.spec.ts
git commit -m "feat(#2613): scan the source for material symbol names"
```

### Task 2: Measuring script, generated table and guard

**Files:**
- Create: `frontend/scripts/icons/measure-ink-shifts.mjs`
- Create: `frontend/scripts/icons/README.md` (how and when to run it, in 5 lines or fewer)
- Create (generated): `frontend/src/app/shared/icon/icon-ink-shifts.ts`
- Modify: `frontend/package.json` (script `"icons:measure": "node scripts/icons/measure-ink-shifts.mjs"`)
- Test: `frontend/src/app/shared/icon/icon-ink-shifts.spec.ts`

**Interfaces:**
- Consumes: `scanIconNames`, `installedFontVersion` from Task 1.
- Produces, in `icon-ink-shifts.ts`:
  - `export const ICON_INK_SHIFTS_FONT_VERSION: string`
  - `export const ICON_INK_SHIFTS: Readonly<Record<string, readonly [outlined: number, filled: number]>>` — the em to translate by (negative moves up), keys sorted.

- [ ] **Step 1: Write the failing guard**

```ts
const FRONTEND = join(__dirname, '..', '..', '..', '..');
const STALE = 'Run `npm run icons:measure` from frontend/ to remeasure.';

describe('icon ink shifts', () => {
  it('covers exactly the names the source can render', () => {
    expect({ names: Object.keys(ICON_INK_SHIFTS), fix: STALE })
      .toEqual({ names: scanIconNames(FRONTEND), fix: STALE });
  });

  it('was measured against the installed font', () => {
    expect({ version: ICON_INK_SHIFTS_FONT_VERSION, fix: STALE })
      .toEqual({ version: installedFontVersion(FRONTEND), fix: STALE });
  });

  it('holds a finite, small shift for every glyph', () => {
    const shifts = Object.values(ICON_INK_SHIFTS).flat();
    expect(shifts.filter((shift) => !Number.isFinite(shift) || Math.abs(shift) >= 0.15)).toEqual([]);
  });

  it('lifts the low glyphs and lowers the high ones', () => {
    expect(ICON_INK_SHIFTS['home'][0]).toBeLessThan(0);
    expect(ICON_INK_SHIFTS['chat_bubble'][0]).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./node_modules/.bin/jest src/app/shared/icon/icon-ink-shifts.spec.ts`
Expected: FAIL, cannot find module `./icon-ink-shifts`.

- [ ] **Step 3: Write `scripts/icons/measure-ink-shifts.mjs`**

- Bundle `src/testing/icon-names.ts` with esbuild to `node_modules/.cache/measure-ink-shifts/`, the way `scripts/fonts/measure-metrics.mjs` `loadFixtures()` does, and import it.
- Launch `chromium`, `page.route` a fixed origin (e.g. `http://icons.invalid/`) to serve `node_modules/material-symbols/outlined.css` and its woff2, and serve a page that links that stylesheet. No dev server.
- Render each name at 200px in a span carrying the Global Constraints rendering: black on white, once per fill. Await `document.fonts.load('200px "Material Symbols Outlined"')`. Abort with a clear message if any span's `scrollWidth` exceeds its width (fallback text).
- Read the pixels with `elementHandle.screenshot()`, decoding inside the page (canvas `drawImage` of the PNG, then `getImageData`). Alternatively screenshot a grid of cells once per fill and slice it; that is the implementer's choice.
- Weight each pixel by `255 - red`, then `centroidY = Σ(y + 0.5)·ink / Σink`. Set `shift = -(centroidY - size / 2) / size`, rounded to 0.001, with `-0` → `0`. A glyph with no ink gets 0.
- Write `icon-ink-shifts.ts`: a first line `// Generated by scripts/icons/measure-ink-shifts.mjs — do not edit.`, both exports, keys sorted, then run `npx prettier --write` on it.
- Print the count measured and the five largest `|shift|` values.

- [ ] **Step 4: Run the script and check it against #2563**

Run: `npm run icons:measure`
Expected: completes and prints the count (several hundred). Compare against #2563's centroids at 16px, where shift ≈ −centroid / 16:

| Glyph | Expected outlined shift (em) |
|---|---|
| `home` | ≈ −0.066 |
| `arrow_upward` | ≈ +0.046 |
| `chat_bubble` | ≈ +0.053 |
| `menu_book` | ≈ +0.013 |

Within ±0.01em passes. If a value is further off or the sign is wrong, stop and report the numbers. Don't tune the method to match.

- [ ] **Step 5: Run the guard to verify it passes**

Run: `./node_modules/.bin/jest src/app/shared/icon/icon-ink-shifts.spec.ts src/testing/icon-names.spec.ts`
Expected: PASS.

- [ ] **Step 6: Break what the guard guards, then restore**

Delete one key from `icon-ink-shifts.ts` and rerun: expect FAIL on "covers exactly". Change the version string and rerun: expect FAIL on "was measured against". Quote both FAIL lines in the report, then `git checkout` the file.

- [ ] **Step 7: Commit**

```bash
git add frontend/scripts/icons frontend/src/app/shared/icon/icon-ink-shifts.ts frontend/src/app/shared/icon/icon-ink-shifts.spec.ts frontend/package.json
git commit -m "feat(#2613): measure material symbol ink shifts into a guarded table"
```

### Task 3: `app-icon` applies the shift

**Files:**
- Modify: `frontend/src/app/shared/icon/icon.component.ts`, `icon.component.html`
- Modify: `docs/design-language.md` (one sentence where `app-icon` is described: glyphs are ink-centred from the generated table; new names need `npm run icons:measure`)
- Test: `frontend/src/app/shared/icon/icon.component.spec.ts`

**Interfaces:**
- Consumes: `ICON_INK_SHIFTS` from Task 2.
- Produces: `protected inkShift(): string | null` on `IconComponent`, bound as `[style.transform]` on the glyph span, keeping its static `style="font-size: inherit"`.

- [ ] **Step 1: Write the failing tests** in `icon.component.spec.ts`, with hosts for `name="home"`, `name="home" [fill]="true"` and `name="no_such_glyph"`

```ts
it('lifts the glyph by its measured outlined shift', () => {
  expect(spanOf(homeFixture).style.transform).toBe(`translateY(${ICON_INK_SHIFTS['home'][0]}em)`);
});

it('uses the filled shift when filled', () => {
  expect(spanOf(filledHomeFixture).style.transform).toBe(`translateY(${ICON_INK_SHIFTS['home'][1]}em)`);
});

it('leaves an unmeasured glyph untransformed', () => {
  expect(spanOf(unknownFixture).style.transform).toBe('');
});

it('keeps the inline font-size beside the transform', () => {
  expect(spanOf(homeFixture).style.fontSize).toBe('inherit');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./node_modules/.bin/jest src/app/shared/icon/icon.component.spec.ts`
Expected: the first two FAIL (transform `''`); the last two pass.

- [ ] **Step 3: Implement `inkShift()`**

Return `null` for an unknown name or a zero shift. Otherwise return `` `translateY(${shift}em)` ``, with the shift picked by `fill()`.

- [ ] **Step 4: Run them to verify they pass**

Run: `./node_modules/.bin/jest src/app/shared/icon`
Expected: PASS, all icon specs.

- [ ] **Step 5: Live check**

Serve the worktree (`./node_modules/.bin/ng serve --port 4201` with a proxy to `https://localhost:8443`, per the worktree memory). Screenshot the "Politics" tag pill and `entry-meta`'s `💬 N` at desktop 1280 and phone 375. Read the computed `transform` on a `home` span to confirm it's applied.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/shared/icon docs/design-language.md
git commit -m "feat(#2613): centre every icon glyph by its ink"
```
