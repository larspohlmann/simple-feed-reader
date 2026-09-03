# #832 Sidebar Brightness Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A brightness stepper in the sidebar foot that shifts the theme's tokens per step at build time, holds today's contrast ratios, dims media on negative steps, and persists per device and per theme.

**Architecture:** The Graphite palette becomes Sass maps; `_brightness.scss` derives one palette per (theme, step) with an OKLCH lightness shift and a contrast solver, and `tokens.scss` emits them under `:root[data-theme][data-brightness]`. A `BrightnessService` (effect → `data-brightness` on `<html>`) and the `index.html` no-flash script select the step; a local `BrightnessControlComponent` in the sidebar foot edits it.

**Tech Stack:** Angular 20 standalone + signals, Sass 1.90 (`sass:color` OKLCH, `math.pow`, `color.to-gamut`), Jest (jest-preset-angular, jsdom), Playwright, Transloco, Material Symbols.

**Spec:** `docs/superpowers/specs/2026-09-03-832-sidebar-brightness-design.md`

## Global Constraints

- Branch `feature/832-sidebar-brightness` off `develop`; commits `type(#832): summary`; PR into `develop` with `Closes #832`. Do not merge.
- Work in place in this checkout (no worktree). Check `git status` before any checkout.
- Frontend commands run from `frontend/`. Jest runs **inside Docker**: `docker compose exec -T frontend npx jest <path>` from the repo root (`docker compose up -d` first if the stack is down). Native `npx jest` is acceptable for a single spec while iterating, but the final `npm run check` runs in the container.
- Stylelint: no hex outside `src/app/theme/`; no raw `px` for spacing/size props outside `theme/` and `styles/`; component styles in a sibling `.scss`.
- Prettier: single quotes, printWidth 100, trailing commas. Run `npx prettier --write <files>` before each commit.
- Step 0 must stay byte-identical to today's palette. Light range −3…+1, dark −3…+3, 4 OKLCH points per step, media 0.94/0.88/0.82.
- Comments: one line, three at most, only for a *why*.
- Copy: "Brightness", "Brighter", "Darker", "Reset to default", "Brightness {{value}}", "Brightness default"; German "Helligkeit", "Heller", "Dunkler", "Auf Standard zurücksetzen", "Helligkeit {{value}}", "Helligkeit Standard".
- Icons: darker = `brightness_low`, brighter = `brightness_high`.

---

### Task 0: Branch

- [ ] **Step 1: Branch off develop**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git status --short && git checkout develop && git pull --ff-only && git checkout -b feature/832-sidebar-brightness
```

Expected: clean status before the checkout, new branch created.

- [ ] **Step 2: Commit the design and this plan on the branch**

The spec and this plan are untracked in the working tree (the planning session left them uncommitted).

```bash
git add docs/superpowers/specs/2026-09-03-832-sidebar-brightness-design.md docs/superpowers/plans/2026-09-03-832-sidebar-brightness.md && git commit -m "docs(#832): sidebar brightness design and implementation plan"
```

---

### Task 1: Pure brightness module (range, keys, clamp)

**Files:**
- Modify: `frontend/src/app/theme/themes/registry.ts`
- Create: `frontend/src/app/theme/brightness.ts`
- Test: `frontend/src/app/theme/brightness.spec.ts`

**Interfaces:**
- Produces: `type ResolvedTheme = 'light' | 'dark'` (in `registry.ts`); `BRIGHTNESS_MIN: number`, `BRIGHTNESS_MAX: Record<ResolvedTheme, number>`, `BRIGHTNESS_CELLS: readonly number[]`, `brightnessKey(theme: ResolvedTheme): string`, `clampBrightness(theme: ResolvedTheme, value: number): number`.

- [ ] **Step 1: Write the failing test**

`frontend/src/app/theme/brightness.spec.ts`:

```ts
import { BRIGHTNESS_CELLS, brightnessKey, clampBrightness } from './brightness';

describe('clampBrightness', () => {
  it('keeps a value inside the dark range', () => {
    expect(clampBrightness('dark', 2)).toBe(2);
    expect(clampBrightness('dark', -3)).toBe(-3);
  });

  it('caps dark at +3 and light at +1', () => {
    expect(clampBrightness('dark', 9)).toBe(3);
    expect(clampBrightness('light', 2)).toBe(1);
  });

  it('floors both themes at -3', () => {
    expect(clampBrightness('light', -7)).toBe(-3);
    expect(clampBrightness('dark', -7)).toBe(-3);
  });

  it('reads a non-number as the default', () => {
    expect(clampBrightness('dark', Number.NaN)).toBe(0);
    expect(clampBrightness('dark', Number.POSITIVE_INFINITY)).toBe(0);
  });

  it('truncates a fraction', () => {
    expect(clampBrightness('dark', 1.7)).toBe(1);
  });
});

describe('brightnessKey', () => {
  it('names one key per theme', () => {
    expect(brightnessKey('light')).toBe('sfr.brightness.light');
    expect(brightnessKey('dark')).toBe('sfr.brightness.dark');
  });
});

describe('BRIGHTNESS_CELLS', () => {
  it('spans the dark range with the default in the middle', () => {
    expect(BRIGHTNESS_CELLS).toEqual([-3, -2, -1, 0, 1, 2, 3]);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx jest src/app/theme/brightness.spec.ts`
Expected: FAIL — cannot find module `./brightness`.

- [ ] **Step 3: Write the implementation**

Add to `frontend/src/app/theme/themes/registry.ts` (after the `ThemeMode` line):

```ts
export type ResolvedTheme = Exclude<ThemeMode, 'system'>;
```

Create `frontend/src/app/theme/brightness.ts`:

```ts
import { ResolvedTheme } from './themes/registry';

export const BRIGHTNESS_MIN = -3;

// Light panels are already white, so light stops one step above today (#832).
// The no-flash script in index.html mirrors these bounds; keep them in step.
export const BRIGHTNESS_MAX: Record<ResolvedTheme, number> = { light: 1, dark: 3 };

/** The bar always draws the full dark range; light marks its unreachable cells. */
export const BRIGHTNESS_CELLS: readonly number[] = [-3, -2, -1, 0, 1, 2, 3];

export function brightnessKey(theme: ResolvedTheme): string {
  return `sfr.brightness.${theme}`;
}

export function clampBrightness(theme: ResolvedTheme, value: number): number {
  if (!Number.isFinite(value)) {
    return 0;
  }
  return Math.max(BRIGHTNESS_MIN, Math.min(BRIGHTNESS_MAX[theme], Math.trunc(value)));
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx jest src/app/theme/brightness.spec.ts`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/theme/brightness.ts src/app/theme/brightness.spec.ts src/app/theme/themes/registry.ts && git add src/app/theme/brightness.ts src/app/theme/brightness.spec.ts src/app/theme/themes/registry.ts && git commit -m "feat(#832): brightness range, storage keys and clamp"
```

---

### Task 2: ThemeService exposes the resolved theme

**Files:**
- Modify: `frontend/src/app/theme/theme.service.ts`
- Test: `frontend/src/app/theme/theme.service.spec.ts`

**Interfaces:**
- Produces: `ThemeService.resolved: Signal<ResolvedTheme>` (a writable signal exposed read-only by convention; updated synchronously whenever `data-theme` is written).

- [ ] **Step 1: Write the failing tests**

Append inside the `describe('ThemeService', …)` block of `frontend/src/app/theme/theme.service.spec.ts`:

```ts
  it('exposes the resolved theme as a signal', () => {
    const svc = TestBed.inject(ThemeService);
    expect(svc.resolved()).toBe('light');

    svc.setMode('dark');

    expect(svc.resolved()).toBe('dark');
  });

  it('re-resolves when the OS scheme flips under system mode', () => {
    const svc = TestBed.inject(ThemeService);
    mql.matches = true;
    const onChange = mql.addEventListener.mock.calls[0][1] as () => void;

    onChange();

    expect(svc.resolved()).toBe('dark');
    expect(attr()).toBe('dark');
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx jest src/app/theme/theme.service.spec.ts`
Expected: FAIL — `svc.resolved is not a function`.

- [ ] **Step 3: Implement**

Replace `frontend/src/app/theme/theme.service.ts` with:

```ts
import { Injectable, signal } from '@angular/core';
import { ResolvedTheme, ThemeMode } from './themes/registry';

const KEY = 'sfr.theme';

@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly media = window.matchMedia('(prefers-color-scheme: dark)');
  readonly mode = signal<ThemeMode>(this.readSaved());

  /** The theme on screen: the mode, or what the OS resolved `system` to. */
  readonly resolved = signal<ResolvedTheme>(this.resolve());

  constructor() {
    // Apply synchronously on construction (not via effect, whose flush is
    // async) so the theme is correct before the first render and assertions.
    this.applyResolved();
    this.media.addEventListener('change', () => {
      if (this.mode() === 'system') this.applyResolved();
    });
  }

  setMode(mode: ThemeMode): void {
    localStorage.setItem(KEY, mode);
    this.mode.set(mode);
    this.applyResolved();
  }

  private readSaved(): ThemeMode {
    const v = localStorage.getItem(KEY);
    return v === 'light' || v === 'dark' || v === 'system' ? v : 'system';
  }

  private resolve(): ResolvedTheme {
    const m = this.mode();
    return m === 'system' ? (this.media.matches ? 'dark' : 'light') : m;
  }

  private applyResolved(): void {
    const theme = this.resolve();
    this.resolved.set(theme);
    document.documentElement.setAttribute('data-theme', theme);
  }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx jest src/app/theme/theme.service.spec.ts src/app/reader/view-controls`
Expected: PASS (6 theme tests plus the view-controls spec unchanged).

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/theme/theme.service.ts src/app/theme/theme.service.spec.ts && git add src/app/theme/theme.service.ts src/app/theme/theme.service.spec.ts && git commit -m "feat(#832): expose the resolved theme as a signal"
```

---

### Task 3: BrightnessService

**Files:**
- Create: `frontend/src/app/theme/brightness.service.ts`
- Test: `frontend/src/app/theme/brightness.service.spec.ts`

**Interfaces:**
- Consumes: `ThemeService.resolved`, `BRIGHTNESS_MIN`, `BRIGHTNESS_MAX`, `brightnessKey`, `clampBrightness`.
- Produces: `BrightnessService { step: Signal<number>; min: number; max: Signal<number>; set(step: number): void; increase(): void; decrease(): void; reset(): void }`. Writes `data-brightness` on `<html>` from an effect (flush with `TestBed.tick()` in specs).

- [ ] **Step 1: Write the failing tests**

`frontend/src/app/theme/brightness.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { BrightnessService } from './brightness.service';
import { ThemeService } from './theme.service';

describe('BrightnessService', () => {
  const attr = () => document.documentElement.getAttribute('data-brightness');

  beforeEach(() => {
    localStorage.clear();
    localStorage.setItem('sfr.theme', 'dark');
    document.documentElement.removeAttribute('data-brightness');
  });

  function create(): BrightnessService {
    const svc = TestBed.inject(BrightnessService);
    TestBed.tick();
    return svc;
  }

  it('starts at the default and writes it to the root element', () => {
    const svc = create();
    expect(svc.step()).toBe(0);
    expect(attr()).toBe('0');
  });

  it('reads the saved step of the resolved theme only', () => {
    localStorage.setItem('sfr.brightness.dark', '-2');
    localStorage.setItem('sfr.brightness.light', '1');
    const svc = create();
    expect(svc.step()).toBe(-2);
    expect(attr()).toBe('-2');
  });

  it('reads a corrupt saved value as the default', () => {
    localStorage.setItem('sfr.brightness.dark', 'bright');
    expect(create().step()).toBe(0);
  });

  it('clamps an out-of-range saved value', () => {
    localStorage.setItem('sfr.brightness.dark', '9');
    expect(create().step()).toBe(3);
  });

  it("steps up and persists under the theme's own key", () => {
    const svc = create();
    svc.increase();
    TestBed.tick();
    expect(svc.step()).toBe(1);
    expect(localStorage.getItem('sfr.brightness.dark')).toBe('1');
    expect(localStorage.getItem('sfr.brightness.light')).toBeNull();
    expect(attr()).toBe('1');
  });

  it('stops at both ends of the range', () => {
    const svc = create();
    for (let i = 0; i < 5; i++) svc.decrease();
    expect(svc.step()).toBe(-3);
    for (let i = 0; i < 8; i++) svc.increase();
    expect(svc.step()).toBe(3);
  });

  it('resets to the default', () => {
    const svc = create();
    svc.set(2);
    svc.reset();
    expect(svc.step()).toBe(0);
    expect(localStorage.getItem('sfr.brightness.dark')).toBe('0');
  });

  it("switches to the other theme's step and range when the theme changes", () => {
    localStorage.setItem('sfr.brightness.light', '1');
    const svc = create();
    expect(svc.max()).toBe(3);

    TestBed.inject(ThemeService).setMode('light');
    TestBed.tick();

    expect(svc.step()).toBe(1);
    expect(svc.max()).toBe(1);
    expect(attr()).toBe('1');
  });

  it('caps light mode at +1', () => {
    localStorage.setItem('sfr.theme', 'light');
    const svc = create();
    svc.set(3);
    expect(svc.step()).toBe(1);
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx jest src/app/theme/brightness.service.spec.ts`
Expected: FAIL — cannot find module `./brightness.service`.

- [ ] **Step 3: Implement**

`frontend/src/app/theme/brightness.service.ts`:

```ts
import { computed, effect, inject, Injectable, signal } from '@angular/core';
import { BRIGHTNESS_MAX, BRIGHTNESS_MIN, brightnessKey, clampBrightness } from './brightness';
import { ThemeService } from './theme.service';
import { ResolvedTheme } from './themes/registry';

@Injectable({ providedIn: 'root' })
export class BrightnessService {
  private readonly theme = inject(ThemeService);
  private readonly saved = signal<Record<ResolvedTheme, number>>({
    light: readSaved('light'),
    dark: readSaved('dark'),
  });

  /** The step of the theme on screen; under `system` that is whatever the OS resolved. */
  readonly step = computed(() => this.saved()[this.theme.resolved()]);
  readonly min = BRIGHTNESS_MIN;
  readonly max = computed(() => BRIGHTNESS_MAX[this.theme.resolved()]);

  constructor() {
    // index.html's no-flash script paints the first frame; this keeps the
    // attribute in step afterwards, including when the OS flips the scheme.
    effect(() => document.documentElement.setAttribute('data-brightness', String(this.step())));
  }

  set(step: number): void {
    const theme = this.theme.resolved();
    const value = clampBrightness(theme, step);
    localStorage.setItem(brightnessKey(theme), String(value));
    this.saved.update((steps) => ({ ...steps, [theme]: value }));
  }

  increase(): void {
    this.set(this.step() + 1);
  }

  decrease(): void {
    this.set(this.step() - 1);
  }

  reset(): void {
    this.set(0);
  }
}

function readSaved(theme: ResolvedTheme): number {
  const saved = localStorage.getItem(brightnessKey(theme));
  return saved === null ? 0 : clampBrightness(theme, Number(saved));
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx jest src/app/theme`
Expected: PASS for all four theme specs.

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/theme/brightness.service.ts src/app/theme/brightness.service.spec.ts && git add src/app/theme/brightness.service.ts src/app/theme/brightness.service.spec.ts && git commit -m "feat(#832): BrightnessService with per-theme steps on the root element"
```

---

### Task 4: The Graphite palette as Sass maps (no visual change)

**Files:**
- Modify: `frontend/src/app/theme/themes/_graphite.scss`
- Test: `frontend/src/app/theme/brightness-steps.spec.ts` (created here, extended in Task 5)

**Interfaces:**
- Produces: `graphite.$light`, `graphite.$dark` (Sass maps, token name → colour), `graphite.emit($palette)` mixin, and the unchanged `graphite.light` / `graphite.dark` mixins.

- [ ] **Step 1: Write the failing test (the identity guard)**

`frontend/src/app/theme/brightness-steps.spec.ts`:

```ts
import { join } from 'node:path';
import * as sass from 'sass';

type Palette = Record<string, string>;
type Rgb = [number, number, number];
type Theme = 'light' | 'dark';

/** Today's palette, verbatim: step 0 must never drift from it (#832). */
const TODAY: Record<Theme, Palette> = {
  light: {
    'surface-0': '#f5f5f4',
    'surface-1': '#fff',
    'surface-2': '#fff',
    'surface-read': '#f5f5f4',
    border: '#e4e4e2',
    'border-strong': '#d4d4d1',
    'text-primary': '#2a2a2a',
    'text-secondary': '#5f5f5c',
    'text-muted': '#8f8f8b',
    accent: '#3f8676',
    'accent-soft': '#e9f1ef',
    'on-accent': '#fff',
    danger: '#b3403a',
    'bg-danger': '#f7e9e8',
    success: '#3f7a52',
    'bg-success': '#e9f1ec',
    warning: '#8a5a00',
    'bg-warning': '#fbf0d9',
    'favicon-backdrop': 'transparent',
  },
  dark: {
    'surface-0': '#161616',
    'surface-1': '#1c1c1c',
    'surface-2': '#242424',
    'surface-read': '#1e1e1e',
    border: '#2a2a2a',
    'border-strong': '#383836',
    'text-primary': '#c2c2c0',
    'text-secondary': '#9a9a97',
    'text-muted': '#6a6a67',
    accent: '#5aa694',
    'accent-soft': '#20302c',
    'on-accent': '#0f1a17',
    danger: '#d98a86',
    'bg-danger': '#2a1e1d',
    success: '#7faf8c',
    'bg-success': '#1c2620',
    warning: '#e6b768',
    'bg-warning': '#302611',
    'favicon-backdrop': '#e8e8e6',
  },
};

interface Block {
  selector: string;
  tokens: Palette;
}

function compileBlocks(): Block[] {
  const css = sass
    .compile(join(__dirname, 'tokens.scss'), { style: 'expanded' })
    .css.replace(/\/\*[\s\S]*?\*\//g, '');
  return Array.from(css.matchAll(/([^{}]+)\{([^{}]*)\}/g), ([, selector, body]) => {
    const tokens: Palette = {};
    for (const declaration of body.split(';')) {
      const colon = declaration.indexOf(':');
      const name = declaration.slice(0, colon).trim();
      if (name.startsWith('--')) tokens[name.slice(2)] = declaration.slice(colon + 1).trim();
    }
    return { selector: selector.trim(), tokens };
  });
}

const BLOCKS = compileBlocks();

function paletteAt(theme: Theme, step: number): Palette {
  const block = BLOCKS.find((candidate) =>
    step === 0
      ? candidate.selector.includes(`[data-theme=${theme}]`) &&
        !candidate.selector.includes('data-brightness')
      : candidate.selector.includes(`[data-theme=${theme}][data-brightness="${step}"]`),
  );
  if (!block) throw new Error(`No block for ${theme} step ${step}`);
  return block.tokens;
}

const COLOUR_TOKENS = Object.keys(TODAY.light).filter((token) => token !== 'favicon-backdrop');

function rgb(value: string): Rgb {
  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value);
  if (hex) {
    const digits = hex[1].length === 3 ? [...hex[1]].map((d) => d + d).join('') : hex[1];
    return [0, 2, 4].map((offset) => parseInt(digits.slice(offset, offset + 2), 16)) as Rgb;
  }
  const fn = /^rgb\(([\d.]+),\s*([\d.]+),\s*([\d.]+)\)$/.exec(value);
  if (fn) return [Number(fn[1]), Number(fn[2]), Number(fn[3])];
  throw new Error(`Not a colour: ${value}`);
}

describe.each(['light', 'dark'] as const)('%s step 0', (theme) => {
  it('is today’s palette, byte for byte', () => {
    const base = paletteAt(theme, 0);
    for (const token of Object.keys(TODAY[theme])) {
      expect(`${token}: ${base[token]}`).toBe(`${token}: ${TODAY[theme][token]}`);
    }
  });

  it('parses as colours', () => {
    for (const token of COLOUR_TOKENS) expect(rgb(paletteAt(theme, 0)[token])).toHaveLength(3);
  });
});
```

- [ ] **Step 2: Run the test to verify it passes on the untouched palette**

Run: `cd frontend && npx jest src/app/theme/brightness-steps.spec.ts`
Expected: PASS (4 tests). This is the guard: it passes now and must still pass after the refactor.

- [ ] **Step 3: Refactor `_graphite.scss` to maps**

Replace `frontend/src/app/theme/themes/_graphite.scss` with:

```scss
// Graphite: muted greyscale with a muted teal accent. One map per mode; the
// brightness steps (#832) derive every other palette from these two.

$light: (
  surface-0: #f5f5f4,
  // page canvas
  surface-1: #fff,
  // panel / sidebar
  surface-2: #fff,
  // raised card
  surface-read: #f5f5f4,
  // reading canvas: article body + airy magazine sheet
  border: #e4e4e2,
  border-strong: #d4d4d1,
  text-primary: #2a2a2a,
  text-secondary: #5f5f5c,
  text-muted: #8f8f8b,
  accent: #3f8676,
  accent-soft: #e9f1ef,
  on-accent: #fff,
  danger: #b3403a,
  bg-danger: #f7e9e8,
  success: #3f7a52,
  bg-success: #e9f1ec,
  warning: #8a5a00,
  bg-warning: #fbf0d9,
  // Favicons already sit on a light surface here, so no chip is needed.
  favicon-backdrop: transparent,
);
$dark: (
  surface-0: #161616,
  surface-1: #1c1c1c,
  surface-2: #242424,
  // Lifts the article and the airy sheet off the near-black page. The two
  // share it so they read as one surface.
  surface-read: #1e1e1e,
  border: #2a2a2a,
  border-strong: #383836,
  text-primary: #c2c2c0,
  text-secondary: #9a9a97,
  text-muted: #6a6a67,
  accent: #5aa694,
  accent-soft: #20302c,
  on-accent: #0f1a17,
  danger: #d98a86,
  bg-danger: #2a1e1d,
  success: #7faf8c,
  bg-success: #1c2620,
  warning: #e6b768,
  bg-warning: #302611,
  // A soft near-white chip behind favicons so dark-ink logos on a transparent
  // background (e.g. Die Zeit) stay legible against the dark surface.
  favicon-backdrop: #e8e8e6,
);

@mixin emit($palette) {
  @each $name, $value in $palette {
    --#{$name}: #{$value};
  }
}

@mixin light {
  @include emit($light);
}

@mixin dark {
  @include emit($dark);
}
```

- [ ] **Step 4: Run the guard, the stylelint and the build**

Run: `cd frontend && npx jest src/app/theme/brightness-steps.spec.ts && npx stylelint "src/app/theme/**/*.scss" && npx ng build --configuration development 2>&1 | tail -5`
Expected: PASS; no stylelint findings (if `scss/comment-no-loud` or a map-comment rule fires, move the comments above the map entries or drop them); build succeeds.

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/theme/brightness-steps.spec.ts && git add src/app/theme/themes/_graphite.scss src/app/theme/brightness-steps.spec.ts && git commit -m "refactor(#832): Graphite palette as Sass maps with an identity guard"
```

---

### Task 5: Brightness step generation, media token and the contrast test

**Files:**
- Create: `frontend/src/app/theme/_brightness.scss`
- Modify: `frontend/src/app/theme/tokens.scss`
- Modify: `frontend/src/styles/_base.scss`
- Test: `frontend/src/app/theme/brightness-steps.spec.ts` (extend)

**Interfaces:**
- Consumes: `graphite.$light`, `graphite.$dark`, `graphite.emit`.
- Produces: CSS blocks `:root[data-theme='light'][data-brightness='<s>']` for s ∈ {−3,−2,−1,1} and `:root[data-theme='dark'][data-brightness='<s>']` for s ∈ {−3,−2,−1,1,2,3}, each redefining the shifted and solved tokens plus `--media-brightness`; base blocks gain `--media-brightness: 1`; the global media dim rule.

- [ ] **Step 1: Extend the test with the step assertions**

Append to `frontend/src/app/theme/brightness-steps.spec.ts` (below the existing `describe.each`), and add the helpers under `rgb()`:

```ts
const STEPS: Record<Theme, readonly number[]> = {
  light: [-3, -2, -1, 1],
  dark: [-3, -2, -1, 1, 2, 3],
};
const SURFACES = ['surface-0', 'surface-1', 'surface-2', 'surface-read'];
const SHIFTED = [
  ...SURFACES,
  'border',
  'border-strong',
  'accent-soft',
  'bg-danger',
  'bg-success',
  'bg-warning',
];
const SOLVED = [
  'text-primary',
  'text-secondary',
  'text-muted',
  'accent',
  'danger',
  'success',
  'warning',
];
const MEDIA: Record<number, string> = { [-3]: '0.82', [-2]: '0.88', [-1]: '0.94' };
const TOLERANCE = 0.02;

const linear = (channel: number): number => {
  const c = channel / 255;
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};
const luminance = ([r, g, b]: Rgb): number =>
  0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
const contrast = (a: string, b: string): number => {
  const [high, low] = [luminance(rgb(a)), luminance(rgb(b))].sort((x, y) => y - x);
  return (high + 0.05) / (low + 0.05);
};
const weakest = (token: string, palette: Palette): number =>
  Math.min(...SURFACES.map((surface) => contrast(palette[token], palette[surface])));

function emittedSteps(theme: Theme): number[] {
  return BLOCKS.flatMap((block) => {
    const match = new RegExp(`\\[data-theme=${theme}\\]\\[data-brightness="?(-?\\d)"?\\]`).exec(
      block.selector,
    );
    return match ? [Number(match[1])] : [];
  }).sort((a, b) => a - b);
}

describe.each(['light', 'dark'] as const)('%s brightness steps', (theme) => {
  const base = paletteAt(theme, 0);

  it('emits exactly the agreed steps', () => {
    expect(emittedSteps(theme)).toEqual([...STEPS[theme]]);
  });

  it('leaves media untouched at step 0', () => {
    expect(base['media-brightness']).toBe('1');
  });

  it.each(STEPS[theme])('step %i redefines every shifted and solved token', (step) => {
    expect(Object.keys(paletteAt(theme, step)).sort()).toEqual(
      [...SHIFTED, ...SOLVED, 'media-brightness'].sort(),
    );
  });

  it.each(STEPS[theme])('step %i holds every solved token at its step-0 contrast', (step) => {
    const palette = paletteAt(theme, step);
    for (const token of SOLVED) {
      expect(`${token} ${weakest(token, palette).toFixed(2)}`).toBe(
        `${token} ${Math.max(weakest(token, palette), weakest(token, base) - TOLERANCE).toFixed(2)}`,
      );
    }
  });

  it.each(STEPS[theme])('step %i moves the canvas in the direction of the step', (step) => {
    const canvas = luminance(rgb(paletteAt(theme, step)['surface-0']));
    const today = luminance(rgb(base['surface-0']));
    if (step < 0) expect(canvas).toBeLessThan(today);
    else expect(canvas).toBeGreaterThan(today);
  });

  it.each(STEPS[theme])('step %i keeps on-accent legible on the accent', (step) => {
    const palette = { ...base, ...paletteAt(theme, step) };
    const floor = Math.min(4.5, contrast(base['on-accent'], base.accent)) - TOLERANCE;
    expect(contrast(palette['on-accent'], palette.accent)).toBeGreaterThanOrEqual(floor);
  });

  it.each(STEPS[theme])('step %i dims media only below the default', (step) => {
    expect(paletteAt(theme, step)['media-brightness']).toBe(MEDIA[step] ?? '1');
  });
});
```

- [ ] **Step 2: Run the test to verify the new assertions fail**

Run: `cd frontend && npx jest src/app/theme/brightness-steps.spec.ts`
Expected: FAIL — "emits exactly the agreed steps" gets `[]`; "leaves media untouched" gets `undefined`; the `it.each` cases throw "No block for …".

- [ ] **Step 3: Write the generator**

Create `frontend/src/app/theme/_brightness.scss`:

```scss
@use 'sass:color';
@use 'sass:list';
@use 'sass:map';
@use 'sass:math';

// Brightness steps (#832). Each step moves the shifted tokens by one OKLCH
// lightness unit and re-solves the solved tokens to hold today's contrast.
$lightness-per-step: 4%;
$light-steps: -3, -2, -1, 1;
$dark-steps: -3, -2, -1, 1, 2, 3;
$media-brightness: (
  -3: 0.82,
  -2: 0.88,
  -1: 0.94,
);
$surfaces: surface-0, surface-1, surface-2, surface-read;
$shifted: list.join($surfaces, (border, border-strong, accent-soft, bg-danger, bg-success, bg-warning));
$solved: text-primary, text-secondary, text-muted, accent, danger, success, warning;

// Per-token contrast override, e.g. (text-muted: 3). Empty holds each token's
// own step-0 ratio (muted text is 2.9:1 today). Applies to steps other than 0.
$contrast-targets: ();

// A solved accent must keep on-accent readable; below this it stays as today.
$partners: (
  accent: on-accent,
);
$partner-floor: 4.5;

// The search aims this fraction above the target so channel rounding cannot dip
// below it; relative, because one rounding unit costs ~0.1 at 13:1 and ~0.02 at 3:1.
$rounding-margin: 0.015;

@function linear-channel($channel) {
  $c: math.div($channel, 255);

  /* stylelint-disable-next-line number-max-precision -- the sRGB transfer
     threshold is 0.04045 by definition. */
  @if $c <= 0.04045 {
    @return math.div($c, 12.92);
  }

  @return math.pow(math.div($c + 0.055, 1.055), 2.4);
}

@function luminance($color) {
  $c: color.to-gamut($color, $space: rgb, $method: clip);
  $red: linear-channel(color.channel($c, 'red', $space: rgb));
  $green: linear-channel(color.channel($c, 'green', $space: rgb));
  $blue: linear-channel(color.channel($c, 'blue', $space: rgb));

  @return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
}

@function contrast($a, $b) {
  $la: luminance($a);
  $lb: luminance($b);

  @return math.div(math.max($la, $lb) + 0.05, math.min($la, $lb) + 0.05);
}

// Returned through rgb() on purpose: Sass then prints every derived colour as
// `rgb(r, g, b)`, never as a colour name, which keeps the test parser trivial.
@function rounded($color) {
  $c: color.to-gamut($color, $space: rgb, $method: clip);
  $red: math.round(color.channel($c, 'red', $space: rgb));
  $green: math.round(color.channel($c, 'green', $space: rgb));
  $blue: math.round(color.channel($c, 'blue', $space: rgb));

  @return rgb($red $green $blue);
}

@function shift($color, $step) {
  @return rounded(color.adjust($color, $lightness: $step * $lightness-per-step, $space: oklch));
}

@function with-lightness($color, $lightness) {
  @return color.to-gamut(
    color.change($color, $lightness: $lightness, $space: oklch),
    $space: rgb,
    $method: clip
  );
}

@function weakest-contrast($color, $palette) {
  $worst: 100;

  @each $name in $surfaces {
    $worst: math.min($worst, contrast($color, map.get($palette, $name)));
  }

  @return $worst;
}

// The surface lightness on the token's own side: the darkest surface for text
// that sits below the surfaces (light theme), the lightest for text above.
@function surface-edge($palette, $below) {
  $lightnesses: ();

  @each $name in $surfaces {
    $lightnesses: list.append(
      $lightnesses,
      color.channel(map.get($palette, $name), 'lightness', $space: oklch)
    );
  }

  @return if($below, math.min($lightnesses...), math.max($lightnesses...));
}

// Binary search over OKLCH lightness for the value nearest the surfaces that
// still meets $target, keeping the token's hue and chroma.
@function solve($base, $palette, $target, $below) {
  $near: surface-edge($palette, $below);
  $far: if($below, 0%, 100%);

  @for $i from 1 through 16 {
    $mid: math.div($near + $far, 2);

    @if weakest-contrast(with-lightness($base, $mid), $palette) >= $target * (1 + $rounding-margin) {
      $far: $mid;
    } @else {
      $near: $mid;
    }
  }

  @return rounded(with-lightness($base, $far));
}

@function target($name, $base-palette) {
  $override: map.get($contrast-targets, $name);

  @if $override {
    @return $override;
  }

  @return weakest-contrast(map.get($base-palette, $name), $base-palette);
}

@function keeps-partner($name, $candidate, $base-palette) {
  $partner: map.get($partners, $name);

  @if not $partner {
    @return true;
  }

  $today: contrast(map.get($base-palette, $name), map.get($base-palette, $partner));

  @return contrast($candidate, map.get($base-palette, $partner)) >= math.min($partner-floor, $today);
}

@function palette-at($base-palette, $step) {
  $canvas: color.channel(map.get($base-palette, surface-0), 'lightness', $space: oklch);
  $out: ();

  @each $name in $shifted {
    $out: map.set($out, $name, shift(map.get($base-palette, $name), $step));
  }

  @each $name in $solved {
    $base: map.get($base-palette, $name);
    $below: color.channel($base, 'lightness', $space: oklch) < $canvas;
    $candidate: solve($base, $out, target($name, $base-palette), $below);
    $out: map.set($out, $name, if(keeps-partner($name, $candidate, $base-palette), $candidate, $base));
  }

  @return map.set($out, media-brightness, map.get($media-brightness, $step) or 1);
}

@mixin step($base-palette, $step) {
  @each $name, $value in palette-at($base-palette, $step) {
    --#{$name}: #{$value};
  }
}
```

- [ ] **Step 4: Wire the steps into `tokens.scss`**

Replace lines 1–24 of `frontend/src/app/theme/tokens.scss` (everything above `// Mode-invariant tokens`) with:

```scss
@use './breakpoints' as bp;
@use './brightness';
@use './themes/graphite';

// Light is the fallback when no attribute is set yet (before the no-flash
// script or ThemeService runs).
:root,
:root[data-theme='light'] {
  color-scheme: light;

  @include graphite.light;

  --media-brightness: 1;

  /* Soft elevation for a raised card surface (#541). Mode-specific: a light
     UI needs only a hair of shadow, a dark one a deeper cast to lift the
     surface off its near-black ground. */
  --panel-shadow: 0 1px 2px rgb(0 0 0 / 4%), 0 6px 20px rgb(0 0 0 / 5%);
}

:root[data-theme='dark'] {
  color-scheme: dark;

  @include graphite.dark;

  --media-brightness: 1;
  --panel-shadow: 0 1px 2px rgb(0 0 0 / 30%), 0 8px 24px rgb(0 0 0 / 30%);
}

// Brightness steps (#832): one block per theme and step, keyed by the
// data-brightness attribute the no-flash script and BrightnessService write.
// Step 0 has no block; the base blocks above are today's palette verbatim.
@each $step in brightness.$light-steps {
  :root[data-theme='light'][data-brightness='#{$step}'] {
    @include brightness.step(graphite.$light, $step);
  }
}

@each $step in brightness.$dark-steps {
  :root[data-theme='dark'][data-brightness='#{$step}'] {
    @include brightness.step(graphite.$dark, $step);
  }
}
```

- [ ] **Step 5: Add the media dim rule**

Append to `frontend/src/styles/_base.scss`:

```scss
/* Negative brightness steps dim media with the surfaces (#832). The attribute
   guard keeps `filter` off the default render, where the token is 1. */
:root[data-brightness^='-'] :is(img, video, iframe) {
  filter: brightness(var(--media-brightness));
}
```

- [ ] **Step 6: Run the tests, stylelint and a build**

Run: `cd frontend && npx jest src/app/theme/brightness-steps.spec.ts && npx stylelint "src/app/theme/**/*.scss" "src/styles/**/*.scss" && npx ng build --configuration development 2>&1 | tail -5`
Expected: PASS (all step tests). Sass prints the derived colours as `rgb(r, g, b)` (e.g. `--surface-0: rgb(206, 206, 205)` at light −3) and the base blocks as today's hex; the spec's `rgb()` helper reads both forms. If a solved token fails its ratio by more than 0.02 at some step, raise the search iterations in `solve` to 20 before touching anything else. If stylelint flags function or mixin names, rename to kebab-case; do not disable rules.

- [ ] **Step 7: Look at it once**

Run: `cd frontend && npm start` (or use the Docker frontend on :4200) and in the browser console: `document.documentElement.setAttribute('data-brightness','-3')` on a dark reader page, then `'3'`, then in light mode `'-3'` and `'1'`. Every surface, border and text must move; the accent must stay readable; images must dim only at negative steps. Note anything odd in the PR description; do not tune numbers here.

- [ ] **Step 8: Commit**

```bash
cd frontend && npx prettier --write src/app/theme/brightness-steps.spec.ts && git add src/app/theme/_brightness.scss src/app/theme/tokens.scss src/styles/_base.scss src/app/theme/brightness-steps.spec.ts && git commit -m "feat(#832): derive brightness step palettes at build time with held contrast"
```

---

### Task 6: i18n keys and the BrightnessControlComponent

**Files:**
- Modify: `frontend/public/i18n/en.json` (after the `reader.theme` block, ~line 857)
- Modify: `frontend/public/i18n/de.json` (same position)
- Create: `frontend/src/app/reader/sidebar/brightness-control.component.ts`
- Create: `frontend/src/app/reader/sidebar/brightness-control.component.html`
- Create: `frontend/src/app/reader/sidebar/brightness-control.component.scss`
- Test: `frontend/src/app/reader/sidebar/brightness-control.component.spec.ts`

**Interfaces:**
- Consumes: `BrightnessService`, `BRIGHTNESS_CELLS`, `IconComponent`, Transloco keys `reader.brightness.*`.
- Produces: `<app-brightness-control>` (`BrightnessControlComponent`), no inputs or outputs.

- [ ] **Step 1: Add the translation keys**

In `frontend/public/i18n/en.json`, directly after the `"theme": { … }` object inside `reader` (keep the trailing comma structure valid):

```json
    "brightness": {
      "aria": "Brightness",
      "brighter": "Brighter",
      "darker": "Darker",
      "reset": "Reset to default",
      "readout": "Brightness {{value}}",
      "readoutDefault": "Brightness default"
    },
```

In `frontend/public/i18n/de.json`, same position:

```json
    "brightness": {
      "aria": "Helligkeit",
      "brighter": "Heller",
      "darker": "Dunkler",
      "reset": "Auf Standard zurücksetzen",
      "readout": "Helligkeit {{value}}",
      "readoutDefault": "Helligkeit Standard"
    },
```

Run: `cd frontend && npx jest src/app/core/i18n-dictionaries.spec.ts`
Expected: PASS (both dictionaries carry the same keys).

- [ ] **Step 2: Write the failing component test**

`frontend/src/app/reader/sidebar/brightness-control.component.spec.ts`:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { BrightnessService } from '../../theme/brightness.service';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { BrightnessControlComponent } from './brightness-control.component';

type Fixture = ComponentFixture<BrightnessControlComponent>;

describe('BrightnessControlComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    localStorage.setItem('sfr.theme', 'dark');
    TestBed.configureTestingModule({
      imports: [BrightnessControlComponent, provideTranslocoTesting()],
    });
  });

  function create(): Fixture {
    const f = TestBed.createComponent(BrightnessControlComponent);
    f.detectChanges();
    return f;
  }

  const element = <T extends HTMLElement>(f: Fixture, selector: string): T =>
    (f.nativeElement as HTMLElement).querySelector<T>(selector)!;
  const filledCells = (f: Fixture): number =>
    (f.nativeElement as HTMLElement).querySelectorAll('.cell.filled').length;

  function setStep(f: Fixture, step: number): void {
    TestBed.inject(BrightnessService).set(step);
    f.detectChanges();
  }

  it('labels the group and both buttons', () => {
    const f = create();
    expect(element(f, '[role=group]').getAttribute('aria-label')).toBe('Brightness');
    expect(element(f, '.darker').getAttribute('title')).toBe('Darker');
    expect(element(f, '.brighter').getAttribute('title')).toBe('Brighter');
  });

  it('shows a small sun for darker and a big sun for brighter', () => {
    const f = create();
    expect(element(f, '.darker').textContent).toContain('brightness_low');
    expect(element(f, '.brighter').textContent).toContain('brightness_high');
  });

  it('fills four of seven cells at the default and marks the default cell', () => {
    const f = create();
    expect((f.nativeElement as HTMLElement).querySelectorAll('.cell').length).toBe(7);
    expect(filledCells(f)).toBe(4);
    expect(element(f, '.cell.default')).not.toBeNull();
  });

  it('announces the default in words', () => {
    expect(element(create(), 'output').textContent?.trim()).toBe('Brightness default');
  });

  it('steps up on the big sun and announces the signed value', () => {
    const f = create();
    element(f, '.brighter').click();
    f.detectChanges();
    expect(filledCells(f)).toBe(5);
    expect(element(f, 'output').textContent?.trim()).toBe('Brightness +1');
  });

  it('steps down on the small sun and announces the negative value', () => {
    const f = create();
    element(f, '.darker').click();
    f.detectChanges();
    expect(filledCells(f)).toBe(3);
    expect(element(f, 'output').textContent?.trim()).toBe('Brightness -1');
  });

  it('disables the small sun at the bottom of the range', () => {
    const f = create();
    setStep(f, -3);
    expect(element<HTMLButtonElement>(f, '.darker').disabled).toBe(true);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(false);
    expect(filledCells(f)).toBe(1);
  });

  it('disables the big sun at the top of the dark range', () => {
    const f = create();
    setStep(f, 3);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(true);
    expect(filledCells(f)).toBe(7);
  });

  it('resets to the default when the bar is clicked', () => {
    const f = create();
    setStep(f, 2);
    element(f, '.bar').click();
    f.detectChanges();
    expect(filledCells(f)).toBe(4);
    expect(element(f, '.bar').getAttribute('title')).toBe('Reset to default');
  });

  it('marks the unreachable cells and stops at +1 in light mode', () => {
    localStorage.setItem('sfr.theme', 'light');
    const f = create();
    expect((f.nativeElement as HTMLElement).querySelectorAll('.cell.unavailable').length).toBe(2);
    setStep(f, 1);
    expect(element<HTMLButtonElement>(f, '.brighter').disabled).toBe(true);
    expect(filledCells(f)).toBe(5);
  });
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd frontend && npx jest src/app/reader/sidebar/brightness-control`
Expected: FAIL — cannot find module `./brightness-control.component`.

- [ ] **Step 4: Write the component**

`frontend/src/app/reader/sidebar/brightness-control.component.ts`:

```ts
import { Component, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { BRIGHTNESS_CELLS } from '../../theme/brightness';
import { BrightnessService } from '../../theme/brightness.service';

/** Small sun, a seven-cell bar, big sun: the sidebar's brightness stepper (#832). */
@Component({
  selector: 'app-brightness-control',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './brightness-control.component.html',
  styleUrl: './brightness-control.component.scss',
})
export class BrightnessControlComponent {
  readonly brightness = inject(BrightnessService);
  readonly cells = BRIGHTNESS_CELLS;

  readonly atMin = computed(() => this.brightness.step() <= this.brightness.min);
  readonly atMax = computed(() => this.brightness.step() >= this.brightness.max());

  readonly signedStep = computed(() => {
    const step = this.brightness.step();
    return step > 0 ? `+${step}` : String(step);
  });
}
```

`frontend/src/app/reader/sidebar/brightness-control.component.html`:

```html
<div class="seg" role="group" [attr.aria-label]="'reader.brightness.aria' | transloco">
  <button
    type="button"
    class="darker"
    [title]="'reader.brightness.darker' | transloco"
    [attr.aria-label]="'reader.brightness.darker' | transloco"
    [disabled]="atMin()"
    (click)="brightness.decrease()"
  >
    <app-icon name="brightness_low" size="md" />
  </button>
  <button
    type="button"
    class="bar"
    [title]="'reader.brightness.reset' | transloco"
    [attr.aria-label]="'reader.brightness.reset' | transloco"
    (click)="brightness.reset()"
  >
    @for (cell of cells; track cell) {
      <span
        class="cell"
        [class.filled]="cell <= brightness.step()"
        [class.unavailable]="cell > brightness.max()"
        [class.default]="cell === 0"
      ></span>
    }
  </button>
  <button
    type="button"
    class="brighter"
    [title]="'reader.brightness.brighter' | transloco"
    [attr.aria-label]="'reader.brightness.brighter' | transloco"
    [disabled]="atMax()"
    (click)="brightness.increase()"
  >
    <app-icon name="brightness_high" size="md" />
  </button>
  <output class="sr-only" aria-live="polite">
    @if (brightness.step() === 0) {
      {{ 'reader.brightness.readoutDefault' | transloco }}
    } @else {
      {{ 'reader.brightness.readout' | transloco: { value: signedStep() } }}
    }
  </output>
</div>
```

`frontend/src/app/reader/sidebar/brightness-control.component.scss`:

```scss
:host {
  display: flex;
}

/* Same frame as the view-controls segmented groups, so the three rows in the
   foot read as one family. */
.seg {
  display: inline-flex;
  flex: 1 1 auto;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}

.seg button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-2) var(--space-2);
  background: var(--surface-2);
  border: none;
  color: var(--text-secondary);
  cursor: pointer;
}

.seg button:hover:not(:disabled) {
  background: var(--surface-0);
}

.seg button:disabled {
  color: var(--text-muted);
  cursor: default;
}

.seg .bar {
  flex: 1 1 auto;
  gap: var(--space-0);
  border-inline: 1px solid var(--border);
}

.cell {
  position: relative;
  flex: 1;
  height: var(--space-2);
  border-radius: var(--radius-sm);
  background: var(--border-strong);
}

.cell.filled {
  background: var(--text-secondary);
}

.cell.unavailable {
  background: var(--border);
}

/* A notch under the default cell marks where today's look is. */
.cell.default::after {
  content: '';
  position: absolute;
  left: 50%;
  bottom: calc(-1 * var(--space-1));
  width: var(--space-0);
  height: var(--space-0);
  margin-left: calc(var(--space-0) / -2);
  border-radius: var(--radius-pill);
  background: var(--text-muted);
}
```

- [ ] **Step 5: Run the tests, lint and stylelint**

Run: `cd frontend && npx jest src/app/reader/sidebar/brightness-control && npx eslint src/app/reader/sidebar/brightness-control.component.ts src/app/reader/sidebar/brightness-control.component.html && npx stylelint src/app/reader/sidebar/brightness-control.component.scss`
Expected: PASS (10 tests), no lint findings. If `.seg .bar` loses to `.seg button` for `border`, keep the `.seg .bar` selector (specificity 0,2,0 beats 0,1,1) and check source order.

- [ ] **Step 6: Commit**

```bash
cd frontend && npx prettier --write public/i18n/en.json public/i18n/de.json src/app/reader/sidebar/brightness-control.component.* && git add public/i18n/en.json public/i18n/de.json src/app/reader/sidebar/brightness-control.component.ts src/app/reader/sidebar/brightness-control.component.html src/app/reader/sidebar/brightness-control.component.scss src/app/reader/sidebar/brightness-control.component.spec.ts && git commit -m "feat(#832): brightness stepper component with a seven-cell bar"
```

---

### Task 7: Mount the control in the sidebar foot

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar-foot.component.ts:1-23`
- Modify: `frontend/src/app/reader/sidebar/sidebar-foot.component.html:13-14`
- Modify: `frontend/src/app/reader/sidebar/sidebar-foot.component.scss:10-12`
- Test: `frontend/src/app/reader/sidebar/sidebar-foot.component.spec.ts:137-150`

**Interfaces:**
- Consumes: `<app-brightness-control>`.

- [ ] **Step 1: Update the failing tests**

In `frontend/src/app/reader/sidebar/sidebar-foot.component.spec.ts`, change the two tests:

```ts
  it('hides the brightness control, view controls and trial line while organising', () => {
    const el = mount({ coarse: true, organising: true, user: account(inDays(5)) })
      .nativeElement as HTMLElement;
    expect(el.querySelector('app-brightness-control')).toBeNull();
    expect(el.querySelector('app-view-controls')).toBeNull();
    expect(el.querySelector('.trial')).toBeNull();
    // The version link stays visible even while organising.
    expect(el.querySelector('.version')).not.toBeNull();
  });

  it('keeps the foot order: organise, brightness, view controls, trial, meta', () => {
    const el = mount({ coarse: true, user: account(inDays(5)) }).nativeElement as HTMLElement;
    const order = Array.from(el.children).map((child) => child.classList[0]);
    expect(order).toEqual(['organise', 'brightness', 'controls', 'trial', 'meta']);
  });
```

Also add to the spec:

```ts
  it('shows the brightness control on fine pointers too', () => {
    const el = mount({ coarse: false }).nativeElement as HTMLElement;
    expect(el.querySelector('app-brightness-control')).not.toBeNull();
  });
```

- [ ] **Step 2: Run the spec to verify it fails**

Run: `cd frontend && npx jest src/app/reader/sidebar/sidebar-foot`
Expected: FAIL — order is `['organise', 'controls', 'trial', 'meta']`, and `app-brightness-control` is null on fine pointers.

- [ ] **Step 3: Mount the control**

`sidebar-foot.component.ts`: add the import and register the component.

```ts
import { BrightnessControlComponent } from './brightness-control.component';
```

and in the decorator:

```ts
  imports: [RouterLink, IconComponent, BrightnessControlComponent, ViewControlsComponent, TranslocoPipe],
```

Update the class docblock's first sentence to: `The sidebar drawer's foot: Organise switch (coarse pointers only), brightness stepper, view controls, trial countdown, and version/feedback links.`

`sidebar-foot.component.html`: replace lines 13–14 with

```html
@if (!organising()) {
  <app-brightness-control class="brightness" />
  <app-view-controls class="controls" />
```

`sidebar-foot.component.scss`: replace the `.controls` rule with

```scss
.brightness {
  padding-top: var(--space-3);
}

.controls {
  padding-top: var(--space-2);
}
```

- [ ] **Step 4: Run the sidebar specs**

Run: `cd frontend && npx jest src/app/reader/sidebar`
Expected: PASS (the whole sidebar directory, including any spec that mounts the foot inside the sidebar).

- [ ] **Step 5: Commit**

```bash
cd frontend && npx prettier --write src/app/reader/sidebar/sidebar-foot.component.* && git add src/app/reader/sidebar/sidebar-foot.component.ts src/app/reader/sidebar/sidebar-foot.component.html src/app/reader/sidebar/sidebar-foot.component.scss src/app/reader/sidebar/sidebar-foot.component.spec.ts && git commit -m "feat(#832): brightness row in the sidebar foot above the view controls"
```

---

### Task 8: Pre-boot attribute and the Playwright smoke

**Files:**
- Modify: `frontend/src/index.html:21-32`
- Create: `frontend/e2e/brightness.spec.ts`

**Interfaces:**
- Consumes: `localStorage['sfr.brightness.<theme>']`; bounds mirrored from `BRIGHTNESS_MAX`.

- [ ] **Step 1: Write the smoke**

`frontend/e2e/brightness.spec.ts`:

```ts
// e2e/brightness.spec.ts
import { test, expect } from '@playwright/test';

// Both checks are root-level (an <html> attribute and a global media rule),
// so the login page proves them without a sign-in.
test('a saved dark brightness step paints from the first frame and dims media', async ({
  page,
}) => {
  await page.addInitScript(() => {
    localStorage.setItem('sfr.theme', 'dark');
    localStorage.setItem('sfr.brightness.dark', '-3');
  });
  await page.goto('/login');

  await expect(page.locator('html')).toHaveAttribute('data-brightness', '-3');
  const filter = await page.evaluate(() => {
    const image = document.createElement('img');
    document.body.append(image);
    return getComputedStyle(image).filter;
  });
  expect(filter).toBe('brightness(0.82)');
});

test('a light step above +1 is clamped before the app boots', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('sfr.theme', 'light');
    localStorage.setItem('sfr.brightness.light', '3');
  });
  await page.goto('/login');

  await expect(page.locator('html')).toHaveAttribute('data-brightness', '1');
});

test('no saved step leaves the default render without a media filter', async ({ page }) => {
  await page.goto('/login');

  await expect(page.locator('html')).toHaveAttribute('data-brightness', '0');
  const filter = await page.evaluate(() => {
    const image = document.createElement('img');
    document.body.append(image);
    return getComputedStyle(image).filter;
  });
  expect(filter).toBe('none');
});
```

- [ ] **Step 2: Run it to verify the first two fail**

Run (Docker stack up, from `frontend/`): `npx playwright test e2e/brightness.spec.ts`
Expected: the first test fails on `data-brightness` only once Angular boots later than the assertion, or the clamp test fails with `3`. (The third passes already because `BrightnessService` writes `0`.) If all three pass, the assertion is not reaching the pre-boot window; keep going, the script is still required for the first frame.

- [ ] **Step 3: Extend the no-flash script**

Replace the `<script>` block in `frontend/src/index.html` (lines 21–32) with:

```html
    <script>
      (function () {
        try {
          var m = localStorage.getItem('sfr.theme');
          var dark =
            m === 'dark' ||
            ((m === 'system' || !m) && matchMedia('(prefers-color-scheme: dark)').matches);
          var theme = dark ? 'dark' : 'light';
          document.documentElement.setAttribute('data-theme', theme);
          // Brightness (#832): bounds mirror BRIGHTNESS_MAX in app/theme/brightness.ts.
          var step = parseInt(localStorage.getItem('sfr.brightness.' + theme), 10);
          var clamped = Math.max(-3, Math.min(dark ? 3 : 1, isNaN(step) ? 0 : step));
          document.documentElement.setAttribute('data-brightness', String(clamped));
        } catch (e) {}
      })();
    </script>
```

- [ ] **Step 4: Run the smoke and the format check**

Run: `cd frontend && npx prettier --check src/index.html && npx playwright test e2e/brightness.spec.ts`
Expected: format OK (run `npx prettier --write src/index.html` if not); 3 passed.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add src/index.html e2e/brightness.spec.ts && git commit -m "feat(#832): apply the saved brightness step before boot, with a Playwright smoke"
```

---

### Task 9: Design-language docs, full check, PR

**Files:**
- Modify: `docs/design-language.md` (§1 after "Utility classes" ~line 212; opt-out list ~line 527; §3 after "Sidebar row markers" ~line 1186)

- [ ] **Step 1: Document the tokens**

Insert before the `---` that precedes `## 2. Component catalog`:

```markdown
### Brightness steps (#832)

`<html>` carries `data-brightness` (`-3`…`3`) next to `data-theme`. Step 0 is
the palette in `themes/_graphite.scss` verbatim; every other step is derived
at build time by `theme/_brightness.scss` and emitted as its own
`:root[data-theme][data-brightness]` block. Surfaces, borders and the soft
status backgrounds shift 4 OKLCH lightness points per step; text and the
saturated hues are re-solved to hold their step-0 contrast against the
weakest surface. `--media-brightness` (1, or 0.94/0.88/0.82 below 0) drives
one global `filter` on `img`, `video` and `iframe`. Light stops at +1 because
its panels are already white. To change a contrast target, edit
`$contrast-targets` in `_brightness.scss`; never hand-tune a step block.
```

- [ ] **Step 2: Extend the opt-out list**

In the `<app-button>` section, after `- the view-controls segmented control`, add:

```markdown
- the sidebar's brightness stepper (`<app-brightness-control>`)
```

- [ ] **Step 3: Document the control**

Insert before `### Sticky and scroll`:

```markdown
### Brightness control

`<app-brightness-control>` (local to the sidebar, #832) is a small sun, a
seven-cell bar and a big sun in the view-controls `.seg` frame. Cells fill
from the left up to the current step (four at the default); the fourth cell's
notch marks today's look; clicking the bar resets to it. In light mode the
two cells above +1 render as unavailable and the big sun disables at +1. A
visually hidden `<output aria-live="polite">` reads the value. Steps are per
device and per theme (`sfr.brightness.light` / `.dark`), never per account.
```

- [ ] **Step 4: Run the full frontend gate in Docker**

From the repo root:

```bash
docker compose up -d && docker compose exec -T frontend npm run check
```

Expected: ESLint, Prettier, Stylelint, typecheck and Jest all pass. Fix any finding in the files this branch touches; do not touch unrelated files.

- [ ] **Step 5: Run the Playwright smokes that pin colours**

From `frontend/`: `npx playwright test e2e/brightness.spec.ts e2e/magazine-airy-style.spec.ts e2e/auth-smoke.spec.ts`
Expected: all pass (the colour-pinning specs run at step 0, so today's values hold).

- [ ] **Step 6: Commit and push**

```bash
git add docs/design-language.md && git commit -m "docs(#832): brightness steps and the sidebar stepper in the design language" && git push -u origin feature/832-sidebar-brightness
```

- [ ] **Step 7: Open the PR (do not merge)**

```bash
gh pr create --base develop --title "feat(#832): sidebar brightness control with automatic contrast" --body-file - <<'EOF'
Closes #832

A brightness stepper in the sidebar foot (small sun, seven-cell bar, big sun). Steps are derived at build time in Sass: surfaces, borders and soft backgrounds shift 4 OKLCH lightness points per step; text and the saturated hues are re-solved to hold their step-0 contrast. Negative steps dim media (`--media-brightness`). Per device, per theme, applied pre-boot by the `index.html` script. Light stops at +1 (its panels are already white); dark runs −3…+3. Step 0 is byte-identical to today, guarded by `brightness-steps.spec.ts`.

Design: docs/superpowers/specs/2026-09-03-832-sidebar-brightness-design.md
Plan: docs/superpowers/plans/2026-09-03-832-sidebar-brightness.md

## Verification
- `docker compose exec -T frontend npm run check`
- `npx playwright test e2e/brightness.spec.ts e2e/magazine-airy-style.spec.ts e2e/auth-smoke.spec.ts`
- Visual pass at −3, 0, +1 (light) and −3, 0, +3 (dark) on the reader
EOF
```

Expected: PR URL printed. Stop here; the user merges.

---

## Self-review

- **Spec coverage:** mechanism and tokens (Tasks 4–5), range and constants (1), media (5), storage/boot (3, 8), the control and its markup, bar, a11y and placement (6–7), i18n (6), docs (9), every listed test (1, 2, 3, 4/5, 6, 7, 8), no transition (nothing added), applied at the root (attribute on `<html>`), icons `brightness_low`/`brightness_high` (6).
- **Placeholders:** none; every step carries its code or its exact command.
- **Type consistency:** `ResolvedTheme` is exported from `themes/registry.ts` (Task 1) and imported by `brightness.ts`, `theme.service.ts`, `brightness.service.ts`. `BrightnessService.min` is a number, `max` and `step` are signals; the component reads `brightness.min` without parentheses and `brightness.max()` / `brightness.step()` with. `graphite.$light` / `graphite.$dark` (Task 4) are what `tokens.scss` passes to `brightness.step` (Task 5). The spec helper `paletteAt` from Task 4 is reused by Task 5's assertions.
