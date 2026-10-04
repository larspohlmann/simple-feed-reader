# Text-Size Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A text-size stepper in the reader sidebar, directly above the brightness control, that scales all text in the lists and the article (90–150 %) while every control keeps its size.

**Architecture:** A per-device percentage (`localStorage` key `sfr.textSize`) becomes one CSS custom property, `--text-scale`, on `<html>`. The no-flash script in `index.html` sets it before the first paint, and `TextSizeService` keeps it in sync afterwards. A global `.text-scaled` class, placed only on the reading containers, multiplies the `--fs-*` type tokens and the inherited font size by `--text-scale`. Inside those containers a zero-specificity rule puts the tokens back to fixed and divides the inherited size back out, for buttons and the tag/saved-search pills. Images, widths, gaps and icons are all fixed-px tokens, so they stay untouched.

**Tech Stack:** Angular 20 (standalone, signals), Sass, Transloco, Jest (jsdom), Playwright.

**Spec:** GitHub issue [#1382](https://github.com/larspohlmann/simple-feed-reader/issues/1382), agreed in a grilling session on 2026-10-04.

## Global Constraints

- Steps exactly `90, 100, 110, 120, 130, 140, 150` (percent); default `100`.
- Storage: `localStorage` key `sfr.textSize`, per device, independent of theme, never sent to the backend.
- Placement: in `SidebarFootComponent`, directly above `<app-brightness-control>`, rendered under the same `@if (!organising())` condition (so it appears in the narrow-screen drawer too).
- Look: the brightness stepper's shape. A `text_decrease` button, then a bar (fill plus the visible percentage; a click resets to 100), then a `text_increase` button. The end buttons disable at 90 and 150. There is an sr-only `<output aria-live="polite">`.
- Scales: all text in the lists (list, magazine and pane layouts, including run headers, source-group headers, the recommendation strip, empty/error states and `.foot` text) and in the article (title, meta, body, TOC entries, comments, paywall text, error texts, the "select an article" placeholder).
- Fixed: every `<button>` that looks like a control and the tag/saved-search pills (`app-entry-pills`). The header texts stay fixed too: the list header's title and count pill (e.g. "Politics 5596"), the reader toolbar's title and button labels ("Reader view", "Reload article", "Keep", "Favorite"), and the fullscreen `.mini` strip. Also fixed: icons, images and thumbnails, widths (720px measure), gaps and padding, the sidebar, the app bar, and the settings/admin/login pages.
- Line clamps keep their line counts, so cards grow taller. This needs no code: clamps are line counts already.
- No keyboard shortcuts.
- House rules: component styles go in a sibling `.scss`; no hex or ad-hoc px outside `theme/`/`styles/`; Prettier at 100 columns; comments only when a reader would otherwise get the code wrong, one line preferred.
- Commit format: `feat(#1382): …` (lower-case summary).
- Frontend tests run in the Docker frontend container: `docker compose exec -T frontend npx jest <path>`. Never run two Jest runs at once (they run out of memory). Playwright needs the Docker stack up: `cd frontend && npx playwright test e2e/<spec>`.

## Decision made while planning (flag to the user if it seems wrong)

Some `<button>`s in the reading area are typeset as running text, not as controls:
- the article's TOC entries (`reader-toc` `.toc-list button`)
- the inline "Retry" link (`.note-link`)
- the "Also in: Source · 2h" duplicate entries (`.also-entry`)

Following Q10 ("links scale") and Q11 ("the TOC scales"), these get a `reads-as-text` class, which exempts them from the fixed-size reset. The TOC *toggle* ("Contents") stays fixed.

## File Structure

| File | Role |
|---|---|
| Create `frontend/src/app/theme/text-size.ts` | Steps, default, storage key, `parseTextSize()` |
| Create `frontend/src/app/theme/text-size.spec.ts` | Unit tests for `parseTextSize` |
| Create `frontend/src/app/theme/text-size.service.ts` | Signal state, persistence, `--text-scale` on `<html>` |
| Create `frontend/src/app/theme/text-size.service.spec.ts` | Service tests |
| Modify `frontend/src/index.html` | No-flash script sets `--text-scale` |
| Create `frontend/src/app/theme/_type-scale.scss` | The type-size map and `fixed`/`scaled` mixins |
| Modify `frontend/src/app/theme/tokens.scss` | Emit the `--fs-*` tokens from the map |
| Create `frontend/src/styles/_text-size.scss` | `.text-scaled` scope and the control reset |
| Modify `frontend/src/styles.scss` | `@use` the new partial, last |
| Create `frontend/src/app/theme/type-scale.spec.ts` | Compiles the Sass; pins root tokens and the scope rules |
| Modify `frontend/src/app/theme/_segmented.scss` | New `stepper-bar` mixin shared by both steppers |
| Modify `frontend/src/app/reader/shell/sidebar/brightness-control.component.scss` | Use `stepper-bar` |
| Create `frontend/src/app/reader/shell/sidebar/text-size-control.component.{ts,html,scss,spec.ts}` | The stepper |
| Modify `frontend/src/app/reader/shell/sidebar/sidebar-foot.component.{ts,html,scss,spec.ts}` | Mount it above brightness |
| Modify `frontend/public/i18n/en.json`, `de.json` | `reader.textSize.*` keys |
| Modify `frontend/src/app/reader/list/entry-list/entry-list.component.html` | `text-scaled` on the reading containers |
| Modify `frontend/src/app/reader/article/reader-view/reader-view.component.html` | `text-scaled` on `<article>` and `.placeholder`; `reads-as-text` on `.note-link` |
| Modify `frontend/src/app/reader/article/reader-toc/reader-toc.component.html` | `reads-as-text` on the entry buttons |
| Modify `frontend/src/app/reader/list/magazine/entry-duplicates.component.html` | `reads-as-text` on `.also-entry` |
| Create `frontend/e2e/text-size.spec.ts` | First-frame, and a scales-text-not-controls measurement in a real browser |
| Modify `docs/design-language.md` | Document the control and the scope rule |

---

### Task 1: Text-size state, persistence and first-frame paint

**Files:**
- Create: `frontend/src/app/theme/text-size.ts`
- Create: `frontend/src/app/theme/text-size.spec.ts`
- Create: `frontend/src/app/theme/text-size.service.ts`
- Create: `frontend/src/app/theme/text-size.service.spec.ts`
- Modify: `frontend/src/index.html` (the no-flash `<script>` in `<head>`, after the brightness lines)

**Interfaces:**
- Produces: `TEXT_SIZE_STEPS: readonly number[]`, `TEXT_SIZE_DEFAULT = 100`, `TEXT_SIZE_KEY = 'sfr.textSize'`, `parseTextSize(raw: string | null): number`.
- Produces: `TextSizeService` (`providedIn: 'root'`) with
  - `percent: Signal<number>`, `canDecrease: Signal<boolean>`, `canIncrease: Signal<boolean>`
  - `fillPercent: Signal<number>` (0 at 90, 100 at 150)
  - `set(percent: number): void`, `increase(): void`, `decrease(): void`, `reset(): void`
- Side effect: `document.documentElement.style` carries `--text-scale` = `percent / 100` as a string (e.g. `"1.1"`).

- [ ] **Step 1: Write the failing tests**

`frontend/src/app/theme/text-size.spec.ts`:

```ts
import { parseTextSize, TEXT_SIZE_DEFAULT, TEXT_SIZE_STEPS } from './text-size';

describe('parseTextSize', () => {
  it('accepts every step', () => {
    for (const step of TEXT_SIZE_STEPS) {
      expect(parseTextSize(String(step))).toBe(step);
    }
  });

  it.each([null, '', 'large', '95', '80', '160', '1.1'])('reads %p as the default', (raw) => {
    expect(parseTextSize(raw)).toBe(TEXT_SIZE_DEFAULT);
  });
});
```

`frontend/src/app/theme/text-size.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { TextSizeService } from './text-size.service';

describe('TextSizeService', () => {
  const scale = () => document.documentElement.style.getPropertyValue('--text-scale');

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.style.removeProperty('--text-scale');
  });

  function create(): TextSizeService {
    const service = TestBed.inject(TextSizeService);
    TestBed.tick();
    return service;
  }

  it('starts at 100 % and writes a scale of 1 to the root element', () => {
    const service = create();
    expect(service.percent()).toBe(100);
    expect(scale()).toBe('1');
  });

  it('reads the saved step', () => {
    localStorage.setItem('sfr.textSize', '130');
    const service = create();
    expect(service.percent()).toBe(130);
    expect(scale()).toBe('1.3');
  });

  it('steps up and down through the scale and persists each step', () => {
    const service = create();
    service.increase();
    TestBed.tick();
    expect(service.percent()).toBe(110);
    expect(localStorage.getItem('sfr.textSize')).toBe('110');
    expect(scale()).toBe('1.1');
    service.decrease();
    service.decrease();
    TestBed.tick();
    expect(service.percent()).toBe(90);
    expect(scale()).toBe('0.9');
  });

  it('stops at both ends', () => {
    const service = create();
    service.set(90);
    expect(service.canDecrease()).toBe(false);
    service.decrease();
    expect(service.percent()).toBe(90);
    service.set(150);
    expect(service.canIncrease()).toBe(false);
    service.increase();
    expect(service.percent()).toBe(150);
  });

  it('ignores a value that is not a step', () => {
    const service = create();
    service.set(125);
    expect(service.percent()).toBe(100);
  });

  it('resets to 100 %', () => {
    localStorage.setItem('sfr.textSize', '150');
    const service = create();
    service.reset();
    expect(service.percent()).toBe(100);
    expect(localStorage.getItem('sfr.textSize')).toBe('100');
  });

  it('fills the bar from empty at 90 % to full at 150 %', () => {
    const service = create();
    service.set(90);
    expect(service.fillPercent()).toBeCloseTo(0);
    service.set(120);
    expect(service.fillPercent()).toBeCloseTo(50);
    service.set(150);
    expect(service.fillPercent()).toBeCloseTo(100);
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/theme/text-size`
Expected: FAIL, "Cannot find module './text-size'".

- [ ] **Step 3: Implement**

`frontend/src/app/theme/text-size.ts`:

```ts
// The no-flash script in index.html mirrors these.
export const TEXT_SIZE_STEPS: readonly number[] = [90, 100, 110, 120, 130, 140, 150];
export const TEXT_SIZE_DEFAULT = 100;
export const TEXT_SIZE_KEY = 'sfr.textSize';

export function parseTextSize(raw: string | null): number {
  const percent = Number(raw);
  return TEXT_SIZE_STEPS.includes(percent) ? percent : TEXT_SIZE_DEFAULT;
}
```

`frontend/src/app/theme/text-size.service.ts` (a private writable `saved` signal, as in `BrightnessService`):

```ts
import { computed, effect, Injectable, signal } from '@angular/core';
import { parseTextSize, TEXT_SIZE_DEFAULT, TEXT_SIZE_KEY, TEXT_SIZE_STEPS } from './text-size';

const LAST_INDEX = TEXT_SIZE_STEPS.length - 1;

@Injectable({ providedIn: 'root' })
export class TextSizeService {
  private readonly saved = signal(parseTextSize(localStorage.getItem(TEXT_SIZE_KEY)));
  readonly percent = this.saved.asReadonly();
  private readonly index = computed(() => TEXT_SIZE_STEPS.indexOf(this.percent()));

  readonly canDecrease = computed(() => this.index() > 0);
  readonly canIncrease = computed(() => this.index() < LAST_INDEX);
  readonly fillPercent = computed(() => (this.index() / LAST_INDEX) * 100);

  constructor() {
    // index.html's no-flash script paints the first frame; this keeps it in step afterwards.
    effect(() =>
      document.documentElement.style.setProperty('--text-scale', String(this.percent() / 100)),
    );
  }

  set(percent: number): void {
    if (!TEXT_SIZE_STEPS.includes(percent)) {
      return;
    }
    localStorage.setItem(TEXT_SIZE_KEY, String(percent));
    this.saved.set(percent);
  }

  increase(): void {
    this.set(TEXT_SIZE_STEPS[Math.min(this.index() + 1, LAST_INDEX)]);
  }

  decrease(): void {
    this.set(TEXT_SIZE_STEPS[Math.max(this.index() - 1, 0)]);
  }

  reset(): void {
    this.set(TEXT_SIZE_DEFAULT);
  }
}
```

`110 / 100` is `1.1` in JS (and `130 / 100` is `1.3`, `90 / 100` is `0.9`), so `String()` yields the exact strings the tests expect.

In `frontend/src/index.html`, inside the existing `try { … }` of the no-flash script, after the `data-brightness` line, add:

```js
          // Text size (#1382): steps mirror TEXT_SIZE_STEPS in app/theme/text-size.ts.
          var size = Number(localStorage.getItem('sfr.textSize'));
          var steps = [90, 100, 110, 120, 130, 140, 150];
          document.documentElement.style.setProperty(
            '--text-scale',
            String((steps.indexOf(size) >= 0 ? size : 100) / 100),
          );
```

The text-size lines must stay inside the brightness `try`. A failing `localStorage` then still skips both, and the CSS fallback `var(--text-scale, 1)` covers it.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/theme/text-size`
Expected: PASS (both files).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/theme/text-size.ts frontend/src/app/theme/text-size.spec.ts frontend/src/app/theme/text-size.service.ts frontend/src/app/theme/text-size.service.spec.ts frontend/src/index.html
git commit -m "feat(#1382): per-device text size drives --text-scale on the root"
```

---

### Task 2: The type-scale CSS (scoped scaling, controls exempt)

**Files:**
- Create: `frontend/src/app/theme/_type-scale.scss`
- Modify: `frontend/src/app/theme/tokens.scss`. Add the `@use` at the top. In the mode-invariant `:root` block, replace the seven `--fs-*` declarations (`--fs-2xs` through `--fs-xl`) with the mixin. Keep `--font-sans`, `--lh-tight`, `--lh-normal` and the type-scale comment as they are.
- Create: `frontend/src/styles/_text-size.scss`
- Modify: `frontend/src/styles.scss` (add `@use './styles/text-size';` as the **last** `@use`)
- Test: `frontend/src/app/theme/type-scale.spec.ts`

**Interfaces:**
- Consumes: `--text-scale` on `<html>` (Task 1).
- Produces: the global class `text-scaled` (marks a reading container) and the global class `reads-as-text` (exempts a text-like `<button>` from the fixed-size reset). Tasks 4 and 5 put these on templates.

- [ ] **Step 1: Write the failing test**

`frontend/src/app/theme/type-scale.spec.ts` follows the `brightness-steps.spec.ts` approach of compiling the real Sass:

```ts
import { join } from 'node:path';
import * as sass from 'sass';

const SIZES = {
  '2xs': '10px',
  xs: '11px',
  sm: '13px',
  base: '15px',
  read: '16px',
  lg: '18px',
  xl: '24px',
};

function compile(file: string): string {
  return sass
    .compile(join(__dirname, file), { style: 'expanded' })
    .css.replace(/\/\*[\s\S]*?\*\//g, '');
}

function ruleBody(css: string, selector: string): string {
  const start = css.indexOf(`${selector} {`);
  if (start < 0) throw new Error(`No rule for ${selector}`);
  return css.slice(start, css.indexOf('}', start));
}

describe('type tokens', () => {
  const tokens = compile('tokens.scss');

  it.each(Object.entries(SIZES))('keeps --fs-%s at its fixed size on the root', (name, size) => {
    expect(tokens).toContain(`--fs-${name}: ${size};`);
  });
});

describe('text-scaled scope', () => {
  const css = compile('../../styles/_text-size.scss');
  const scope = ruleBody(css, '.text-scaled');
  const reset = ruleBody(
    css,
    ':where(.text-scaled) :where(button:not(.reads-as-text), app-entry-pills)',
  );

  it.each(Object.entries(SIZES))('multiplies --fs-%s by the text scale', (name, size) => {
    expect(scope).toContain(`--fs-${name}: calc(${size} * var(--text-scale, 1));`);
  });

  it('scales text that only inherits its size', () => {
    expect(scope).toContain('font-size: calc(1em * var(--text-scale, 1));');
  });

  it.each(Object.entries(SIZES))('puts --fs-%s back to fixed on controls', (name, size) => {
    expect(reset).toContain(`--fs-${name}: ${size};`);
  });

  it('divides the inherited scale back out on controls', () => {
    expect(reset).toContain('font-size: calc(1em / var(--text-scale, 1));');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/theme/type-scale.spec.ts`
Expected: FAIL. The `tokens` block passes, since the values exist today. The `text-scaled` block fails because `../../styles/_text-size.scss` does not exist.

- [ ] **Step 3: Implement**

`frontend/src/app/theme/_type-scale.scss`:

```scss
// --fs-2xs is below the UI chrome scale: for compact glyph badges (the
// sidebar's "W" whole-word pill), not body or control text.
$sizes: (
  '2xs': 10px,
  'xs': 11px,
  'sm': 13px,
  'base': 15px,
  'read': 16px,
  'lg': 18px,
  'xl': 24px,
);

@mixin fixed {
  @each $name, $size in $sizes {
    --fs-#{$name}: #{$size};
  }
}

@mixin scaled {
  @each $name, $size in $sizes {
    --fs-#{$name}: calc(#{$size} * var(--text-scale, 1));
  }
}
```

In `tokens.scss`:
- Add `@use './type-scale';` beside the other `@use`s.
- In the `:root` block, delete the `--fs-2xs` comment and the seven `--fs-2xs … --fs-xl` lines (the comment moves into `_type-scale.scss` above).
- Put `@include type-scale.fixed;` in their place, right after `--font-sans`. Leave the existing type-scale comment above `--font-sans` as it is.

`frontend/src/styles/_text-size.scss`:

```scss
@use '../app/theme/type-scale';

// The reader's text-size setting (#1382): a reading container takes `text-scaled`.
.text-scaled {
  @include type-scale.scaled;

  font-size: calc(1em * var(--text-scale, 1));
}

// Zero specificity so a component's own font-size still wins. The 1em division
// undoes exactly one scaling, so this holds only for controls not nested in one another.
:where(.text-scaled) :where(button:not(.reads-as-text), app-entry-pills) {
  @include type-scale.fixed;

  font-size: calc(1em / var(--text-scale, 1));
}
```

In `frontend/src/styles.scss`, add `@use './styles/text-size';` after `@use './styles/icon-button';`. It must come after `./styles/reset`: both its `button` rule and ours have specificity (0,0,1), so the later one wins.

- [ ] **Step 4: Run the test and the existing Sass spec**

Run: `docker compose exec -T frontend npx jest src/app/theme/type-scale.spec.ts src/app/theme/brightness-steps.spec.ts`
Expected: PASS. If `ruleBody` can't find the reset selector because Sass reformats it, print the compiled CSS. Copy the selector exactly as Sass emits it into the test; the selector itself must not change.

- [ ] **Step 5: Lint**

Run: `docker compose exec -T frontend npx stylelint "src/**/*.scss"`
Expected: no findings. Both new partials live in `theme/` and `styles/`, where px is allowed.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/theme/_type-scale.scss frontend/src/app/theme/tokens.scss frontend/src/styles/_text-size.scss frontend/src/styles.scss frontend/src/app/theme/type-scale.spec.ts
git commit -m "feat(#1382): text-scaled containers scale the type tokens, controls keep theirs"
```

---

### Task 3: The sidebar stepper

**Files:**
- Modify: `frontend/src/app/theme/_segmented.scss` (add a `stepper-bar` mixin)
- Modify: `frontend/src/app/reader/shell/sidebar/brightness-control.component.scss` (use it)
- Create: `frontend/src/app/reader/shell/sidebar/text-size-control.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-foot.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `TextSizeService` (Task 1): `percent`, `canDecrease`, `canIncrease`, `fillPercent`, `increase()`, `decrease()`, `reset()`.
- Produces: `TextSizeControlComponent`, selector `app-text-size-control`.

- [ ] **Step 1: Add the translations**

In `frontend/public/i18n/en.json`, under `reader`, next to `brightness`:

```json
"textSize": {
  "aria": "Text size",
  "smaller": "Smaller text",
  "larger": "Larger text",
  "reset": "Reset to default",
  "value": "{{value}}%",
  "readout": "Text size {{value}}%"
}
```

In `frontend/public/i18n/de.json`:

```json
"textSize": {
  "aria": "Schriftgröße",
  "smaller": "Kleinere Schrift",
  "larger": "Größere Schrift",
  "reset": "Auf Standard zurücksetzen",
  "value": "{{value}} %",
  "readout": "Schriftgröße {{value}} %"
}
```

- [ ] **Step 2: Write the failing component test**

`frontend/src/app/reader/shell/sidebar/text-size-control.component.spec.ts`:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { TextSizeService } from '../../../theme/text-size.service';
import { TextSizeControlComponent } from './text-size-control.component';

type Fixture = ComponentFixture<TextSizeControlComponent>;

describe('TextSizeControlComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [TextSizeControlComponent, provideTranslocoTesting()],
    });
  });

  function create(): Fixture {
    const fixture = TestBed.createComponent(TextSizeControlComponent);
    fixture.detectChanges();
    return fixture;
  }

  const element = <T extends HTMLElement>(fixture: Fixture, selector: string): T =>
    (fixture.nativeElement as HTMLElement).querySelector<T>(selector)!;
  const fill = (fixture: Fixture): number =>
    parseFloat(element(fixture, '.fill').style.getPropertyValue('inline-size'));

  function click(fixture: Fixture, selector: string): void {
    element(fixture, selector).click();
    fixture.detectChanges();
  }

  it('labels the group and both buttons', () => {
    const fixture = create();
    expect(element(fixture, '[role=group]').getAttribute('aria-label')).toBe('Text size');
    expect(element(fixture, '.smaller').getAttribute('title')).toBe('Smaller text');
    expect(element(fixture, '.larger').getAttribute('title')).toBe('Larger text');
    expect(element(fixture, '.bar').getAttribute('title')).toBe('Reset to default');
  });

  it('shows the decrease and increase glyphs', () => {
    const fixture = create();
    expect(element(fixture, '.smaller').textContent).toContain('text_decrease');
    expect(element(fixture, '.larger').textContent).toContain('text_increase');
  });

  it('shows and announces the current percentage', () => {
    const fixture = create();
    expect(element(fixture, '.value').textContent?.trim()).toBe('100%');
    expect(element(fixture, 'output').textContent?.trim()).toBe('Text size 100%');
  });

  it('steps up on the larger button', () => {
    const fixture = create();
    click(fixture, '.larger');
    expect(element(fixture, '.value').textContent?.trim()).toBe('110%');
    expect(fill(fixture)).toBeCloseTo(100 / 3);
  });

  it('steps down on the smaller button and disables it at 90 %', () => {
    const fixture = create();
    click(fixture, '.smaller');
    expect(element(fixture, '.value').textContent?.trim()).toBe('90%');
    expect(element<HTMLButtonElement>(fixture, '.smaller').disabled).toBe(true);
    expect(fill(fixture)).toBeCloseTo(0);
  });

  it('disables the larger button at 150 %', () => {
    const fixture = create();
    TestBed.inject(TextSizeService).set(150);
    fixture.detectChanges();
    expect(element<HTMLButtonElement>(fixture, '.larger').disabled).toBe(true);
    expect(fill(fixture)).toBeCloseTo(100);
  });

  it('resets to 100 % when the bar is clicked', () => {
    const fixture = create();
    TestBed.inject(TextSizeService).set(140);
    fixture.detectChanges();
    click(fixture, '.bar');
    expect(element(fixture, '.value').textContent?.trim()).toBe('100%');
  });
});
```

In `sidebar-foot.component.spec.ts`, extend the existing tests rather than adding parallel ones:
- In "hides the brightness control, view controls and trial line while organising", add `expect(element.querySelector('app-text-size-control')).toBeNull();` and rename the test to "hides the text size and brightness controls, view controls and trial line while organising".
- Change "keeps the foot order: organise, brightness, view controls, trial, meta" to "keeps the foot order: organise, text size, brightness, view controls, trial, meta", and its expectation to `['organise', 'text-size', 'brightness', 'controls', 'trial', 'meta']`.
- In "shows the brightness control on fine pointers too", add `expect(element.querySelector('app-text-size-control')).not.toBeNull();` and rename it to "shows the text size and brightness controls on fine pointers too".

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar/text-size-control src/app/reader/shell/sidebar/sidebar-foot`
Expected: FAIL. The control spec can't find its module; the foot order test gets `['organise', 'brightness', …]`.

- [ ] **Step 4: Extract the shared bar styles**

Append to `frontend/src/app/theme/_segmented.scss`:

```scss
// The sidebar steppers (brightness, text size): glyph buttons either side of a
// full-height accent fill that resets on click.
@mixin stepper-bar {
  .seg {
    align-items: stretch;
  }

  .seg button:disabled {
    color: var(--text-muted);
    cursor: default;
  }

  .seg .bar {
    flex: 1 1 auto;
    padding: 0;
    border-inline: 1px solid var(--border);
  }

  .track {
    display: block;
    block-size: 100%;
    inline-size: 100%;
    background: var(--surface-2);
  }

  .fill {
    display: block;
    block-size: 100%;
    background: var(--accent-soft);
    transition: inline-size 0.12s ease;
  }
}
```

Replace `brightness-control.component.scss` with:

```scss
@use '../../../theme/segmented';
@include segmented.frame;
@include segmented.stepper-bar;

:host {
  display: flex;
}

.seg .darker,
.seg .brighter {
  padding: var(--space-2);
}
```

(The bar comment moves into the mixin; the rule bodies are unchanged.)

- [ ] **Step 5: Implement the component**

`text-size-control.component.ts`:

```ts
import { Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TextSizeService } from '../../../theme/text-size.service';

/** Smaller A, a progress bar with the percentage, larger A: the sidebar's text-size stepper (#1382). */
@Component({
  selector: 'app-text-size-control',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './text-size-control.component.html',
  styleUrl: './text-size-control.component.scss',
})
export class TextSizeControlComponent {
  readonly textSize = inject(TextSizeService);
}
```

`text-size-control.component.html`:

```html
<div class="seg" role="group" [attr.aria-label]="'reader.textSize.aria' | transloco">
  <button
    type="button"
    class="smaller"
    [title]="'reader.textSize.smaller' | transloco"
    [attr.aria-label]="'reader.textSize.smaller' | transloco"
    [disabled]="!textSize.canDecrease()"
    (click)="textSize.decrease()"
  >
    <app-icon name="text_decrease" size="md" />
  </button>
  <button
    type="button"
    class="bar"
    [title]="'reader.textSize.reset' | transloco"
    [attr.aria-label]="'reader.textSize.reset' | transloco"
    (click)="textSize.reset()"
  >
    <span class="track"><span class="fill" [style.inline-size.%]="textSize.fillPercent()"></span></span>
    <span class="value" aria-hidden="true">{{
      'reader.textSize.value' | transloco: { value: textSize.percent() }
    }}</span>
  </button>
  <button
    type="button"
    class="larger"
    [title]="'reader.textSize.larger' | transloco"
    [attr.aria-label]="'reader.textSize.larger' | transloco"
    [disabled]="!textSize.canIncrease()"
    (click)="textSize.increase()"
  >
    <app-icon name="text_increase" size="md" />
  </button>
  <output class="sr-only" aria-live="polite">
    {{ 'reader.textSize.readout' | transloco: { value: textSize.percent() } }}
  </output>
</div>
```

Run Prettier on it (`npx prettier --write` in the container), since the `track` line is over 100 columns.

`text-size-control.component.scss`:

```scss
@use '../../../theme/segmented';
@include segmented.frame;
@include segmented.stepper-bar;

:host {
  display: flex;
}

.seg .smaller,
.seg .larger {
  padding: var(--space-2);
}

.seg .bar {
  position: relative;
}

.value {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: var(--fs-xs);
  color: var(--text-secondary);
  font-variant-numeric: tabular-nums;
}
```

- [ ] **Step 6: Mount it in the foot**

`sidebar-foot.component.html`: inside `@if (!organising()) {`, directly before `<app-brightness-control class="brightness" />`, add:

```html
  <app-text-size-control class="text-size" />
```

`sidebar-foot.component.ts`: import `TextSizeControlComponent` from `./text-size-control.component` and add it to `imports`. Also update the class docblock's list of foot parts ("Organise switch (coarse pointers only), brightness …") so it names the text-size stepper before brightness.

`sidebar-foot.component.scss`: the top gap moves to the new first stepper, and brightness sits close beneath it like the view controls do:

```scss
.text-size {
  padding-top: var(--space-3);
}

.brightness {
  padding-top: var(--space-2);
}
```

- [ ] **Step 7: Run the tests**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar`
Expected: PASS, including the unchanged `brightness-control.component.spec.ts`.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/theme/_segmented.scss frontend/src/app/reader/shell/sidebar frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1382): text-size stepper above brightness in the sidebar foot"
```

---

### Task 4: Mark the reading containers and the text-like buttons

**Files:**
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.html`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.html`
- Modify: `frontend/src/app/reader/article/reader-toc/reader-toc.component.html`
- Modify: `frontend/src/app/reader/list/magazine/entry-duplicates.component.html`

**Interfaces:**
- Consumes: the global classes `text-scaled` and `reads-as-text` (Task 2).

This is template-only. Jest (jsdom) can't evaluate the custom-property cascade, so it is verified in a real browser by Task 5's Playwright spec. Run Task 5 straight after this one.

- [ ] **Step 1: Lists**

In `entry-list.component.html`, add `text-scaled` to:
- `<app-error-banner class="list-error"` → `class="list-error text-scaled"`. Its message is reading-area text, and its action button is reset by the control rule.
- the skeleton `<div class="rows">` (line ~76) → `class="rows text-scaled"`. It holds no text today; marking every `.rows` keeps the rule simple: rows are reading text.
- `<div class="empty-wrap" …>` → `class="empty-wrap text-scaled"`
- `class="rows magazine"` (line ~94) → `class="rows magazine text-scaled"`
- the plain list `class="rows"` (line ~142) → `class="rows text-scaled"`

Do **not** mark `<header class="list-header">`, `.pull-indicator`, `app-loading-overlay`, `app-to-top-button` or `.mark-above`. They are controls and chrome.

- [ ] **Step 2: Article**

In `reader-view.component.html`:
- `<article [attr.aria-busy]=…>` → `<article class="text-scaled" [attr.aria-busy]=…>`
- `<div class="placeholder">` → `<div class="placeholder text-scaled">`
- `<button type="button" class="note-link" …>` → `class="note-link reads-as-text"`

Do **not** mark `.bar` (the toolbar) or `.mini`.

- [ ] **Step 3: Text-like buttons**

`reader-toc.component.html`: the entry button inside `.toc-list` becomes `<button type="button" class="reads-as-text" (click)="jump.emit(item.id)">`. The `.toc-toggle` stays as it is.

`entry-duplicates.component.html`: `class="also-entry"` → `class="also-entry reads-as-text"`.

Before committing, grep the component `.scss` files of these four templates for selectors on `article`, `.placeholder`, `.rows`, `.empty-wrap`, `.list-error`, `.note-link`, `.also-entry` and `.toc-list button`. Adding a class never breaks an existing class or element selector, but confirm none of them use an exact attribute match like `[class="rows"]`.

- [ ] **Step 4: Run the affected Jest specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/entry-list src/app/reader/article src/app/reader/list/magazine`
Expected: PASS. Spec selectors match on a single class, so an added class doesn't change any match.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/list/entry-list/entry-list.component.html frontend/src/app/reader/article/reader-view/reader-view.component.html frontend/src/app/reader/article/reader-toc/reader-toc.component.html frontend/src/app/reader/list/magazine/entry-duplicates.component.html
git commit -m "feat(#1382): lists and the article scale with the text size"
```

---

### Task 5: Prove it in a real browser

**Files:**
- Create: `frontend/e2e/text-size.spec.ts`

**Interfaces:**
- Consumes: `presetLocalStorage` from `e2e/support/auth.ts`; `stubOneFeedReader` (which calls `stubAuthToken`, so no seeded account is needed), `entryWire`, `entryDetailJson`, `readerFailedJson` from `e2e/support/reader.ts`.

The spec owns its data (stubbed routes), so it runs against any database. It measures each element at 100 %, switches to 150 % and measures again, so no token value is hard-coded. Playwright matches the most recently registered route first, so the entries stubs registered after `stubOneFeedReader` override its empty list.

- [ ] **Step 1: Write the spec**

The stubs follow `e2e/article-back-desktop.spec.ts`. Reader extraction is forced to fail, so the article stays in "original" mode and shows the stubbed feed body with no outbound fetch. The `pane` layout on a desktop viewport shows the list header, the list and the article toolbar (with its `.bar-title`) together.

```ts
import { test, expect, Page } from '@playwright/test';
import { presetLocalStorage } from './support/auth';
import {
  entryDetailJson,
  entryWire,
  readerFailedJson,
  stubOneFeedReader,
} from './support/reader';

const DESKTOP = { width: 1280, height: 900 };
const ENTRY = entryWire({ id: 1, title: 'Text size fixture entry' });
const BODY = '<h2>Section</h2><p>Body text of the fixture article.</p>';

/** Measured at 100 % and again at 150 %: text grows by half, chrome not at all. */
const SCALED = {
  rowTitle: 'app-entry-row .title',
  rowMeta: 'app-entry-row .meta',
  articleTitle: 'app-reader-view article .title',
  articleBody: 'app-reader-view .content p',
  articleHeading: 'app-reader-view .content h2',
};
const FIXED = {
  listHeaderTitle: 'app-list-header h2',
  listHeaderCount: 'app-list-header .title-count',
  toolbarTitle: 'app-reader-view .bar-title',
  toolbarButton: 'app-reader-view .bar .nav button',
  rowAction: 'app-entry-row app-entry-actions button',
  pill: 'app-entry-row app-entry-pills .pill',
};

async function stubReader(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ json: readerFailedJson('unextractable') }),
  );
  await page.route('**/api/entries/*/state', (route) =>
    route.fulfill({
      json: { state: { entryId: 1, isHidden: true, isFavorite: false, isKept: false, hiddenAt: 'x' } },
    }),
  );
  await page.route(
    (url) => url.pathname === `/api/entries/${ENTRY.id}`,
    (route) => route.fulfill({ json: entryDetailJson(ENTRY, BODY) }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [ENTRY], nextCursor: null } }),
  );
}

async function openArticle(page: Page): Promise<void> {
  await page.getByText(ENTRY.title).first().click();
  await expect(page.locator(SCALED.articleBody)).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function measure(page: Page, selectors: Record<string, string>) {
  const sizes: Record<string, number> = {};
  for (const [name, selector] of Object.entries(selectors)) {
    sizes[name] = await page
      .locator(selector)
      .first()
      .evaluate((element) => parseFloat(getComputedStyle(element).fontSize));
  }
  return sizes;
}

test('a saved text size reaches the root before the app boots', async ({ page }) => {
  await presetLocalStorage(page, { 'sfr.textSize': '130' });
  await page.goto('/login');

  const scale = await page.evaluate(() =>
    document.documentElement.style.getPropertyValue('--text-scale'),
  );
  expect(scale).toBe('1.3');
});

test.describe('on a desktop pane layout', () => {
  test.use({ viewport: DESKTOP });

  test('reading text scales; headers, toolbar, buttons and pills do not', async ({ page }) => {
    await presetLocalStorage(page, { 'sfr.layout': 'pane' });
    await stubReader(page);
    await page.goto('/');
    await openArticle(page);
    const scaledBefore = await measure(page, SCALED);
    const fixedBefore = await measure(page, FIXED);

    await page.evaluate(() => localStorage.setItem('sfr.textSize', '150'));
    await page.reload();
    await openArticle(page);
    const scaledAfter = await measure(page, SCALED);
    const fixedAfter = await measure(page, FIXED);

    for (const name of Object.keys(SCALED)) {
      expect.soft(scaledAfter[name], name).toBeCloseTo(scaledBefore[name] * 1.5, 1);
    }
    for (const name of Object.keys(FIXED)) {
      expect.soft(fixedAfter[name], name).toBe(fixedBefore[name]);
    }
  });

  test('the sidebar stepper changes the scale live', async ({ page }) => {
    await stubReader(page);
    await page.goto('/');
    await page.getByRole('button', { name: 'Larger text' }).click();

    const scale = await page.evaluate(() =>
      document.documentElement.style.getPropertyValue('--text-scale'),
    );
    expect(scale).toBe('1.1');
  });
});
```

`entryWire` has no tags by default, so `pill` would match nothing. Give the entry one: read how `app-entry-row` gets `tags()` (from `feedTags` keyed by subscription, i.e. the stubbed `/api/tags` and the subscription's tag ids). Then extend the stubs so the fixture feed carries one tag (`oneFeedJson`'s `overrides` plus a `/api/tags` route registered after `stubOneFeedReader`). If that turns out to need more than those two stubs, drop `pill` from `FIXED` and say so in the task report, rather than inventing a fixture shape.

Before running, the implementer **reads** (not guesses):
- the selectors in `SCALED`/`FIXED` against `entry-row.component.html`, `list-header.component.html` (`h2`, `.title-count`), `reader-view.component.html` (`.bar-title`, `.bar .nav button`, `article .title`)
- that the sidebar is visible at 1280px without a toggle (otherwise open it as `e2e/sidebar-toggle-desktop.spec.ts` does)

- [ ] **Step 2: Run it against the Docker stack, from this checkout**

Run: `cd frontend && npx playwright test e2e/text-size.spec.ts`
Expected: 3 passed.

- [ ] **Step 3: Break-test the guard**

Temporarily delete the `:where(.text-scaled) :where(…)` rule in `src/styles/_text-size.scss`. Re-run, and quote the FAIL lines: the `toolbarButton` and `rowAction` equalities must fail. Restore the rule by re-applying the edit; don't use `git checkout --`. Re-run: 3 passed.

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e/text-size.spec.ts
git commit -m "test(#1382): text scales in lists and articles, controls do not"
```

---

### Task 6: Docs, full gate, visual check

**Files:**
- Modify: `docs/design-language.md`

- [ ] **Step 1: Document**

In `docs/design-language.md`, directly before `### Brightness control`, add:

```markdown
### Text-size control

`<app-text-size-control>` (local to the sidebar, #1382) sits directly above the
brightness control and shares its shape (`segmented.stepper-bar`): `text_decrease`,
a fill bar showing the percentage, `text_increase`. Steps are 90–150 % in tens,
default 100, per device and theme-independent (`sfr.textSize`), never per account.

The setting is one root property, `--text-scale`. A reading container takes the
global class `text-scaled`, which multiplies the `--fs-*` tokens and the inherited
font size by it; today that is the list `.rows`, `.empty-wrap`, `.list-error`, the
article `<article>` and its `.placeholder`. Inside one, every `<button>` and
`app-entry-pills` keep the fixed tokens. A button typeset as running text (TOC
entries, inline "Retry", "Also in") opts back in with `reads-as-text`. Icons,
spacing, images and widths are px tokens and never scale. A new reading surface
takes `text-scaled`; a new control inside one needs nothing.
```

In the type-scale table section (around the `--fs-read` row), add one line: "The `--fs-*` values live in `theme/_type-scale.scss`; `text-scaled` containers multiply them (see *Text-size control*)."

- [ ] **Step 2: The CI gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint and Jest all green.

- [ ] **Step 3: Production build**

Run: `docker compose exec -T frontend npm run build`
Expected: success, with no new budget warnings.

- [ ] **Step 4: Visual check on the real render**

Check :4200 in the built-in browser (confirm the frontend container serves the current chunk), at 150 % and at 90 %:
- the magazine (hero, kicker, source group, compact) on desktop width
- the list and pane layouts
- an open article (title, meta, body, TOC open, comments if any)
- the Mobile viewport preset with the sidebar drawer open, to show the stepper above brightness

Confirm that the buttons, pills, list header and toolbar look identical at both sizes, that no text overlaps a fixed control, and that the sidebar is unchanged. Send screenshots to the user.

- [ ] **Step 5: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(#1382): the text-size control and the text-scaled rule"
```
