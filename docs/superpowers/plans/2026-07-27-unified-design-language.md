# Unified Design Language Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promote the frontend's implicit visual conventions into a documented, shared, mechanically-enforced design language so new feature areas are consistent by construction.

**Architecture:** Three layers. (1) A token layer in `src/app/theme/` — CSS custom properties for spacing/radii/sizing/type, plus an SCSS partial for breakpoints, since custom properties do not work inside `@media`. (2) Stylelint rules that make ad-hoc `px` a CI failure outside `theme/` and `styles/`. (3) Shared presentational components in `src/app/shared/` that own the recurring patterns — the tag glyph, the form field, the overlay panel, the button.

**Tech Stack:** Angular 20 (standalone components, signal inputs, OnPush), SCSS, Stylelint 17 + `stylelint-config-standard-scss`, Jest + `@angular/core/testing` TestBed, `@angular/cdk/dialog`.

**Spec:** [docs/superpowers/specs/2026-07-27-unified-design-language-design.md](../specs/2026-07-27-unified-design-language-design.md)

**Branch:** `feature/126-unified-design-language` (already created, spec already committed)

---

## Working agreements

- Run all frontend commands from `frontend/`.
- The CI gate is `npm run check` (ESLint + Prettier + Stylelint + Jest). Run it before every commit.
- Commit after every task. Never leave `main`/`develop` — this branch merges to `develop` via PR.
- Where a task says "expected: N violations", the number is a guide from the state at planning time. If your count differs, that is information, not necessarily a bug — but investigate before proceeding.

---

## File structure

**Created:**

| Path | Responsibility |
|---|---|
| `frontend/src/app/theme/_breakpoints.scss` | The three breakpoint SCSS variables. SCSS, not CSS custom properties, because `@media` cannot read custom properties. |
| `frontend/src/app/shared/tag-glyph/tag-glyph.component.ts` | The one way to render a tag/category: tinted glyph, colour-dot fallback. |
| `frontend/src/app/shared/tag-glyph/tag-glyph.component.html` | Template for the above. |
| `frontend/src/app/shared/tag-glyph/tag-glyph.component.scss` | Styles for the above. |
| `frontend/src/app/shared/tag-glyph/tag-glyph.component.spec.ts` | Contract tests. |
| `frontend/src/app/shared/field/field.component.ts` | Projection wrapper owning label / required marker / hint / error. |
| `frontend/src/app/shared/field/field.component.html` | Template for the above. |
| `frontend/src/app/shared/field/field.component.scss` | Styles for the above. |
| `frontend/src/app/shared/field/field.component.spec.ts` | Contract tests. |
| `frontend/src/app/shared/color-field/color-field.component.ts` | Swatch row + native colour input + clear. |
| `frontend/src/app/shared/color-field/color-field.component.html` | Template for the above. |
| `frontend/src/app/shared/color-field/color-field.component.scss` | Styles for the above. |
| `frontend/src/app/shared/color-field/color-field.component.spec.ts` | Contract tests. |
| `frontend/src/app/shared/overlay-panel/overlay-panel.component.ts` | Responsive dialog frame: centred card on desktop, full screen on mobile. |
| `frontend/src/app/shared/overlay-panel/overlay-panel.component.html` | Template for the above. |
| `frontend/src/app/shared/overlay-panel/overlay-panel.component.scss` | Styles for the above. |
| `frontend/src/app/shared/overlay-panel/overlay-panel.component.spec.ts` | Contract tests. |
| `frontend/src/styles/_controls.scss` | Global styles for controls projected into `<app-field>`. |
| `docs/design-language.md` | The prose reference: token tables, component catalog, conventions, new-surface checklist. |

**Modified (principal):**

| Path | Change |
|---|---|
| `frontend/src/app/theme/tokens.scss` | New spacing/radius/sizing/icon/type/density tokens. |
| `frontend/.stylelintrc.json` | The three enforcement rules and their overrides. |
| `frontend/src/styles.scss` | `@use` the new `_controls.scss`; add the global `.app-dialog` panelClass. |
| `frontend/src/styles/_base.scss` | Remove the `.field` block (superseded by `<app-field>`). |
| `frontend/src/app/shared/icon/icon.component.ts` | `size` becomes a named union. |
| `frontend/src/app/shared/button/button.component.ts` | Signal inputs, `danger`/`ghost` variants, `size`. |
| 42 `*.scss` files + inline `styles:` blocks | Ad-hoc `px` → tokens. |
| `CLAUDE.md` | Pointer to `docs/design-language.md`; correct two stale lines. |

---

# Phase A — Token layer

## Task 1: Add the new tokens

**Files:**
- Modify: `frontend/src/app/theme/tokens.scss:19-39`

- [ ] **Step 1: Replace the mode-invariant token block**

Replace the whole `// Mode-invariant tokens (same in every theme).` block (lines 19–39) with:

```scss
// Mode-invariant tokens (same in every theme).
:root {
  /* Spacing scale. --space-0 is a real half-step for tight gaps (rail rows,
     icon-grid padding), not a stray. Anything off this scale is a Stylelint
     failure outside theme/ and styles/. */
  --space-0: 2px;
  --space-1: 4px;
  --space-2: 8px;
  --space-3: 12px;
  --space-4: 16px;
  --space-5: 24px;
  --space-6: 32px;
  --space-7: 48px;

  /* Radii. --radius-pill is for chips and pills; --radius-sm for elements
     nested inside an already-rounded container. */
  --radius-sm: 4px;
  --radius: 8px;
  --radius-pill: 999px;

  /* Structural sizing. --bar-h is the *fallback* for the floating app bar,
     whose real height is measured at runtime by ReaderShellComponent and
     written to --app-bar-h; read it as `var(--app-bar-h, var(--bar-h))`.
     --tap-target is the documented minimum touch target. */
  --control-h: 40px;
  --bar-h: 56px;
  --tap-target: 44px;

  /* Icon sizes. Four steps because the same tag glyph is rendered from a
     12px pill up to a 20px sidebar lead; three steps would inflate the pill. */
  --icon-xs: 12px;
  --icon-sm: 16px;
  --icon-md: 20px;
  --icon-lg: 24px;

  /* Row density. Every list, rail and picker row derives its padding from
     these two, which is what keeps the picker rail and the reader's lists
     from drifting apart again. */
  --row-pad-y: var(--space-2);
  --row-pad-x: var(--space-3);

  /* Breathing room between the floating top bars and the content that starts
     beneath them. Added to the measured bar heights by every scrolling pane, so
     this one value tunes the gap everywhere (#87). */
  --bar-gap: var(--space-4);

  /* Type scale. */
  --font-sans: system-ui, -apple-system, 'Segoe UI', roboto, sans-serif;
  --fs-xs: 11px;
  --fs-sm: 13px;
  --fs-base: 15px;
  --fs-lg: 18px;
  --fs-xl: 24px;
  --lh-tight: 1.25;
  --lh-normal: 1.5;
}
```

- [ ] **Step 2: Verify nothing broke**

```bash
npm run check
```

Expected: PASS. This task is purely additive — every existing token keeps its name and value, so no consumer changes.

- [ ] **Step 3: Commit**

```bash
git add src/app/theme/tokens.scss
git commit -m "feat(theme): extend the token scale for the design language (#126)"
```

---

## Task 2: Add the breakpoint partial

**Files:**
- Create: `frontend/src/app/theme/_breakpoints.scss`

- [ ] **Step 1: Create the partial**

```scss
// src/app/theme/_breakpoints.scss
// Breakpoints must be SCSS variables, not CSS custom properties: `@media`
// cannot read custom properties, so `@media (width <= var(--bp-md))` silently
// never matches. Components `@use` this partial and write
// `@media (width <= bp.$bp-md)`.
//
// Three steps only. The seven values that existed before (560/720/800/820/
// 899/900/960) were drift, not intent.

$bp-sm: 560px; // phone
$bp-md: 720px; // small tablet, phone landscape
$bp-lg: 900px; // desktop layout switch
```

- [ ] **Step 2: Verify Stylelint accepts it**

```bash
npm run stylelint
```

Expected: PASS. (An unused partial is not an error.)

- [ ] **Step 3: Commit**

```bash
git add src/app/theme/_breakpoints.scss
git commit -m "feat(theme): add breakpoint tokens as an SCSS partial (#126)"
```

---

# Phase B — Enforcement

## Task 3: Add the Stylelint rules

These land **red on purpose**. Phase C makes them pass. Do not commit this task on its own — it is committed together with Task 4, so no commit on the branch ever has a failing gate.

**Files:**
- Modify: `frontend/.stylelintrc.json`

- [ ] **Step 1: Replace the config**

```json
{
  "extends": ["stylelint-config-standard-scss"],
  "rules": {
    "color-no-hex": true,
    "scss/dollar-variable-pattern": null,
    "selector-class-pattern": null,
    "no-empty-source": null,
    "selector-pseudo-element-no-unknown": [true, { "ignorePseudoElements": ["ng-deep"] }],
    "declaration-property-unit-allowed-list": {
      "padding": [],
      "padding-top": [],
      "padding-right": [],
      "padding-bottom": [],
      "padding-left": [],
      "margin": [],
      "margin-top": [],
      "margin-right": [],
      "margin-bottom": [],
      "margin-left": [],
      "gap": [],
      "row-gap": [],
      "column-gap": [],
      "font-size": [],
      "border-radius": [],
      "width": [],
      "height": [],
      "min-width": [],
      "min-height": [],
      "max-width": [],
      "max-height": []
    },
    "media-feature-name-unit-allowed-list": { "width": [] }
  },
  "overrides": [
    {
      "files": ["src/app/theme/**/*.scss", "src/styles/**/*.scss", "src/styles.scss"],
      "rules": {
        "color-no-hex": null,
        "declaration-property-unit-allowed-list": null,
        "media-feature-name-unit-allowed-list": null
      }
    }
  ]
}
```

Two notes on what is deliberately **not** in this list:

- `border-width` and `outline-*` are absent, so the 88 `1px` hairlines stay literal. Tokenising a hairline lengthens every border declaration and buys nothing.
- An empty array (`"padding": []`) means "no units permitted at all" — so `padding: 0` and `padding: var(--space-2)` pass, while `padding: 12px` and `padding: 0.5rem` fail. That is the intent: the value must come from a token.

- [ ] **Step 2: Confirm the rules bite**

```bash
npm run stylelint 2>&1 | tail -5
```

Expected: FAIL, with a violation count in the low hundreds. Record the number — Phase C drives it to zero.

- [ ] **Step 3: Do not commit yet**

Proceed directly to Task 4. Task 4's commit includes this file.

---

# Phase C — Migration

**The snap rule, applied uniformly in every task below.** Round each off-scale value to the nearest step; ties round *up* (toward more breathing room, which is the direction #126 complains about).

| Found | Becomes |
|---|---|
| `2px` | `var(--space-0)` |
| `3px`, `4px`, `5px` | `var(--space-1)` |
| `6px`–`10px` | `var(--space-2)` |
| `11px`–`14px` | `var(--space-3)` |
| `15px`–`19px` | `var(--space-4)` |
| `20px`–`27px` | `var(--space-5)` |
| `28px`–`39px` | `var(--space-6)` |
| `40px`–`55px` | `var(--space-7)` |
| `999px` (border-radius) | `var(--radius-pill)` |
| `56px` as `var(--app-bar-h, 56px)` | `var(--app-bar-h, var(--bar-h))` |
| `56px` as a literal height | `var(--bar-h)` |
| `44px` on `to-top-button` width/height | `var(--tap-target)` |
| `1px` border / outline widths | **unchanged** |

**Exception — genuinely structural one-offs.** A value that is a tuned component
dimension rather than spacing (the 220px rail width, the 1040px discover panel,
the 460px dialog width, the 216px icon-grid cap, the 300px/400px content
measures) is **not** snapped. It gets:

```scss
/* stylelint-disable-next-line declaration-property-unit-allowed-list --
   tuned component dimension, not a spacing value. */
width: 220px;
```

**Do not** snap `44px` in `entry-list.component.scss:185` or
`reader-header.component.scss:175`. Those are positioning offsets that
coincidentally share the tap-target number; they are structural one-offs and
take the disable comment.

**Breakpoint collapse, applied in every task below:**

| Found | Becomes |
|---|---|
| `(max-width: 800px)`, `(width <= 800px)`, `(width <= 820px)` | `(width <= bp.$bp-md)` |
| `(width <= 720px)` | `(width <= bp.$bp-md)` |
| `(width <= 560px)` | `(width <= bp.$bp-sm)` |
| `(width < 900px)`, `(width <= 899px)`, `(width <= 960px)` | `(width <= bp.$bp-lg)` |

Every file with a `@media (width …)` query gains, as its first line:

```scss
@use '../../theme/breakpoints' as bp;
```

with the relative depth adjusted per file. `@media (prefers-reduced-motion: reduce)` is untouched.

---

## Task 4: Migrate `shared/`

**Files:**
- Modify: `frontend/.stylelintrc.json` (from Task 3)
- Modify: `frontend/src/app/shared/**/*.scss` and inline `styles:` blocks
- Modify: `frontend/src/styles/_base.scss`, `frontend/src/styles/_reset.scss` — **no**, these are exempt; skip them

- [ ] **Step 1: List the violations in scope**

```bash
npx stylelint "src/app/shared/**/*.scss" --formatter compact
```

- [ ] **Step 2: Apply the snap and exception rules**

Work file by file through the output. Known specifics:

- `shared/to-top-button/to-top-button.component.scss:18-19` — `width: 44px` and `height: 44px` become `var(--tap-target)`. This is the one honest tap target in the codebase.
- `shared/icon-picker/icon-picker.component.ts` — 9 px values in the inline `styles:` block. Inline blocks are linted too; apply the same rules.
- `shared/spinner`, `shared/favicon`, `shared/user-avatar` — sizes are component dimensions driven by an input; use the disable comment where the value is structural.

- [ ] **Step 3: Verify this area is clean**

```bash
npx stylelint "src/app/shared/**/*.scss" "src/app/shared/**/*.ts"
```

Expected: PASS (no output).

- [ ] **Step 4: Verify nothing else broke**

```bash
npm test
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add .stylelintrc.json src/app/shared
git commit -m "refactor(theme): enforce token-only spacing and migrate shared/ (#126)"
```

---

## Task 5: Migrate `auth/`

**Files:**
- Modify: `frontend/src/app/auth/**/*.scss`

- [ ] **Step 1: List the violations**

```bash
npx stylelint "src/app/auth/**/*.scss" --formatter compact
```

- [ ] **Step 2: Apply the snap and breakpoint rules**

- [ ] **Step 3: Verify**

```bash
npx stylelint "src/app/auth/**/*.scss" && npm test -- auth
```

Expected: both PASS.

- [ ] **Step 4: Commit**

```bash
git add src/app/auth
git commit -m "refactor(auth): move spacing onto the token scale (#126)"
```

---

## Task 6: Migrate `settings/`

**Files:**
- Modify: `frontend/src/app/settings/*.scss` (7 files; `settings.component.scss` has 4 violations, `tags-section` 6)

- [ ] **Step 1: List the violations**

```bash
npx stylelint "src/app/settings/**/*.scss" --formatter compact
```

- [ ] **Step 2: Apply the rules**

Specific: `settings/settings.component.scss:2` has a literal `height: 56px` — this is a static bar height, so it becomes `var(--bar-h)` (not the `var(--app-bar-h, …)` form; settings has no runtime measurement).

- [ ] **Step 3: Verify**

```bash
npx stylelint "src/app/settings/**/*.scss" && npm test -- settings
```

Expected: both PASS.

- [ ] **Step 4: Commit**

```bash
git add src/app/settings
git commit -m "refactor(settings): move spacing onto the token scale (#126)"
```

---

## Task 7: Migrate `admin/`

**Files:**
- Modify: `frontend/src/app/admin/admin-catalog.component.scss` (11 violations), `frontend/src/app/admin/admin-users.component.scss` (5)

- [ ] **Step 1: List the violations**

```bash
npx stylelint "src/app/admin/**/*.scss" --formatter compact
```

- [ ] **Step 2: Apply the rules**

- [ ] **Step 3: Verify**

```bash
npx stylelint "src/app/admin/**/*.scss" && npm test -- admin
```

Expected: both PASS.

- [ ] **Step 4: Commit**

```bash
git add src/app/admin
git commit -m "refactor(admin): move spacing onto the token scale (#126)"
```

---

## Task 8: Migrate `discover/`

**Files:**
- Modify: `frontend/src/app/discover/discover.component.scss` (18 violations)
- Modify: `frontend/src/app/discover/category-rail.component.ts` (5, inline)
- Modify: `frontend/src/app/discover/category-chips.component.ts` (4, inline)

- [ ] **Step 1: List the violations**

```bash
npx stylelint "src/app/discover/**/*.scss" "src/app/discover/**/*.ts" --formatter compact
```

- [ ] **Step 2: Apply the rules**

Specifics:

- `discover.component.scss` — the `1040px` panel width and `860px` max-height are structural one-offs; use the disable comment. `border-radius: calc(var(--radius) * 1.5)` is already token-derived; leave it. `border-radius: 999px` at line 133 becomes `var(--radius-pill)`.
- `category-rail.component.ts` — `width: 220px` is a structural one-off (disable comment). `gap: 2px` becomes `var(--space-0)`. The `@media (max-width: 800px)` becomes `@media (width <= bp.$bp-md)`, which requires `@use '../theme/breakpoints' as bp;`. **Inline `styles:` blocks cannot use `@use`** — see Step 3.
- `category-chips.component.ts` — same `@use` problem.

- [ ] **Step 3: Handle the inline-styles/`@use` conflict**

An Angular inline `styles:` block is compiled as a standalone SCSS unit, so `@use '../theme/breakpoints'` resolves relative to the *workspace* include paths, not the component file. Rather than fight this, **extract `category-rail` and `category-chips` styles into sibling `.scss` files**, matching what commit `496d06d` already did for the rest of the codebase:

Create `frontend/src/app/discover/category-rail.component.scss` with the current `styles:` content plus the `@use` line, and change the component decorator from `styles: \`…\`` to `styleUrl: './category-rail.component.scss'`. Do the same for `category-chips`.

This is consistent with the house pattern and removes the last inline style blocks outside `shared/icon-picker`.

- [ ] **Step 4: Verify**

```bash
npx stylelint "src/app/discover/**/*.scss" "src/app/discover/**/*.ts" && npm test -- discover
```

Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add src/app/discover
git commit -m "refactor(discover): move spacing onto the token scale (#126)"
```

---

## Task 9: Migrate `reader/` — part 1, the shell and header

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.scss` (16 violations)
- Modify: `frontend/src/app/reader/header/reader-header.component.scss` (18)

This is the highest-risk area in the sweep: the floating-bar measurement logic in `ReaderShellComponent` reads these values back at runtime.

- [ ] **Step 1: List the violations**

```bash
npx stylelint "src/app/reader/reader-shell.component.scss" "src/app/reader/header/*.scss" --formatter compact
```

- [ ] **Step 2: Apply the rules, carefully**

Specifics:

- Every `var(--app-bar-h, 56px)` becomes `var(--app-bar-h, var(--bar-h))`. There are 2 in `reader-shell.component.scss` (lines 64, 154).
- `reader-shell.component.scss:213` (`top: 56px`) and `:231` (`inset: 56px 0 0`) are inside a media query and are pre-measurement fallbacks; they become `var(--bar-h)`. Note `top` and `inset` are **not** in the allowed-list rule, so Stylelint will not force this — do it anyway for consistency.
- `reader-header.component.scss:15` (`height: 56px`) becomes `var(--bar-h)`.
- `reader-header.component.scss:61` (`border-radius: 999px`) becomes `var(--radius-pill)`.
- `reader-header.component.scss:175` (`top: 44px`) is a positioning offset, **not** a tap target. Leave the literal; `top` is not linted so no disable comment is needed, but add a one-line comment saying why it is not `--tap-target`.

- [ ] **Step 3: Verify the measurement logic still holds**

```bash
npm test -- reader-shell reader-header
```

Expected: PASS. `reader-shell.component.spec.ts` has assertions about bar offsets (see its line ~189 comment about backdrop strips) — if any fail, the fallback substitution changed a computed value and must be re-examined, not force-updated.

- [ ] **Step 4: Commit**

```bash
git add src/app/reader/reader-shell.component.scss src/app/reader/header
git commit -m "refactor(reader): move the shell and header onto the token scale (#126)"
```

---

## Task 10: Migrate `reader/` — part 2, the remaining surfaces

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss` (31 violations — the largest single file)
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss` (24)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.scss` (18)
- Modify: `frontend/src/app/reader/manage/*.scss`, `add-feed/*.scss`, `entry-row/*.scss`, `magazine/*.scss`, `source-tags/*.scss`, `view-controls/*.scss`

- [ ] **Step 1: List the violations**

```bash
npx stylelint "src/app/reader/**/*.scss" --formatter compact
```

- [ ] **Step 2: Apply the rules**

Specifics:

- `entry-list.component.scss` — six `calc()` expressions build on `var(--app-bar-h, 56px)`; all become `var(--app-bar-h, var(--bar-h))`. Line 185's `- 44px` is the pull-to-refresh chip offset: leave it literal with a comment, per the exception rule.
- `sidebar.component.scss:162-167` — the `.dot` block (`width: 9px; height: 9px`) is about to move into `<app-tag-glyph>` in Task 11. Migrate it now anyway (`9px` → `var(--space-2)`); Task 11 deletes it.
- `source-tags.component.scss:14` and `add-feed-dialog.component.scss:89` and `edit-subscription-dialog.component.scss:64` — `border-radius: 999px` → `var(--radius-pill)`.
- `tag-form-dialog.component.scss` — `216px` icon-grid cap and `22px`/`26px`/`30px` swatch dimensions. The `216px` is a structural one-off (disable comment, and its existing `#85` comment explains why it exists — keep that comment). The swatch dimensions move into `<app-color-field>` in Task 13; snap them now (`22px`/`26px` → `var(--space-5)`, `30px` → `var(--space-6)`).

- [ ] **Step 3: Verify the whole gate**

```bash
npm run check
```

Expected: **PASS** — this is the moment the Stylelint rules added in Task 3 go green. If `npm run stylelint` still reports violations, they are outside `src/app/reader`; find them with `npx stylelint "src/**/*.scss" "src/**/*.ts" --formatter compact`.

- [ ] **Step 4: Commit**

```bash
git add src/app/reader
git commit -m "refactor(reader): move the remaining surfaces onto the token scale (#126)"
```

---

# Phase D — Shared components

## Task 11: `<app-tag-glyph>`

The canonical tag/category rendering. Replaces five re-implementations, two of
which (`category-rail`, `category-chips`) have no dot fallback today and render
an empty box for a category with a colour but no icon.

**Files:**
- Create: `frontend/src/app/shared/tag-glyph/tag-glyph.component.spec.ts`
- Create: `frontend/src/app/shared/tag-glyph/tag-glyph.component.ts`
- Create: `frontend/src/app/shared/tag-glyph/tag-glyph.component.html`
- Create: `frontend/src/app/shared/tag-glyph/tag-glyph.component.scss`
- Modify: `frontend/src/app/shared/icon/icon.component.ts`
- Modify: `frontend/src/app/shared/icon/icon.component.html`

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/app/shared/tag-glyph/tag-glyph.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { TagGlyphComponent } from './tag-glyph.component';

@Component({
  imports: [TagGlyphComponent],
  template: `<app-tag-glyph [name]="name()" [color]="color()" [size]="'md'" />`,
})
class Host {
  readonly name = signal<string | null>(null);
  readonly color = signal<string | null>(null);
}

describe('TagGlyphComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders the tinted glyph when a name is given', async () => {
    const fixture = await mount();
    fixture.componentInstance.name.set('public');
    fixture.componentInstance.color.set('#c08a3e');
    fixture.detectChanges();

    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('.material-symbols-outlined')?.textContent?.trim()).toBe('public');
    expect(el.querySelector('.dot')).toBeNull();
    // jsdom normalises the hex to rgb(), so assert it is set rather than equal.
    expect((el.querySelector('app-icon') as HTMLElement).style.color).toBeTruthy();
  });

  it('falls back to the colour dot when no name is given', async () => {
    const fixture = await mount();
    fixture.componentInstance.color.set('#c08a3e');
    fixture.detectChanges();

    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('.material-symbols-outlined')).toBeNull();
    expect((el.querySelector('.dot') as HTMLElement).style.background).toBeTruthy();
  });

  it('falls back to the muted colour when no colour is given', async () => {
    const fixture = await mount();
    fixture.componentInstance.name.set('public');
    fixture.detectChanges();

    const icon = fixture.nativeElement.querySelector('app-icon') as HTMLElement;
    expect(icon.style.color).toBe('var(--text-muted)');
  });

  it('renders the dot even when both name and colour are absent', async () => {
    const fixture = await mount();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('.dot')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npm test -- tag-glyph
```

Expected: FAIL — `Cannot find module './tag-glyph.component'`.

- [ ] **Step 3: Widen `IconComponent`'s size to the named union**

Replace `frontend/src/app/shared/icon/icon.component.ts` with:

```ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';

export type IconSize = 'xs' | 'sm' | 'md' | 'lg';

/** Maps the named size onto its token. Kept here so no consumer writes a px. */
const SIZE_TOKEN: Record<IconSize, string> = {
  xs: 'var(--icon-xs)',
  sm: 'var(--icon-sm)',
  md: 'var(--icon-md)',
  lg: 'var(--icon-lg)',
};

@Component({
  selector: 'app-icon',
  templateUrl: './icon.component.html',
  styleUrl: './icon.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IconComponent {
  readonly name = input.required<string>();
  readonly size = input<IconSize>('md');

  protected fontSize(): string {
    return SIZE_TOKEN[this.size()];
  }
}
```

Replace `frontend/src/app/shared/icon/icon.component.html` with:

```html
<span class="material-symbols-outlined" [style.font-size]="fontSize()" aria-hidden="true">{{
  name()
}}</span>
```

- [ ] **Step 4: Create the component**

`frontend/src/app/shared/tag-glyph/tag-glyph.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { IconComponent, IconSize } from '../icon/icon.component';

/**
 * The one way to render a tag or catalog category.
 *
 * A tag carries an optional Material Symbol and an optional colour. With a
 * glyph it renders tinted; without one it falls back to a colour dot, so an
 * icon-less tag is still identifiable at a glance. Callers that highlight a
 * selected row pass the highlight colour in `color` (`'currentColor'`, say) —
 * both branches honour it, which is why the ternary no longer has to be
 * duplicated across the glyph and the dot.
 */
@Component({
  selector: 'app-tag-glyph',
  imports: [IconComponent],
  templateUrl: './tag-glyph.component.html',
  styleUrl: './tag-glyph.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TagGlyphComponent {
  readonly name = input<string | null>(null);
  readonly color = input<string | null>(null);
  readonly size = input<IconSize>('md');

  protected tint(): string {
    return this.color() ?? 'var(--text-muted)';
  }
}
```

`frontend/src/app/shared/tag-glyph/tag-glyph.component.html`:

```html
@if (name(); as glyph) {
  <app-icon [name]="glyph" [size]="size()" [style.color]="tint()" />
} @else {
  <span class="dot" [style.background]="tint()"></span>
}
```

`frontend/src/app/shared/tag-glyph/tag-glyph.component.scss`:

```scss
:host {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
}

.dot {
  width: var(--space-2);
  height: var(--space-2);
  border-radius: var(--radius-pill);
}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npm test -- tag-glyph icon
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/app/shared/tag-glyph src/app/shared/icon
git commit -m "feat(shared): add TagGlyphComponent and name IconComponent's sizes (#126)"
```

---

## Task 12: Adopt `<app-tag-glyph>` at all five sites

Every `[size]="N"` on an `app-icon` anywhere in the app also has to move to the
named union, since Task 11 changed the input's type. Map with: `N <= 13` → `xs`,
`14–17` → `sm`, `18–21` → `md`, `>= 22` → `lg`.

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html:110-123`, `sidebar.component.scss` (delete `.dot`)
- Modify: `frontend/src/app/reader/source-tags/source-tags.component.html:12-17`, `.scss` (delete `.dot`)
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.html:33-47`, `.scss` (delete `.dot`)
- Modify: `frontend/src/app/discover/category-rail.component.html`
- Modify: `frontend/src/app/discover/category-chips.component.html`
- Modify: every remaining `app-icon` consumer with a numeric `[size]`

- [ ] **Step 1: Update the sidebar's existing spec to describe the shared component**

In `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`, the test at line
~117 (`renders the tag icon (tinted with its colour) when set, else the colour
dot`) asserts on `.dot` and `app-icon` inside `.lead`. Those selectors still
resolve after the swap because `<app-tag-glyph>` renders exactly those elements.
**Leave the assertions unchanged** — that is the point: an unmodified test
passing across the refactor proves the rendering did not change.

- [ ] **Step 2: Swap the sidebar**

Replace `frontend/src/app/reader/sidebar/sidebar.component.html:110-123` (the
`@if (node.tag.icon) { … } @else { … }` block inside `<span class="lead">`) with:

```html
<app-tag-glyph [name]="node.tag.icon" [color]="node.tag.color" size="md" />
```

Add `TagGlyphComponent` to the component's `imports`, and remove `IconComponent`
from them **only if** no other `app-icon` remains in that template (the sidebar
has several, so it stays). Delete the `.dot` block from
`sidebar.component.scss:162-167`.

- [ ] **Step 3: Swap `source-tags`**

Replace `frontend/src/app/reader/source-tags/source-tags.component.html:12-17` with:

```html
<app-tag-glyph [name]="t.icon" [color]="t.color" size="xs" />
```

Delete the `.dot` rule from `source-tags.component.scss`.

- [ ] **Step 4: Swap `add-feed-dialog`**

This site tints by selection state. Replace the whole `@if (t.icon) { … } @else { … }`
block at `add-feed-dialog.component.html:33-47` with a single element whose
colour expression is written once instead of twice:

```html
<app-tag-glyph
  [name]="t.icon"
  [color]="checked().has(t.id) ? 'var(--on-accent)' : t.color"
  size="sm"
/>
```

Delete the `.dot` rule from `add-feed-dialog.component.scss`.

- [ ] **Step 5: Swap the two discover sites — this is where the bug gets fixed**

In `category-rail.component.html`, replace the `<app-icon>` inside `<span class="lead">` with:

```html
<app-tag-glyph [name]="category.icon" [color]="category.color" size="md" />
```

In `category-chips.component.html`, replace the `<app-icon>` with:

```html
<app-tag-glyph
  [name]="category.icon"
  [color]="category.id === activeId() ? 'currentColor' : category.color"
  size="sm"
/>
```

Both previously rendered nothing at all for a category with no icon. They now
render the colour dot.

Swap `IconComponent` for `TagGlyphComponent` in both components' `imports`, and
delete the now-unused `.lead { width: 18px }` rule from `category-rail`'s styles
(the glyph component sizes itself).

- [ ] **Step 6: Add a regression test for the fixed fallback**

Append to `frontend/src/app/discover/category-rail.component.spec.ts` (create the
file if it does not exist, mirroring the mount helper used by
`category-chips.component.spec.ts` if present, otherwise the `Host` pattern from
Task 11):

```ts
it('renders the colour dot for a category with no icon', async () => {
  const fixture = await mount({
    categories: [{ id: 1, name: 'Plain', color: '#c08a3e', icon: null, feeds: [] }],
    picked: {},
  });
  const el: HTMLElement = fixture.nativeElement;
  expect(el.querySelector('.material-symbols-outlined')).toBeNull();
  expect(el.querySelector('.dot')).not.toBeNull();
});
```

Adjust the `CatalogCategoryDto` literal to match the real shape in
`frontend/src/app/discover/catalog.models.ts`.

- [ ] **Step 7: Fix every remaining numeric `[size]`**

```bash
grep -rn '\[size\]="[0-9]' src/app
```

Convert each to the named union using the mapping above. `app-spinner` also has
a numeric `size` — leave it; only `app-icon` changed.

- [ ] **Step 8: Verify**

```bash
npm run check
```

Expected: PASS, including the unmodified sidebar assertion from Step 1.

- [ ] **Step 9: Commit**

```bash
git add src/app
git commit -m "refactor(ui): render every tag and category through TagGlyphComponent (#126)"
```

---

## Task 13: `<app-field>` and the global control styles

**Files:**
- Create: `frontend/src/app/shared/field/field.component.spec.ts`
- Create: `frontend/src/app/shared/field/field.component.ts`
- Create: `frontend/src/app/shared/field/field.component.html`
- Create: `frontend/src/app/shared/field/field.component.scss`
- Create: `frontend/src/styles/_controls.scss`
- Modify: `frontend/src/styles.scss`
- Modify: `frontend/src/styles/_base.scss` (delete the `.field` block, lines 21-48)

- [ ] **Step 1: Write the failing tests**

`frontend/src/app/shared/field/field.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { FieldComponent } from './field.component';

@Component({
  imports: [FieldComponent],
  template: `
    <app-field [label]="label()" [error]="error()" [required]="required()">
      <input id="probe" />
    </app-field>
  `,
})
class Host {
  readonly label = signal('Name');
  readonly error = signal<string | null>(null);
  readonly required = signal(false);
}

describe('FieldComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders the label and projects the control', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelector('label')?.textContent).toContain('Name');
    expect(el.querySelector('input#probe')).not.toBeNull();
  });

  it('shows no error region until an error is set', async () => {
    const fixture = await mount();
    expect(fixture.nativeElement.querySelector('.error')).toBeNull();

    fixture.componentInstance.error.set('Required');
    fixture.detectChanges();

    const error: HTMLElement = fixture.nativeElement.querySelector('.error');
    expect(error.textContent).toContain('Required');
    // Announced, so a screen reader hears the validation failure.
    expect(error.getAttribute('role')).toBe('alert');
  });

  it('marks the label when the field is required', async () => {
    const fixture = await mount();
    expect(fixture.nativeElement.querySelector('.required')).toBeNull();

    fixture.componentInstance.required.set(true);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.required')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run to verify failure**

```bash
npm test -- field.component
```

Expected: FAIL — module not found.

- [ ] **Step 3: Create the component**

`frontend/src/app/shared/field/field.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * Form field layout: label, optional required marker, the projected control,
 * and an optional error.
 *
 * Deliberately not a ControlValueAccessor. The native control stays in the
 * consumer's template with its own formControlName, so `type`,
 * `autocomplete`, `inputmode` and the rest need no re-exposure as inputs; this
 * component owns only what was being retyped — the label, the rhythm and the
 * error slot. The projected control is styled globally by styles/_controls.scss
 * because ViewEncapsulation does not reach projected content.
 */
@Component({
  selector: 'app-field',
  templateUrl: './field.component.html',
  styleUrl: './field.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FieldComponent {
  readonly label = input.required<string>();
  readonly error = input<string | null>(null);
  readonly hint = input<string | null>(null);
  readonly required = input(false);
}
```

`frontend/src/app/shared/field/field.component.html`:

```html
<label>
  <span class="lbl">
    {{ label() }}
    @if (required()) {
      <span class="required" aria-hidden="true">*</span>
    }
  </span>
  <ng-content />
</label>
@if (hint(); as text) {
  <p class="hint">{{ text }}</p>
}
@if (error(); as text) {
  <p class="error" role="alert">{{ text }}</p>
}
```

`frontend/src/app/shared/field/field.component.scss`:

```scss
:host {
  display: block;
  margin-bottom: var(--space-4);
}

label {
  display: block;
}

.lbl {
  display: block;
  margin-bottom: var(--space-1);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
}

.required {
  color: var(--danger);
}

.hint,
.error {
  margin: var(--space-1) 0 0;
  font-size: var(--fs-sm);
}

.hint {
  color: var(--text-muted);
}

.error {
  color: var(--danger);
}
```

- [ ] **Step 4: Create the global control styles**

`frontend/src/styles/_controls.scss`:

```scss
// src/styles/_controls.scss
// Styles for the native controls projected into <app-field>. Global rather
// than component-scoped because Angular's ViewEncapsulation does not style
// projected content, and because a bare <input> outside a field should still
// look like the rest of the app.
input:not([type='checkbox']):not([type='radio']):not([type='color']),
select,
textarea {
  width: 100%;
  padding: 0 var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-1);
  color: var(--text-primary);
  font: inherit;
}

input:not([type='checkbox']):not([type='radio']):not([type='color']),
select {
  height: var(--control-h);
}

textarea {
  padding: var(--space-2) var(--space-3);
  line-height: var(--lh-normal);
}

input:focus,
select:focus,
textarea:focus {
  border-color: var(--accent);
  outline: none;
}

input:disabled,
select:disabled,
textarea:disabled {
  cursor: default;
  opacity: 0.7;
}
```

- [ ] **Step 5: Wire it up and remove the superseded `.field` class**

In `frontend/src/styles.scss`, add `@use './styles/controls';` after `@use './styles/base';`.

In `frontend/src/styles/_base.scss`, delete lines 21–48 (the `// Shared form field:` comment and the four `.field*` rules). Everything above stays.

- [ ] **Step 6: Run to verify the tests pass**

```bash
npm test -- field.component
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/app/shared/field src/styles src/styles.scss
git commit -m "feat(shared): add FieldComponent and global control styles (#126)"
```

---

## Task 14: Adopt `<app-field>`

**Files:**
- Modify: the five `auth/**/*.component.html` files (they use the deleted `.field` class)
- Modify: `frontend/src/app/admin/admin-catalog.component.html` (18 raw controls)
- Modify: `frontend/src/app/settings/opml-section.component.html`
- Modify: `frontend/src/app/reader/manage/tag-form-dialog.component.html`, `edit-subscription-dialog.component.html`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.html`

- [ ] **Step 1: Find every consumer of the deleted class**

```bash
grep -rn 'class="field"' src/app
```

Each match is an `auth` form. Convert each from:

```html
<label class="field">
  <span>Email</span>
  <input type="email" formControlName="email" />
</label>
```

to:

```html
<app-field label="Email">
  <input type="email" formControlName="email" />
</app-field>
```

Add `FieldComponent` to each component's `imports`. Where the form currently
renders its own error markup below the input, move the message into the
`[error]` input rather than leaving two error mechanisms.

- [ ] **Step 2: Convert `admin-catalog`**

Its 18 raw `<input>`/`<select>` elements are the densest form in the app. Wrap
each in an `<app-field>` with the label text that currently sits beside it, and
delete the hand-rolled label/spacing CSS from `admin-catalog.component.scss`
that the component no longer needs.

- [ ] **Step 3: Convert the remaining sites**

`settings/opml-section` (2 controls), `tag-form-dialog` (2), `edit-subscription-dialog` (1), `add-feed-dialog` (1).

- [ ] **Step 4: Verify no orphaned `.field` styling remains**

```bash
grep -rn 'class="field"\|\.field' src/app src/styles
```

Expected: no matches.

- [ ] **Step 5: Run the gate**

```bash
npm run check
```

Expected: PASS. Auth specs assert on `input` elements by `formControlName` or
type, not by the wrapper, so they should not need changes. If one asserts on
`label.field`, update the selector to `app-field label`.

- [ ] **Step 6: Commit**

```bash
git add src/app
git commit -m "refactor(forms): render every form field through FieldComponent (#126)"
```

---

## Task 15: `<app-color-field>` and the second icon picker

**Files:**
- Create: `frontend/src/app/shared/color-field/color-field.component.spec.ts`
- Create: `frontend/src/app/shared/color-field/color-field.component.ts`
- Create: `frontend/src/app/shared/color-field/color-field.component.html`
- Create: `frontend/src/app/shared/color-field/color-field.component.scss`
- Modify: `frontend/src/app/reader/manage/tag-form-dialog.component.html` + `.ts` + `.scss`
- Modify: `frontend/src/app/admin/admin-catalog.component.html` + `.ts`

- [ ] **Step 1: Write the failing tests**

`frontend/src/app/shared/color-field/color-field.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { ColorFieldComponent } from './color-field.component';
import { TAG_COLORS } from '../icon-choices';

describe('ColorFieldComponent', () => {
  const mount = async (value: string | null = null) => {
    await TestBed.configureTestingModule({ imports: [ColorFieldComponent] }).compileComponents();
    const fixture = TestBed.createComponent(ColorFieldComponent);
    fixture.componentRef.setInput('value', value);
    fixture.detectChanges();
    return fixture;
  };

  it('renders one button per preset swatch', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelectorAll('.swatch').length).toBe(TAG_COLORS.length);
  });

  it('marks the swatch matching the current value', async () => {
    const el: HTMLElement = (await mount(TAG_COLORS[0])).nativeElement;
    expect(el.querySelector('.swatch.on')).not.toBeNull();
  });

  it('emits the picked colour', async () => {
    const fixture = await mount();
    const picked: (string | null)[] = [];
    fixture.componentInstance.valueChange.subscribe((v: string | null) => picked.push(v));

    (fixture.nativeElement.querySelector('.swatch') as HTMLButtonElement).click();
    expect(picked).toEqual([TAG_COLORS[0]]);
  });

  it('emits null when cleared', async () => {
    const fixture = await mount(TAG_COLORS[0]);
    const picked: (string | null)[] = [];
    fixture.componentInstance.valueChange.subscribe((v: string | null) => picked.push(v));

    (fixture.nativeElement.querySelector('.clear') as HTMLButtonElement).click();
    expect(picked).toEqual([null]);
  });
});
```

- [ ] **Step 2: Run to verify failure**

```bash
npm test -- color-field
```

Expected: FAIL — module not found.

- [ ] **Step 3: Read the current implementation before replacing it**

```bash
sed -n '1,40p' src/app/reader/manage/tag-form-dialog.component.html
cat src/app/shared/icon-choices.ts
```

The preset row, the native colour input and the clear button already exist in
that template, and the palette itself already lives in
`src/app/shared/icon-choices.ts` as `TAG_COLORS`. The new component imports that
rather than restating it — `icon-choices.ts` stays the one place the palette is
defined. Note the presets are hex, which is fine: `color-no-hex` is a Stylelint
rule and applies to SCSS only, and these values are TypeScript.

Carry the native picker's existing fallback value (`'#3f8676'`, see
`tag-form-dialog.component.html:29`) across too — a colour input has no "unset"
state, so it needs a concrete default when the tag has no colour.

- [ ] **Step 4: Create the component**

`frontend/src/app/shared/color-field/color-field.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TAG_COLORS } from '../icon-choices';

/**
 * Colour chooser: a row of presets, a native picker for anything else, and a
 * clear button for "no colour". Lifted out of the tag dialog so the admin
 * catalog's category colour is chosen the same way.
 *
 * The presets come from shared/icon-choices, which already held them — this
 * component is a move, not a redesign, and that module stays the single place
 * the palette is defined.
 *
 * Not a ControlValueAccessor: both consumers drive it from a signal rather than
 * a form control, and value/valueChange keeps it usable with either.
 */
@Component({
  selector: 'app-color-field',
  templateUrl: './color-field.component.html',
  styleUrl: './color-field.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ColorFieldComponent {
  readonly value = input<string | null>(null);
  readonly valueChange = output<string | null>();

  protected readonly presets = TAG_COLORS;

  /** A colour input has no "unset" state, so it needs a concrete default. */
  protected readonly fallback = '#3f8676';

  protected pick(color: string): void {
    this.valueChange.emit(color);
  }

  protected clear(): void {
    this.valueChange.emit(null);
  }
}
```

`frontend/src/app/shared/color-field/color-field.component.html`:

```html
<div class="swatches">
  @for (preset of presets; track preset) {
    <button
      type="button"
      class="swatch"
      [class.on]="preset === value()"
      [style.background]="preset"
      [attr.aria-pressed]="preset === value()"
      (click)="pick(preset)"
    ></button>
  }
  <input
    type="color"
    class="picker"
    [value]="value() ?? fallback"
    (input)="pick($any($event.target).value)"
  />
  <button type="button" class="clear" (click)="clear()">
    <ng-content />
  </button>
</div>
```

`frontend/src/app/shared/color-field/color-field.component.scss`:

```scss
.swatches {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  align-items: center;
}

.swatch {
  width: var(--space-5);
  height: var(--space-5);
  border: 2px solid var(--border);
  border-radius: var(--radius-pill);
  cursor: pointer;
}

.swatch.on {
  border-color: var(--text-primary);
}

.picker {
  width: var(--space-6);
  height: var(--space-5);
  padding: 0;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: none;
  cursor: pointer;
}

.clear {
  padding: 0 var(--space-2);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-1);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  cursor: pointer;
}
```

- [ ] **Step 5: Adopt in `tag-form-dialog`, and drop its duplicate icon grid**

Replace the swatch/picker/clear markup in `tag-form-dialog.component.html` with
`<app-color-field [value]="color()" (valueChange)="color.set($event)">{{ 'common.clear' | transloco }}</app-color-field>`
(match the existing translation key for the clear label).

Then replace its hand-rolled icon grid with the shared picker that `admin-catalog`
already uses: import `IconPickerComponent` from `src/app/shared/icon-picker/` and
bind it to the same signal the grid wrote to. Delete the `.swatches`, `.swatch`,
`.picker`, `.clear`, `.icons` and `.icon` rules from
`tag-form-dialog.component.scss` — roughly 60 lines, and the app's second icon
picker implementation, gone.

- [ ] **Step 6: Adopt in `admin-catalog`**

Replace its colour input with `<app-color-field>`.

- [ ] **Step 7: Verify**

```bash
npm run check
```

Expected: PASS. `tag-form-dialog.component.spec.ts` asserts on the icon grid and
swatches; update its selectors to the shared components' (`app-color-field .swatch`,
`app-icon-picker`), keeping the behavioural assertions identical.

- [ ] **Step 8: Commit**

```bash
git add src/app
git commit -m "feat(shared): add ColorFieldComponent and drop the duplicate icon grid (#126)"
```

---

## Task 16: `<app-overlay-panel>`

**Files:**
- Create: `frontend/src/app/shared/overlay-panel/overlay-panel.component.spec.ts`
- Create: `frontend/src/app/shared/overlay-panel/overlay-panel.component.ts`
- Create: `frontend/src/app/shared/overlay-panel/overlay-panel.component.html`
- Create: `frontend/src/app/shared/overlay-panel/overlay-panel.component.scss`
- Modify: `frontend/src/styles.scss` (the `.app-dialog` panelClass)
- Modify: the four reader dialogs and their opener call sites
- Modify: `frontend/src/app/discover/discover.component.html` + `.scss`

- [ ] **Step 1: Write the failing tests**

`frontend/src/app/shared/overlay-panel/overlay-panel.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { OverlayPanelComponent } from './overlay-panel.component';

@Component({
  imports: [OverlayPanelComponent],
  template: `
    <app-overlay-panel heading="Edit tag">
      <p class="body-probe">body</p>
      <button footer class="footer-probe">Save</button>
    </app-overlay-panel>
  `,
})
class Host {}

describe('OverlayPanelComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders the title as the panel heading', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelector('h2')?.textContent?.trim()).toBe('Edit tag');
  });

  it('projects body content into the scrolling region', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelector('.body .body-probe')).not.toBeNull();
  });

  it('projects footer content into the footer, not the body', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelector('.footer .footer-probe')).not.toBeNull();
    expect(el.querySelector('.body .footer-probe')).toBeNull();
  });

  it('labels the panel with its heading for assistive tech', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    const panel = el.querySelector('.panel') as HTMLElement;
    const heading = el.querySelector('h2') as HTMLElement;
    expect(panel.getAttribute('aria-labelledby')).toBe(heading.id);
    expect(heading.id).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run to verify failure**

```bash
npm test -- overlay-panel
```

Expected: FAIL — module not found.

- [ ] **Step 3: Create the component**

`frontend/src/app/shared/overlay-panel/overlay-panel.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';

let nextId = 0;

/**
 * The frame every interrupt surface renders inside: a centred card on desktop,
 * full screen on a phone. Owns the heading, the scrolling body and the footer
 * row, so a dialog's own stylesheet carries only what is specific to it.
 *
 * Width is the one dimension that legitimately varies per consumer, so it is
 * read from --panel-w rather than being an input — that keeps it in the
 * stylesheet where the rest of the sizing lives.
 */
@Component({
  selector: 'app-overlay-panel',
  templateUrl: './overlay-panel.component.html',
  styleUrl: './overlay-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OverlayPanelComponent {
  /**
   * Named `heading`, not `title`: an input called `title` on a component host
   * collides with the native attribute and would render a stray browser
   * tooltip over every dialog.
   */
  readonly heading = input.required<string>();

  /** Ties the panel to its heading; unique so several panels can coexist. */
  protected readonly headingId = `overlay-panel-heading-${nextId++}`;
}
```

`frontend/src/app/shared/overlay-panel/overlay-panel.component.html`:

```html
<section class="panel" role="dialog" aria-modal="true" [attr.aria-labelledby]="headingId">
  <header class="head">
    <h2 [id]="headingId">{{ heading() }}</h2>
    <ng-content select="[headerActions]" />
  </header>
  <div class="body">
    <ng-content />
  </div>
  <footer class="footer">
    <ng-content select="[footer]" />
  </footer>
</section>
```

`frontend/src/app/shared/overlay-panel/overlay-panel.component.scss`:

```scss
@use '../../theme/breakpoints' as bp;

:host {
  display: contents;
}

.panel {
  display: flex;
  flex-direction: column;
  width: min(var(--panel-w, 460px), 92vw);

  /* Never taller than the viewport -- the body scrolls instead (#85).
     Reachable in landscape on a phone. `vh` first as the fallback for
     browsers without `dvh`. */
  max-height: 90vh;
  max-height: 90dvh;
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-2);
}

.head {
  display: flex;
  gap: var(--space-3);
  align-items: center;
  justify-content: space-between;
  padding: var(--space-5) var(--space-5) var(--space-3);
}

h2 {
  margin: 0;
  font-size: var(--fs-lg);
}

.body {
  min-height: 0;
  padding: 0 var(--space-5);
  overflow-y: auto;
  overscroll-behavior: contain;
}

.footer {
  display: flex;
  gap: var(--space-2);
  justify-content: flex-end;
  padding: var(--space-3) var(--space-5) var(--space-5);
}

.footer:empty {
  display: none;
}

/* On a phone the panel is the screen: no rounding, no margin, and the full
   height so the body's scroll region is as tall as it can be. */
@media (width <= bp.$bp-sm) {
  .panel {
    width: 100vw;
    max-width: 100vw;
    height: 100dvh;
    max-height: 100dvh;
    border: 0;
    border-radius: 0;
  }
}
```

- [ ] **Step 4: Add the global panelClass**

Append to `frontend/src/styles.scss`:

```scss
// Passed as CDK Dialog's panelClass so every dialog gets the same frame. The
// CDK's own container carries no sizing, so this is where the responsive rule
// has to live.
.app-dialog .cdk-dialog-container {
  outline: none;
}
```

- [ ] **Step 5: Run to verify the tests pass**

```bash
npm test -- overlay-panel
```

Expected: PASS.

- [ ] **Step 6: Commit the component before converting consumers**

```bash
git add src/app/shared/overlay-panel src/styles.scss
git commit -m "feat(shared): add OverlayPanelComponent for interrupt surfaces (#126)"
```

---

## Task 17: Adopt `<app-overlay-panel>` in the four dialogs and discover

**Files:**
- Modify: `frontend/src/app/reader/manage/confirm-dialog.component.{html,ts,scss}`
- Modify: `frontend/src/app/reader/manage/tag-form-dialog.component.{html,ts,scss}`
- Modify: `frontend/src/app/reader/manage/edit-subscription-dialog.component.{html,ts,scss}`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.{html,ts,scss}`
- Modify: `frontend/src/app/reader/manage/manage-actions.service.ts:28,72,80,87,102`
- Modify: `frontend/src/app/reader/reader-shell.component.ts:554`
- Modify: `frontend/src/app/discover/discover.component.{html,scss}`

- [ ] **Step 1: Convert one dialog and prove the pattern**

Start with `confirm-dialog` — the smallest. Wrap its content:

```html
<app-overlay-panel [heading]="data.title">
  <p>{{ data.message }}</p>
  <app-button footer (click)="ref.close(false)">{{ 'common.cancel' | transloco }}</app-button>
  <app-button footer variant="primary" (click)="ref.close(true)">
    {{ 'common.confirm' | transloco }}
  </app-button>
</app-overlay-panel>
```

Delete the `.dialog`, `h2` and `.row` rules from its SCSS — the panel owns them
now. Match the real translation keys and `DIALOG_DATA` shape from the existing file.

- [ ] **Step 2: Pass the panelClass at the call sites**

Every `dialog.open(...)` gains `panelClass: 'app-dialog'`:

```ts
const ref = this.dialog.open<boolean>(ConfirmDialogComponent, { data, panelClass: 'app-dialog' });
```

Five call sites in `manage-actions.service.ts` (lines 28, 72, 80, 87, 102) and
one in `reader-shell.component.ts` (line 554).

- [ ] **Step 3: Verify the first conversion before doing the rest**

```bash
npm test -- confirm-dialog manage-actions
```

Expected: PASS.

- [ ] **Step 4: Convert the other three dialogs**

Same shape. `tag-form-dialog` and `add-feed-dialog` set a wider panel where they
need one, via `--panel-w` on the host:

```scss
:host {
  --panel-w: 460px;
}
```

- [ ] **Step 5: Convert discover**

`discover.component.html` keeps its own `.scrim` (it is a route, not a CDK
dialog, so nothing else provides one) but its `.panel` markup is replaced by
`<app-overlay-panel>`. Set the width on the host:

```scss
:host {
  --panel-w: 1040px;
}
```

Delete the `.panel`, `.top` frame rules from `discover.component.scss` that the
shared panel now owns. Keep the `.scrim` rule and the `:host { position: fixed; inset: 0; … }`
block — those are discover's own, not the panel's.

- [ ] **Step 6: Verify**

```bash
npm run check
```

Expected: PASS. Dialog specs assert on `.dialog`-scoped selectors; update them to
the panel's (`.panel`, `.body`, `.footer`) keeping the behavioural assertions identical.

- [ ] **Step 7: Commit**

```bash
git add src/app
git commit -m "refactor(ui): frame every interrupt surface with OverlayPanelComponent (#126)"
```

---

## Task 18: Extend and adopt `shared/button`

**Files:**
- Modify: `frontend/src/app/shared/button/button.component.ts`
- Modify: `frontend/src/app/shared/button/button.component.html`
- Modify: `frontend/src/app/shared/button/button.component.scss`
- Create: `frontend/src/app/shared/button/button.component.spec.ts` (if absent; check `shared/shared.spec.ts` first)
- Modify: consumers across `reader/`, `admin/`, `settings/`, `discover/`

- [ ] **Step 1: Write the failing tests**

Check whether `frontend/src/app/shared/shared.spec.ts` already covers the button;
extend it rather than duplicating if so. Otherwise create
`frontend/src/app/shared/button/button.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { ButtonComponent } from './button.component';

@Component({
  imports: [ButtonComponent],
  template: `<app-button [variant]="variant()" [loading]="loading()">Save</app-button>`,
})
class Host {
  readonly variant = signal<'default' | 'primary' | 'danger' | 'ghost'>('default');
  readonly loading = signal(false);
}

describe('ButtonComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('carries the variant as a class', async () => {
    const fixture = await mount();
    const button = () => fixture.nativeElement.querySelector('button') as HTMLElement;

    expect(button().classList.contains('primary')).toBe(false);

    fixture.componentInstance.variant.set('danger');
    fixture.detectChanges();
    expect(button().classList.contains('danger')).toBe(true);
  });

  it('swaps the label for a spinner and disables while loading', async () => {
    const fixture = await mount();
    fixture.componentInstance.loading.set(true);
    fixture.detectChanges();

    const button = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(fixture.nativeElement.querySelector('app-spinner')).not.toBeNull();
    expect(button.textContent?.trim()).toBe('');
  });
});
```

- [ ] **Step 2: Run to verify failure**

```bash
npm test -- button.component
```

Expected: FAIL on the `danger` variant — the type does not accept it yet.

- [ ] **Step 3: Rewrite the component**

`frontend/src/app/shared/button/button.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { SpinnerComponent } from '../spinner/spinner.component';

export type ButtonVariant = 'default' | 'primary' | 'danger' | 'ghost';

@Component({
  selector: 'app-button',
  imports: [SpinnerComponent],
  templateUrl: './button.component.html',
  styleUrl: './button.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ButtonComponent {
  readonly type = input<'button' | 'submit'>('button');
  readonly variant = input<ButtonVariant>('default');
  readonly size = input<'sm' | 'md'>('md');
  readonly loading = input(false);
  readonly disabled = input(false);

  /** Auth forms want a full-width submit; toolbars do not. */
  readonly block = input(false);
}
```

`frontend/src/app/shared/button/button.component.html`:

```html
<button
  [type]="type()"
  [disabled]="loading() || disabled()"
  [class]="variant()"
  [class.sm]="size() === 'sm'"
  [class.block]="block()"
>
  @if (loading()) {
    <app-spinner [size]="16" />
  } @else {
    <ng-content />
  }
</button>
```

`frontend/src/app/shared/button/button.component.scss`:

```scss
button {
  display: inline-flex;
  height: var(--control-h);
  padding: 0 var(--space-4);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-1);
  color: var(--text-primary);
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

/* Full width is what an auth submit wants and what a toolbar button must not
   have. It used to be unconditional here, which is why every other surface
   hand-rolled its own button rather than reaching for this component. */
button.block {
  width: 100%;
}

button.sm {
  height: var(--space-6);
  padding: 0 var(--space-3);
  font-size: var(--fs-sm);
}

button.primary {
  border-color: var(--accent);
  background: var(--accent);
  color: var(--on-accent);
}

button.danger {
  border-color: var(--danger);
  background: var(--bg-danger);
  color: var(--danger);
}

button.ghost {
  border-color: transparent;
  background: none;
  color: var(--text-secondary);
}

button.ghost:hover {
  background: var(--surface-2);
  color: var(--text-primary);
}

/* The default spinner's signal colour is the accent, which would vanish on a
   primary button's accent fill; retint it to the on-accent colour instead. */
button.primary app-spinner {
  --spin-sig: var(--on-accent);
  --spin-dim: color-mix(in srgb, var(--on-accent) 35%, transparent);
}

button:disabled {
  cursor: default;
  opacity: 0.7;
}
```

- [ ] **Step 4: Restore full width where it was assumed**

The five auth forms currently rely on the unconditional `width: 100%`. Add
`block` to each auth `<app-button>`:

```html
<app-button type="submit" variant="primary" block [loading]="submitting()">…</app-button>
```

- [ ] **Step 5: Run the tests**

```bash
npm test -- button auth
```

Expected: PASS.

- [ ] **Step 6: Adopt across the remaining surfaces**

Convert hand-rolled buttons to `<app-button>` where they are ordinary
action buttons — the dialog footers (already restructured in Task 17), the
settings actions, the admin catalog actions, discover's footer actions.

**Do not convert** icon-only affordances that are not really buttons in this
sense: `sidebar`'s `.dots` menu triggers, `entry-row`'s read toggles,
`view-controls`' segmented control, `to-top-button`. Those carry their own
interaction semantics and forcing them through this component would make it a
grab bag. Note that boundary in `docs/design-language.md` in Task 20.

- [ ] **Step 7: Verify**

```bash
npm run check
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/app
git commit -m "refactor(shared): give ButtonComponent real variants and adopt it app-wide (#126)"
```

---

## Task 19: Adopt the density tokens and audit the sticky surfaces

Task 1 *defined* `--row-pad-y` / `--row-pad-x` but nothing consumes them yet.
Until something does, the picker rail and the reader's lists can still drift —
which is the specific complaint in #126. This task makes them derive from one
place.

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss`
- Modify: `frontend/src/app/reader/entry-row/entry-row.component.scss`
- Modify: `frontend/src/app/discover/category-rail.component.scss`
- Modify: `frontend/src/app/discover/category-chips.component.scss`
- Modify: `frontend/src/app/settings/tags-section.component.scss`
- Modify: `frontend/src/app/admin/admin-catalog.component.scss`, `admin-users.component.scss`

- [ ] **Step 1: Find every row-like surface**

```bash
grep -rn 'padding' src/app/reader/sidebar src/app/reader/entry-row src/app/discover \
  src/app/settings/tags-section.component.scss src/app/admin
```

A "row" here means the repeated, clickable line in a list, rail, chip strip or
picker — the sidebar's `.tag` and `.feedrow`, `entry-row`'s host, the rail's
`button`, the chips' `button`, the tag list's rows, the admin tables' rows.

- [ ] **Step 2: Convert each row's padding**

Replace the row's own padding with the tokens:

```scss
padding: var(--row-pad-y) var(--row-pad-x);
```

After Phase C these read `padding: var(--space-2) var(--space-3)` — which is the
same computed value, since `--row-pad-y` is defined as `var(--space-2)` and
`--row-pad-x` as `var(--space-3)`. **The point is the indirection, not the
value**: the rail and the sidebar now change together.

Leave non-row padding alone. A panel, a header, a footer or a dialog body is not
a row and must keep its own spacing token.

- [ ] **Step 3: Audit the sticky surfaces against the convention**

```bash
grep -rn 'position: sticky' src/app
```

For each hit, confirm all three hold, and fix it where they do not:

1. The sticky rule is on the **flex-child host**, not on an inner wrapper. A
   sticky element inside a content-height host has no room to stick and scrolls
   away with its parent — this is exactly the `category-rail` bug #126 reported,
   and `category-rail.component.scss` carries the comment explaining it.
2. Any element that scrolls internally has `overscroll-behavior: contain`, so a
   scroll gesture inside it does not chain to the page behind.
3. Content that starts beneath a floating bar offsets by the measured height plus
   `--bar-gap` — `calc(var(--app-bar-h, var(--bar-h)) + var(--bar-gap))` — never
   by a literal.

- [ ] **Step 4: Verify**

```bash
npm run check
```

Expected: PASS. The density conversion is value-preserving, so no test should
change. If one does, the row you converted was not a row.

- [ ] **Step 5: Commit**

```bash
git add src/app
git commit -m "refactor(ui): derive row density from tokens and align sticky surfaces (#126)"
```

---

# Phase E — Documentation

## Task 20: Write `docs/design-language.md`

**Files:**
- Create: `docs/design-language.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Write the document**

Four sections. Write it from the *implemented* state, not from this plan — read
`frontend/src/app/theme/tokens.scss` and `_breakpoints.scss` as they now stand
and tabulate them.

1. **Tokens** — one table per group (spacing, radii, sizing, icon, density,
   type), each row: token, value, what it is for. Then the breakpoint partial
   and the `@media (width <= bp.$bp-md)` usage, including *why* they are SCSS
   variables rather than custom properties.
2. **Component catalog** — `<app-tag-glyph>`, `<app-field>`, `<app-color-field>`,
   `<app-icon-picker>`, `<app-overlay-panel>`, `<app-button>`, `<app-icon>`. For
   each: selector, inputs with types, a usage snippet, and when *not* to use it
   (the Task 18 Step 6 boundary for buttons especially).
3. **Conventions** — density (`--row-pad-y`/`--row-pad-x` on every list, rail and
   picker row); sticky and scroll (stickiness on the flex-child host, never an
   inner wrapper — with the `category-rail` explanation of why; `overscroll-behavior: contain`
   on internal scrollers; content offsets by the measured bar height plus
   `--bar-gap`, never a literal); the overlay convention; form layout.
4. **Adding a new surface** — a checklist: reach for the shared component before
   writing markup; derive every spacing value from a token; `@use` the
   breakpoint partial; use `--row-pad-*` for rows; put stickiness on the host;
   run `npm run check`.

Also record the escape hatch: a tuned component dimension may use
`/* stylelint-disable-next-line declaration-property-unit-allowed-list -- … */`
with a reason, and list the ones that legitimately do (rail width, discover
panel width, dialog width, icon-grid cap, pull-to-refresh offset).

- [ ] **Step 2: Update CLAUDE.md**

In the "Frontend conventions" section:

- Add: a line pointing at `docs/design-language.md` as the source for tokens,
  shared components and conventions, and stating that ad-hoc `px` and hex
  outside `theme/`/`styles/` fail `npm run check`.
- Correct the stale line claiming component styles are inline in the `.ts`.
  Commit `496d06d` extracted them into `.scss` files, and Task 8 of this plan
  extracted the last two in `discover/`. The convention is now: styles live in a
  sibling `.scss` file.
- Keep the existing `color-no-hex` line; it is still true.

- [ ] **Step 3: Verify the document matches reality**

Spot-check three claims against the code: pick one token from each table and
confirm its value in `tokens.scss`; confirm one component's input list against
its `.ts`; confirm the breakpoint values.

- [ ] **Step 4: Commit**

```bash
git add docs/design-language.md CLAUDE.md
git commit -m "docs: document the unified design language (#126)"
```

---

# Phase F — Verification

## Task 21: Manual visual verification

**This task is required, not optional.** Green CI does not mean this sweep is
safe: Jest cannot see a visual regression, and the change deliberately moves
~25 spacing values by 1–3px, collapses seven breakpoints to three (moving some
layout switch points by up to 80px), and normalises five glyph sizes onto four
tokens.

- [ ] **Step 1: Bring the stack up**

```bash
docker compose up -d
```

- [ ] **Step 2: Run the full gate one more time**

```bash
cd frontend && npm run check
```

Expected: PASS.

- [ ] **Step 3: Walk every surface at three widths, in both themes**

At 375px, 720px and 1280px, in light and dark:

| Surface | Look for |
|---|---|
| Reader — list view | Row density, the sidebar tag glyphs, floating bar offsets, no clipped headers |
| Reader — magazine view | Hero and source-group spacing |
| Reader — article view | The full-screen layer from #128 still isolates correctly |
| Sidebar | Tag glyphs at `md`; long names still truncate (#131) |
| Discover | The rail pinned; **a category with no icon shows a dot, not a blank** |
| Discover at 720px | The chips row replaces the rail at the new breakpoint |
| Dialogs (add feed, edit subscription, tag form, confirm) | Centred on desktop, **full screen at 375px**; body scrolls, footer stays |
| Settings | Section rhythm, the 56px bar |
| Admin catalog | The 18 converted fields, the colour field, the icon picker |
| Auth forms | Full-width submit buttons still full width (the `block` input) |

- [ ] **Step 4: Check the backend log for anything the frontend provoked**

```bash
tail -50 backend/var/log/dev.log
```

- [ ] **Step 5: Run the Playwright smokes**

```bash
cd frontend && npm run e2e
```

Note: these need the Docker stack up, and the admin e2e account needs at least
one subscription or onboarding redirects it and the logins time out.

- [ ] **Step 6: Record what you found**

Write the results into the PR description — which surfaces you checked, at which
widths, and any deliberate visual change a reviewer should expect. A reviewer
cannot tell an intended 2px shift from an accident without being told.

---

## Task 22: Open the pull request

- [ ] **Step 1: Confirm the branch is green and pushed**

```bash
cd frontend && npm run check && cd .. && git push -u origin feature/126-unified-design-language
```

- [ ] **Step 2: Open the PR against `develop`**

```bash
gh pr create --base develop --title "Introduce a unified design language (#126)" --body-file -
```

The body must cover: what changed per phase, the three categories of intentional
visual change, the surfaces verified in Task 21, and `Closes #126`.

- [ ] **Step 3: Confirm CI is actually green**

```bash
gh run watch --exit-status
```

`--exit-status` is unreliable here — it has returned 0 on a failed run before.
Re-read the conclusion explicitly:

```bash
gh run list --branch feature/126-unified-design-language --limit 1
```

---

## Fallback

If review of Tasks 9–10 (the reader migration) proves unmanageable as one diff,
the spec's stated fallback applies: split Phase D (Tasks 11–19, the shared
components and the density adoption) into a follow-up PR, leaving Phases A–C
plus documentation as a self-contained change. Phase D depends on Phase A but
nothing in Phases A–C depends on Phase D, so the cut is clean.
