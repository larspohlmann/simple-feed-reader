# Frontend 5a — Angular Workspace, Theming, and Auth — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the Angular SPA with a themeable Graphite design system (light/dark) and the complete authentication journey — register, verify, login (password + Google/Apple), password reset — landing the signed-in user in a minimal shell, with a code-style/lint gate matching the backend's discipline.

**Architecture:** One standalone-Angular workspace at `frontend/`, signals for state, bespoke SCSS over Angular CDK (no component framework), CSS custom-property design tokens so a new theme is data not code. Bearer-JWT transport (token in `localStorage`, attached by a functional HTTP interceptor); the one exception is the credentialed OAuth exchange. The backend contract is frozen — this plan writes no backend code and conforms exactly to routes/payloads that already exist.

**Tech Stack:** Angular 20 (standalone, signals, functional guards/interceptors), TypeScript, SCSS, Angular CDK, Jest (`jest-preset-angular`), ESLint (`angular-eslint` flat config) + Prettier + Stylelint, Playwright (integration smoke), self-hosted Material Symbols.

**Design spec:** [docs/superpowers/specs/2026-07-22-05a-frontend-workspace-theming-auth-design.md](../specs/2026-07-22-05a-frontend-workspace-theming-auth-design.md)

---

## Conventions for every task

- **Working directory** is `frontend/` unless a step says otherwise. Commands are shown relative to `frontend/`.
- **Angular 20 idioms:** standalone components (no NgModules, no `standalone: true` — it is the default), `inject()` over constructor params in functions/guards/interceptors, native control flow (`@if`/`@for`), signals for component/service state. Classic file layout per unit: `x.component.ts` + `.html` + `.scss` + `.spec.ts`; services/guards/helpers are `x.ts` + `x.spec.ts`.
- **Selectors** use the `app` prefix (`app-login`).
- **No hard-coded colours** anywhere outside `src/app/theme/` — components reference CSS custom properties only. Stylelint enforces this (Task 3).
- **Tests are TDD:** write the failing test, watch it fail, implement minimally, watch it pass, commit. `npm test` runs Jest.
- **Commit** after each task with a Conventional-Commits message (`feat(frontend): …`, `chore(frontend): …`, `test(frontend): …`).
- **Quality gate** after code tasks: `npm run check` (lint + format:check + stylelint + jest) must be green before the commit. Early tasks (1–3) bootstrap the gate itself, so they note when it first becomes available.

## Shared contracts (defined across tasks — reference)

These are the load-bearing types/values used by multiple tasks. Each is *created* in the task noted; later tasks import it unchanged.

- `API_BASE_URL` — `InjectionToken<string>`, Task 1 (`core/api.ts`). Value from `environment.apiBaseUrl`.
- `Problem` interface + `parseProblem(err)` — Task 7 (`core/problem.ts`). Shape: `{ type: string; title: string; status: number; detail?: string; errors?: Record<string,string[]>; accountStatus?: string }`.
- `TokenStore` — Task 8 (`core/token.store.ts`). `token(): Signal<string|null>`, `isAuthenticated(): Signal<boolean>`, `set(jwt)`, `clear()`. Storage key `sfr.jwt`.
- `authInterceptor` — Task 9 (`core/auth.interceptor.ts`).
- `AuthService` + `CurrentUser` — Task 10 (`core/auth.service.ts`). `CurrentUser = { id: number; email: string; roles: string[]; status: string; createdAt: string }`.
- `authGuard` / `guestGuard` — Task 11 (`core/auth.guard.ts`).
- `ThemeService` + `ThemeMode` — Task 12 (`theme/theme.service.ts`). `ThemeMode = 'light'|'dark'|'system'`. Storage key `sfr.theme`.
- `AltchaChallenge` interface + `solveAltcha(c)` — Task 15 (`auth/altcha.ts`).

## Backend contract this plan consumes (frozen — do not change the backend)

| Method + path | Request body | Success | Error |
|---|---|---|---|
| `POST /api/auth/login` | `{ email, password }` | `200 { token }` | `401 invalid_credentials`; `403 account_not_active` (+`accountStatus`); `429 rate_limited` (+`Retry-After`) |
| `GET /api/me` | — (bearer) | `200 { id, email, roles[], status, createdAt }` | `401` |
| `GET /api/auth/altcha-challenge` | — | `200 { algorithm, challenge, salt, signature, maxnumber }` | — |
| `POST /api/auth/register` | `{ email, password(≥12), altcha }` | `202 { status: 'pending_verification' }` | `422 validation_error` (`errors.altcha`, `errors.email`, …); `429 rate_limited` |
| `POST /api/auth/verify-email` | `{ token }` | `200` | `422`/`400 invalid_token` |
| `POST /api/auth/password-reset-request` | `{ email, altcha }` | `200` (neutral) | `422`; `429` |
| `POST /api/auth/password-reset` | `{ token, password(≥12) }` | `200` | `422`/`400 invalid_token` |
| `GET /api/auth/oauth/providers` | — | `200 { providers: string[] }` | — |
| `GET /api/auth/oauth/{provider}` | — (top-level browser redirect) | `302` to provider | — |
| `POST /api/auth/oauth/exchange` | `{ code }` **credentialed** | `200 { token }` | `400 invalid_token`; `403 account_not_active` |

The ALTCHA solution submitted in `altcha` is `base64(JSON.stringify({ algorithm, challenge, number, salt, signature }))` where `number` is the smallest `n ≥ 0` with `sha256hex(salt + n) === challenge`.

The email links the backend sends resolve to these exact frontend routes: `/verify-email?token=…`, `/reset-password?token=…`. The OAuth callback redirects to `/auth/callback?code=…` (or `?error=…`).

## File map (what 5a creates)

```
frontend/
  package.json, angular.json, tsconfig*.json      Task 1
  jest.config.ts, setup-jest.ts, jest-global-mocks.ts   Task 2
  eslint.config.js, .prettierrc.json, .stylelintrc.json Task 3
  playwright.config.ts, e2e/auth-smoke.spec.ts    Task 22
  src/
    index.html                        Task 1 (+ no-flash script Task 12)
    main.ts                           Task 1
    styles.scss                       Task 4
    environments/
      environment.ts                  Task 1  (prod: apiBaseUrl '')
      environment.development.ts      Task 1  (dev: 'https://localhost:8443')
    styles/
      _reset.scss, _base.scss         Task 4
    app/
      app.ts, app.html, app.scss      Task 1 (root shell = <router-outlet>)
      app.config.ts                   Task 1 (+ interceptor Task 13)
      app.routes.ts                   Task 13
      core/
        api.ts                        Task 1
        problem.ts                    Task 7
        token.store.ts                Task 8
        auth.interceptor.ts           Task 9
        auth.service.ts               Task 10
        auth.guard.ts                 Task 11
      theme/
        tokens.scss                   Task 4
        themes/_graphite.scss         Task 4
        themes/registry.ts            Task 12
        theme.service.ts              Task 12
      shared/
        icon/icon.component.*         Task 5
        button/button.component.*     Task 6
        form-error/form-error.component.*   Task 6
        spinner/spinner.component.*   Task 6
      auth/
        auth-shell/auth-shell.component.*   Task 13
        altcha.ts                     Task 15
        altcha.service.ts             Task 15
        login/login.component.*       Task 14
        register/register.component.* Task 16
        verify-email/verify-email.component.*         Task 17
        reset-request/reset-request.component.*       Task 18
        reset-password/reset-password.component.*     Task 19
        oauth-callback/oauth-callback.component.*      Task 20
      shell/
        shell.component.*             Task 21
```

---

## Task 1: Scaffold the Angular workspace

**Files:**
- Create: `frontend/` (whole workspace via CLI)
- Create: `frontend/src/environments/environment.ts`, `environment.development.ts`
- Create: `frontend/src/app/core/api.ts`
- Modify: `frontend/src/app/app.config.ts`, `frontend/src/app/app.ts` + `.html`

- [ ] **Step 1: Generate the workspace** (run from repo root `/Users/lars/Documents/work/eigenes/simple-feed-reader`)

```bash
npx -y -p @angular/cli@20 ng new frontend \
  --style=scss --ssr=false --routing --skip-git --package-manager=npm --defaults
```

Expected: creates `frontend/` with `package.json`, `angular.json`, `src/app/app.ts`, `app.config.ts`, `app.routes.ts`, and a Karma-based `*.spec.ts`. `frontend/.gitignore` already ignores `node_modules/` and `dist/`.

- [ ] **Step 2: Verify the baseline builds** (from `frontend/`)

```bash
cd frontend && npm run build
```

Expected: `Application bundle generation complete`. No errors.

- [ ] **Step 3: Generate environment files**

```bash
npx ng generate environments
```

Then set them:

```ts
// src/environments/environment.ts  (production default)
export const environment = {
  production: true,
  apiBaseUrl: '',
};
```

```ts
// src/environments/environment.development.ts
export const environment = {
  production: false,
  apiBaseUrl: 'https://localhost:8443',
};
```

- [ ] **Step 4: Create the API base-URL injection token**

```ts
// src/app/core/api.ts
import { InjectionToken } from '@angular/core';

/** Absolute base for every backend call. '' in prod (same-origin), the Docker
 *  origin in dev. Injected so tests can override it. */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL');
```

- [ ] **Step 5: Wire the base URL and reset the root component**

```ts
// src/app/app.config.ts
import { ApplicationConfig, provideZoneChangeDetection } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { routes } from './app.routes';
import { API_BASE_URL } from './core/api';
import { environment } from '../environments/environment';

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes),
    provideHttpClient(),
    { provide: API_BASE_URL, useValue: environment.apiBaseUrl },
  ],
};
```

```ts
// src/app/app.ts
import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {}
```

```html
<!-- src/app/app.html -->
<router-outlet />
```

Leave `app.scss` empty for now. Ensure `app.routes.ts` exports `export const routes: Routes = [];` (Task 13 fills it).

> **Root-file naming:** Angular 20's `ng new` scaffolds the root as `app.ts` (class `App`) with `app.html`/`app.scss`/`app.config.ts`/`app.routes.ts`. If your installed CLI emits `app.component.ts` (class `AppComponent`) instead, keep whatever it produced and adjust the import in `app.spec.ts` (Task 2) accordingly — everything else in this plan hand-creates its files with the `*.component.ts` convention regardless.

- [ ] **Step 6: Verify build still passes and the package-lock is tracked**

```bash
npm run build
cd .. && git add frontend && git status --short | grep 'frontend/package-lock.json' || git add -f frontend/package-lock.json
```

Expected: build passes; `frontend/package-lock.json` appears staged (a global gitignore may hide lockfiles — force-add if the `grep` finds nothing, mirroring how the repo force-tracks `composer.lock`).

- [ ] **Step 7: Commit**

```bash
git add frontend && git commit -m "feat(frontend): scaffold Angular 20 workspace with env-based API base URL"
```

---

## Task 2: Replace Karma with Jest

**Files:**
- Create: `frontend/jest.config.ts`, `frontend/setup-jest.ts`, `frontend/jest-global-mocks.ts`
- Create: `frontend/tsconfig.spec.json` (replace CLI's Karma types)
- Modify: `frontend/package.json` (scripts + deps), `frontend/angular.json` (drop `test` target)
- Delete: any `src/**/*.spec.ts` Karma sample if it references `jasmine`

- [ ] **Step 1: Install Jest and the Angular preset** (from `frontend/`)

```bash
npm install -D jest jest-preset-angular @types/jest jest-environment-jsdom jsdom
```

- [ ] **Step 2: Write the Jest config**

```ts
// jest.config.ts
import type { Config } from 'jest';
import { createCjsPreset } from 'jest-preset-angular/presets/index.js';

export default {
  displayName: 'frontend',
  ...createCjsPreset(),
  setupFilesAfterEnv: ['<rootDir>/setup-jest.ts'],
  testPathIgnorePatterns: ['<rootDir>/node_modules/', '<rootDir>/e2e/'],
  collectCoverageFrom: ['src/**/*.ts', '!src/**/*.spec.ts', '!src/main.ts'],
} satisfies Config;
```

```ts
// setup-jest.ts
import { setupZoneTestEnv } from 'jest-preset-angular/setup-env/zone';
import './jest-global-mocks';

setupZoneTestEnv({
  errorOnUnknownElements: true,
  errorOnUnknownProperties: true,
});
```

```ts
// jest-global-mocks.ts
// jsdom lacks matchMedia (ThemeService) and, in some Node versions, an
// exposed crypto.subtle (ALTCHA solver). Provide both for tests.
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  }),
});

if (!globalThis.crypto?.subtle) {
  // Node's WebCrypto, exposed under the same API the browser uses.
  const { webcrypto } = require('node:crypto');
  Object.defineProperty(globalThis, 'crypto', { value: webcrypto });
}
```

- [ ] **Step 3: Point `tsconfig.spec.json` at Jest types**

```jsonc
// tsconfig.spec.json
{
  "extends": "./tsconfig.json",
  "compilerOptions": {
    "outDir": "./out-tsc/spec",
    "types": ["jest", "node"]
  },
  "include": ["src/**/*.spec.ts", "src/**/*.d.ts"]
}
```

- [ ] **Step 4: Update scripts and drop the Karma `test` target**

In `package.json` `scripts`, set `"test": "jest"` and `"test:watch": "jest --watch"`. Remove the Karma/Jasmine devDependencies (`karma*`, `jasmine*`, `@types/jasmine`) and delete the `"test"` architect target block from `angular.json` (the `test` builder object under the project's `architect`/`targets`).

- [ ] **Step 5: Write a smoke spec**

```ts
// src/app/app.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { App } from './app';

describe('App', () => {
  it('creates the root component', async () => {
    // provideRouter supplies the context <router-outlet> needs to render.
    await TestBed.configureTestingModule({ imports: [App], providers: [provideRouter([])] }).compileComponents();
    const fixture = TestBed.createComponent(App);
    expect(fixture.componentInstance).toBeTruthy();
  });
});
```

- [ ] **Step 6: Run Jest**

```bash
npm test
```

Expected: `Tests: 1 passed`. If `App` needs a router outlet context, the bare create still passes (RouterOutlet renders nothing without routes).

- [ ] **Step 7: Commit**

```bash
cd .. && git add frontend && git commit -m "chore(frontend): swap Karma for Jest (jest-preset-angular)"
```

---

## Task 3: Code-style gate — ESLint + Prettier + Stylelint

**Files:**
- Create: `frontend/eslint.config.js`, `.prettierrc.json`, `.prettierignore`, `.stylelintrc.json`
- Modify: `frontend/package.json` (scripts + deps)

- [ ] **Step 1: Add angular-eslint (flat config) via the schematic**

```bash
npx ng add angular-eslint --skip-confirmation
```

Expected: installs `angular-eslint`, `typescript-eslint`, `@eslint/js`, writes `eslint.config.js`, and adds a `lint` architect target. This produces the canonical flat config with a `**/*.ts` block (recommended + stylistic + `angular.configs.tsRecommended`, `processInlineTemplates`) and a `**/*.html` block (`templateRecommended` + `templateAccessibility`).

- [ ] **Step 2: Add Prettier and disable formatting rules in ESLint**

```bash
npm install -D prettier eslint-config-prettier
```

```json
// .prettierrc.json
{ "singleQuote": true, "printWidth": 100, "trailingComma": "all" }
```

```
// .prettierignore
dist
coverage
node_modules
```

Append `eslint-config-prettier` last in the `**/*.ts` `extends` array of `eslint.config.js` so it turns off stylistic rules that would fight Prettier:

```js
// in eslint.config.js, the TS block's extends, add as the LAST entry:
const prettier = require('eslint-config-prettier');
// ...extends: [ eslint.configs.recommended, ...tseslint.configs.recommended,
//   ...tseslint.configs.stylistic, ...angular.configs.tsRecommended, prettier ],
```

- [ ] **Step 3: Add Stylelint with the token guard**

```bash
npm install -D stylelint stylelint-config-standard-scss
```

```json
// .stylelintrc.json
{
  "extends": ["stylelint-config-standard-scss"],
  "rules": {
    "color-no-hex": true,
    "scss/dollar-variable-pattern": null,
    "selector-class-pattern": null
  },
  "overrides": [
    {
      "files": ["src/app/theme/**/*.scss"],
      "rules": { "color-no-hex": null }
    }
  ]
}
```

`color-no-hex: true` everywhere except `theme/` is the guard that forces components to theme only through CSS custom properties; the theme files are the one place raw colour values are allowed.

- [ ] **Step 4: Add the aggregate scripts**

In `package.json` `scripts`:

```json
"lint": "ng lint",
"format": "prettier --write \"src/**/*.{ts,html,scss}\"",
"format:check": "prettier --check \"src/**/*.{ts,html,scss}\"",
"stylelint": "stylelint \"src/**/*.scss\"",
"check": "npm run lint && npm run format:check && npm run stylelint && npm test"
```

- [ ] **Step 5: Format the tree, then run the whole gate**

```bash
npm run format
npm run check
```

Expected: `lint` passes (fix any findings the scaffold produced), `format:check` passes after `format`, `stylelint` passes (no SCSS yet, so trivially green), Jest passes. From here on, `npm run check` is the gate for every task.

- [ ] **Step 6: Commit**

```bash
cd .. && git add frontend && git commit -m "chore(frontend): code-style gate — ESLint, Prettier, Stylelint (token-only colours)"
```

---

## Task 4: Design tokens and the Graphite theme

**Files:**
- Create: `frontend/src/app/theme/themes/_graphite.scss`, `frontend/src/app/theme/tokens.scss`
- Create: `frontend/src/styles/_reset.scss`, `frontend/src/styles/_base.scss`
- Modify: `frontend/src/styles.scss`

This task is CSS, so it is verified by `npm run build` + `npm run stylelint` rather than a unit test.

- [ ] **Step 1: Write the Graphite theme as two mixins** (hex is allowed here — this is `theme/`)

```scss
// src/app/theme/themes/_graphite.scss
// Graphite: muted greyscale with a muted teal accent. Two mixins, one per mode,
// so the token values live once and are applied to the [data-theme] selectors
// in tokens.scss.

@mixin light {
  --surface-0: #f5f5f4; // page canvas
  --surface-1: #ffffff; // panel / sidebar
  --surface-2: #ffffff; // raised card
  --border: #e4e4e2;
  --border-strong: #d4d4d1;
  --text-primary: #2a2a2a;
  --text-secondary: #5f5f5c;
  --text-muted: #8f8f8b;
  --accent: #3f8676;
  --accent-soft: #e9f1ef;
  --on-accent: #ffffff;
  --danger: #b3403a;
  --bg-danger: #f7e9e8;
  --success: #3f7a52;
  --bg-success: #e9f1ec;
}

@mixin dark {
  --surface-0: #161616;
  --surface-1: #1c1c1c;
  --surface-2: #242424;
  --border: #2a2a2a;
  --border-strong: #383836;
  --text-primary: #d8d8d6;
  --text-secondary: #9a9a97;
  --text-muted: #6a6a67;
  --accent: #5aa694;
  --accent-soft: #20302c;
  --on-accent: #0f1a17;
  --danger: #d98a86;
  --bg-danger: #2a1e1d;
  --success: #7faf8c;
  --bg-success: #1c2620;
}
```

- [ ] **Step 2: Apply the theme to the `data-theme` selectors, and add mode-invariant tokens**

```scss
// src/app/theme/tokens.scss
@use './themes/graphite';

// Light is the fallback when no attribute is set yet (before the no-flash
// script or ThemeService runs).
:root,
:root[data-theme='light'] {
  @include graphite.light;
}
:root[data-theme='dark'] {
  @include graphite.dark;
}

// Mode-invariant tokens (same in every theme).
:root {
  --radius: 8px;
  --control-h: 40px;
  --space-1: 4px;
  --space-2: 8px;
  --space-3: 12px;
  --space-4: 16px;
  --space-5: 24px;
  --space-6: 32px;
  --font-sans: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  --fs-sm: 13px;
  --fs-base: 15px;
  --fs-lg: 18px;
  --fs-xl: 24px;
}
```

- [ ] **Step 3: Write the reset and base typography** (tokens only — no hex)

```scss
// src/styles/_reset.scss
*,
*::before,
*::after {
  box-sizing: border-box;
}
* {
  margin: 0;
}
html,
body {
  height: 100%;
}
body {
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
img,
picture,
svg {
  display: block;
  max-width: 100%;
}
input,
button,
textarea,
select {
  font: inherit;
  color: inherit;
}
a {
  color: var(--accent);
  text-decoration: none;
}
```

```scss
// src/styles/_base.scss
body {
  font-family: var(--font-sans);
  font-size: var(--fs-base);
  background: var(--surface-0);
  color: var(--text-primary);
}
h1,
h2,
h3 {
  font-weight: 500;
  line-height: 1.25;
}
:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

// Shared form field: <label class="field"><span>Label</span><input /></label>.
// Global (unencapsulated) so every auth form reuses it without repeating CSS.
.field {
  display: block;
  margin-bottom: var(--space-4);
}
.field span {
  display: block;
  font-size: var(--fs-sm);
  color: var(--text-secondary);
  margin-bottom: var(--space-1);
}
.field input {
  width: 100%;
  height: var(--control-h);
  padding: 0 var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-1);
  color: var(--text-primary);
}
.field input:focus {
  border-color: var(--accent);
  outline: none;
}
```

- [ ] **Step 4: Compose the global stylesheet**

```scss
// src/styles.scss
@use './app/theme/tokens';
@use './styles/reset';
@use './styles/base';
```

- [ ] **Step 5: Verify**

```bash
cd frontend && npm run build && npm run stylelint
```

Expected: build succeeds; stylelint reports no problems (hex only appears under `theme/`, which is overridden).

- [ ] **Step 6: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): Graphite design tokens with light/dark and global base styles"
```

---

## Task 5: Self-hosted Material Symbols + Icon component

**Files:**
- Modify: `frontend/angular.json` (styles array), `frontend/package.json`
- Create: `frontend/src/app/shared/icon/icon.component.ts` + `.spec.ts`

- [ ] **Step 1: Install the self-hosted font package** (from `frontend/`)

```bash
npm install material-symbols
```

`material-symbols` bundles the Material Symbols woff2 files and CSS in `node_modules` — no runtime CDN.

- [ ] **Step 2: Include the outlined stylesheet in the build**

In `angular.json`, add to the project's `build.options.styles` array (before `src/styles.scss`):

```json
"node_modules/material-symbols/outlined.css",
"src/styles.scss"
```

The esbuild pipeline resolves the `url()` font references and copies the woff2 into the bundle.

- [ ] **Step 3: Write the failing Icon test**

```ts
// src/app/shared/icon/icon.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { IconComponent } from './icon.component';

@Component({ imports: [IconComponent], template: `<app-icon name="settings" />` })
class Host {}

describe('IconComponent', () => {
  it('renders the ligature name inside a material-symbols span', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const span: HTMLElement = fixture.nativeElement.querySelector('span.material-symbols-outlined');
    expect(span.textContent?.trim()).toBe('settings');
    expect(span.getAttribute('aria-hidden')).toBe('true');
  });
});
```

- [ ] **Step 4: Run it — fails** (`IconComponent` undefined)

```bash
npm test -- icon
```

Expected: FAIL (cannot find `./icon.component`).

- [ ] **Step 5: Implement the Icon component**

```ts
// src/app/shared/icon/icon.component.ts
import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-icon',
  template: `<span class="material-symbols-outlined" [style.font-size.px]="size" aria-hidden="true">{{
    name
  }}</span>`,
  styles: [
    `
      span {
        line-height: 1;
        user-select: none;
        font-variation-settings: 'opsz' 20;
      }
    `,
  ],
})
export class IconComponent {
  @Input({ required: true }) name!: string;
  @Input() size = 20;
}
```

- [ ] **Step 6: Run it — passes**

```bash
npm test -- icon
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): self-hosted Material Symbols and Icon component"
```

---

## Task 6: Shared UI primitives (button, form error, spinner)

**Files:**
- Create under `frontend/src/app/shared/`: `button/button.component.ts`, `form-error/form-error.component.ts`, `spinner/spinner.component.ts` + a single `shared.spec.ts`

These are presentational primitives styled only through tokens. Templates are inline; styles are inline CSS (no SCSS features needed, so stylelint's SCSS scope does not apply — and no hex is used). Form text fields are plain `<input>` elements styled by a global `.field` class (Task 4's `_base.scss`), so no field component is needed.

- [ ] **Step 1: Write the failing spec**

```ts
// src/app/shared/shared.spec.ts
import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { ButtonComponent } from './button/button.component';
import { FormErrorComponent } from './form-error/form-error.component';
import { SpinnerComponent } from './spinner/spinner.component';

@Component({
  imports: [ButtonComponent, FormErrorComponent, SpinnerComponent],
  template: `
    <app-button [loading]="true">Save</app-button>
    <app-form-error [message]="'Bad input'" />
    <app-spinner />
  `,
})
class Host {}

describe('shared primitives', () => {
  it('button shows a spinner when loading and disables itself', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('button')?.disabled).toBe(true);
    expect(el.querySelector('app-button app-spinner')).toBeTruthy();
    expect(el.querySelector('app-form-error')?.textContent).toContain('Bad input');
  });

  it('spinner exposes an accessible status role', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('app-spinner [role="status"]')).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- shared
```

Expected: FAIL (components missing).

- [ ] **Step 3: Implement the four primitives**

```ts
// src/app/shared/spinner/spinner.component.ts
import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-spinner',
  template: `<span
    class="spin"
    [style.width.px]="size"
    [style.height.px]="size"
    role="status"
    aria-label="Loading"
  ></span>`,
  styles: [
    `
      .spin {
        display: inline-block;
        border: 2px solid var(--border);
        border-top-color: var(--accent);
        border-radius: 50%;
        animation: r 0.7s linear infinite;
      }
      @keyframes r {
        to {
          transform: rotate(1turn);
        }
      }
    `,
  ],
})
export class SpinnerComponent {
  @Input() size = 18;
}
```

```ts
// src/app/shared/button/button.component.ts
import { Component, Input } from '@angular/core';
import { SpinnerComponent } from '../spinner/spinner.component';

@Component({
  selector: 'app-button',
  imports: [SpinnerComponent],
  template: `
    <button [type]="type" [disabled]="loading || disabled" [class.primary]="variant === 'primary'">
      @if (loading) {
        <app-spinner [size]="16" />
      } @else {
        <ng-content />
      }
    </button>
  `,
  styles: [
    `
      button {
        height: var(--control-h);
        padding: 0 var(--space-4);
        border-radius: var(--radius);
        border: 1px solid var(--border-strong);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }
      button.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
      button:disabled {
        cursor: default;
        opacity: 0.7;
      }
    `,
  ],
})
export class ButtonComponent {
  @Input() type: 'button' | 'submit' = 'button';
  @Input() variant: 'default' | 'primary' = 'default';
  @Input() loading = false;
  @Input() disabled = false;
}
```

```ts
// src/app/shared/form-error/form-error.component.ts
import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-form-error',
  template: `@if (message) {
    <p class="err" role="alert">{{ message }}</p>
  }`,
  styles: [
    `
      .err {
        color: var(--danger);
        background: var(--bg-danger);
        border-radius: var(--radius);
        padding: var(--space-2) var(--space-3);
        font-size: var(--fs-sm);
      }
    `,
  ],
})
export class FormErrorComponent {
  @Input() message: string | null = null;
}
```

Text fields are plain `<input>` inside a `<label class="field">`, styled by the global `.field` rules added in Task 4 — so the field CSS lives once and every auth form reuses it.

- [ ] **Step 4: Run it — passes**

```bash
npm test -- shared
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): shared UI primitives (button, text-field, form-error, spinner)"
```

---

## Task 7: Problem model and parser

**Files:**
- Create: `frontend/src/app/core/problem.ts` + `problem.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/core/problem.spec.ts
import { HttpErrorResponse } from '@angular/common/http';
import { parseProblem } from './problem';

describe('parseProblem', () => {
  it('reads a validation_error problem+json body', () => {
    const err = new HttpErrorResponse({
      status: 422,
      error: {
        type: 'validation_error',
        title: 'Validation failed',
        status: 422,
        errors: { email: ['Not a valid address'] },
      },
    });
    const p = parseProblem(err);
    expect(p.type).toBe('validation_error');
    expect(p.errors?.['email']?.[0]).toBe('Not a valid address');
  });

  it('carries accountStatus through for account_not_active', () => {
    const err = new HttpErrorResponse({
      status: 403,
      error: { type: 'account_not_active', title: 'x', status: 403, detail: 'nope', accountStatus: 'suspended' },
    });
    expect(parseProblem(err).accountStatus).toBe('suspended');
  });

  it('falls back to a generic problem when the body is not JSON', () => {
    const err = new HttpErrorResponse({ status: 0, error: 'Network down' });
    const p = parseProblem(err);
    expect(p.status).toBe(0);
    expect(p.title.length).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- problem
```

Expected: FAIL (no `./problem`).

- [ ] **Step 3: Implement**

```ts
// src/app/core/problem.ts
import { HttpErrorResponse } from '@angular/common/http';

export interface Problem {
  type: string;
  title: string;
  status: number;
  detail?: string;
  errors?: Record<string, string[]>;
  accountStatus?: string;
}

/** Map any HttpErrorResponse to the backend's problem+json contract, with a
 *  safe fallback when the body is missing or not JSON (network errors, gateways). */
export function parseProblem(err: HttpErrorResponse): Problem {
  const body: unknown = err.error;
  if (body && typeof body === 'object' && 'type' in body) {
    const b = body as Record<string, unknown>;
    return {
      type: String(b['type'] ?? 'about:blank'),
      title: String(b['title'] ?? 'Request failed'),
      status: typeof b['status'] === 'number' ? (b['status'] as number) : err.status,
      detail: typeof b['detail'] === 'string' ? (b['detail'] as string) : undefined,
      errors: (b['errors'] as Record<string, string[]> | undefined) ?? undefined,
      accountStatus: typeof b['accountStatus'] === 'string' ? (b['accountStatus'] as string) : undefined,
    };
  }
  return {
    type: 'about:blank',
    title: err.status === 0 ? 'Could not reach the server' : 'Something went wrong',
    status: err.status,
  };
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- problem
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): problem+json model and parser"
```

---

## Task 8: TokenStore

**Files:**
- Create: `frontend/src/app/core/token.store.ts` + `token.store.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/core/token.store.spec.ts
import { TestBed } from '@angular/core/testing';
import { TokenStore } from './token.store';

describe('TokenStore', () => {
  beforeEach(() => localStorage.clear());

  it('starts unauthenticated with no stored token', () => {
    const store = TestBed.inject(TokenStore);
    expect(store.token()).toBeNull();
    expect(store.isAuthenticated()).toBe(false);
  });

  it('persists and exposes a set token, and clears it', () => {
    const store = TestBed.inject(TokenStore);
    store.set('jwt-123');
    expect(store.token()).toBe('jwt-123');
    expect(store.isAuthenticated()).toBe(true);
    expect(localStorage.getItem('sfr.jwt')).toBe('jwt-123');
    store.clear();
    expect(store.token()).toBeNull();
    expect(localStorage.getItem('sfr.jwt')).toBeNull();
  });

  it('rehydrates from localStorage on construction', () => {
    localStorage.setItem('sfr.jwt', 'persisted');
    const store = TestBed.inject(TokenStore);
    expect(store.token()).toBe('persisted');
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- token.store
```

- [ ] **Step 3: Implement**

```ts
// src/app/core/token.store.ts
import { Injectable, computed, signal } from '@angular/core';

const KEY = 'sfr.jwt';

@Injectable({ providedIn: 'root' })
export class TokenStore {
  private readonly _token = signal<string | null>(localStorage.getItem(KEY));
  readonly token = this._token.asReadonly();
  readonly isAuthenticated = computed(() => this._token() !== null);

  set(jwt: string): void {
    localStorage.setItem(KEY, jwt);
    this._token.set(jwt);
  }

  clear(): void {
    localStorage.removeItem(KEY);
    this._token.set(null);
  }
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- token.store
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): JWT TokenStore (signal-backed localStorage)"
```

---

## Task 9: Auth HTTP interceptor

**Files:**
- Create: `frontend/src/app/core/auth.interceptor.ts` + `auth.interceptor.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/core/auth.interceptor.spec.ts
import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { TokenStore } from './token.store';
import { authInterceptor } from './auth.interceptor';

describe('authInterceptor', () => {
  let http: HttpClient;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;
  const navigate = jest.fn();

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
      ],
    });
    http = TestBed.inject(HttpClient);
    ctrl = TestBed.inject(HttpTestingController);
    tokens = TestBed.inject(TokenStore);
  });
  afterEach(() => ctrl.verify());

  it('attaches the bearer header to API requests when a token exists', () => {
    tokens.set('jwt-abc');
    http.get('https://api.test/api/me').subscribe();
    const req = ctrl.expectOne('https://api.test/api/me');
    expect(req.request.headers.get('Authorization')).toBe('Bearer jwt-abc');
    req.flush({});
  });

  it('does not attach a header when there is no token', () => {
    http.get('https://api.test/api/me').subscribe();
    const req = ctrl.expectOne('https://api.test/api/me');
    expect(req.request.headers.has('Authorization')).toBe(false);
    req.flush({});
  });

  it('clears the token and routes to /login on 401', () => {
    tokens.set('jwt-abc');
    http.get('https://api.test/api/me').subscribe({ error: () => undefined });
    ctrl.expectOne('https://api.test/api/me').flush(null, { status: 401, statusText: 'Unauthorized' });
    expect(tokens.token()).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login']);
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- auth.interceptor
```

- [ ] **Step 3: Implement**

```ts
// src/app/core/auth.interceptor.ts
import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { API_BASE_URL } from './api';
import { TokenStore } from './token.store';

/** Attaches the bearer token to API requests and, on 401, clears the session
 *  and sends the user to login. The token is the whole auth story — no cookie. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const base = inject(API_BASE_URL);
  const tokens = inject(TokenStore);
  const router = inject(Router);

  const isApi = req.url.startsWith(base ? base : '/') || req.url.startsWith('/api');
  const token = tokens.token();
  const authed = isApi && token ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : req;

  return next(authed).pipe(
    catchError((err) => {
      if (err.status === 401) {
        tokens.clear();
        void router.navigate(['/login']);
      }
      return throwError(() => err);
    }),
  );
};
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- auth.interceptor
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): bearer auth interceptor with 401 handling"
```

---

## Task 10: AuthService

**Files:**
- Create: `frontend/src/app/core/auth.service.ts` + `auth.service.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/core/auth.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { TokenStore } from './token.store';
import { AuthService } from './auth.service';

describe('AuthService', () => {
  let svc: AuthService;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;
  const navigate = jest.fn();

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
      ],
    });
    svc = TestBed.inject(AuthService);
    ctrl = TestBed.inject(HttpTestingController);
    tokens = TestBed.inject(TokenStore);
  });
  afterEach(() => ctrl.verify());

  it('login stores the returned JWT', () => {
    svc.login('a@b.c', 'password12345').subscribe();
    const req = ctrl.expectOne('https://api.test/api/auth/login');
    expect(req.request.body).toEqual({ email: 'a@b.c', password: 'password12345' });
    req.flush({ token: 'jwt-xyz' });
    expect(tokens.token()).toBe('jwt-xyz');
  });

  it('loadMe populates the current-user signal', () => {
    svc.loadMe().subscribe();
    ctrl
      .expectOne('https://api.test/api/me')
      .flush({ id: 1, email: 'a@b.c', roles: ['ROLE_USER'], status: 'active', createdAt: '2026-07-01T00:00:00+00:00' });
    expect(svc.user()?.email).toBe('a@b.c');
  });

  it('logout clears token and user and routes to /login', () => {
    tokens.set('jwt');
    svc.logout();
    expect(tokens.token()).toBeNull();
    expect(svc.user()).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login']);
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- auth.service
```

- [ ] **Step 3: Implement**

```ts
// src/app/core/auth.service.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { API_BASE_URL } from './api';
import { TokenStore } from './token.store';

export interface CurrentUser {
  id: number;
  email: string;
  roles: string[];
  status: string;
  createdAt: string;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly tokens = inject(TokenStore);
  private readonly router = inject(Router);

  readonly user = signal<CurrentUser | null>(null);

  login(email: string, password: string): Observable<{ token: string }> {
    return this.http
      .post<{ token: string }>(`${this.base}/api/auth/login`, { email, password })
      .pipe(tap((res) => this.tokens.set(res.token)));
  }

  loadMe(): Observable<CurrentUser> {
    return this.http.get<CurrentUser>(`${this.base}/api/me`).pipe(tap((u) => this.user.set(u)));
  }

  logout(): void {
    this.tokens.clear();
    this.user.set(null);
    void this.router.navigate(['/login']);
  }

  isAdmin(): boolean {
    return this.user()?.roles.includes('ROLE_ADMIN') ?? false;
  }
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- auth.service
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): AuthService (login, current user, logout)"
```

---

## Task 11: Route guards

**Files:**
- Create: `frontend/src/app/core/auth.guard.ts` + `auth.guard.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/core/auth.guard.spec.ts
import { TestBed } from '@angular/core/testing';
import { Router, UrlTree } from '@angular/router';
import { TokenStore } from './token.store';
import { authGuard, guestGuard } from './auth.guard';

describe('guards', () => {
  let tokens: TokenStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [{ provide: Router, useValue: { createUrlTree: (c: unknown[]) => ({ toString: () => c.join('/') }) as UrlTree } }],
    });
    tokens = TestBed.inject(TokenStore);
  });

  const run = (g: typeof authGuard) => TestBed.runInInjectionContext(() => g({} as never, {} as never));

  it('authGuard allows when authenticated, redirects otherwise', () => {
    expect(run(authGuard)).not.toBe(true);
    tokens.set('jwt');
    expect(run(authGuard)).toBe(true);
  });

  it('guestGuard allows when anonymous, redirects when authenticated', () => {
    expect(run(guestGuard)).toBe(true);
    tokens.set('jwt');
    expect(run(guestGuard)).not.toBe(true);
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- auth.guard
```

- [ ] **Step 3: Implement**

```ts
// src/app/core/auth.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { TokenStore } from './token.store';

export const authGuard: CanActivateFn = () => {
  const tokens = inject(TokenStore);
  return tokens.isAuthenticated() ? true : inject(Router).createUrlTree(['/login']);
};

export const guestGuard: CanActivateFn = () => {
  const tokens = inject(TokenStore);
  return tokens.isAuthenticated() ? inject(Router).createUrlTree(['/']) : true;
};
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- auth.guard
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): authGuard and guestGuard"
```

---

## Task 12: ThemeService, registry, and no-flash boot

**Files:**
- Create: `frontend/src/app/theme/theme.service.ts` + `theme.service.spec.ts`, `frontend/src/app/theme/themes/registry.ts`
- Modify: `frontend/src/index.html` (no-flash inline script)

- [ ] **Step 1: Write the registry**

```ts
// src/app/theme/themes/registry.ts
export type ThemeMode = 'light' | 'dark' | 'system';

/** Registered themes. 5a ships Graphite; a new theme adds one SCSS file and one
 *  entry here — no component changes. */
export const THEMES = [{ id: 'graphite', label: 'Graphite' }] as const;
```

- [ ] **Step 2: Write the failing ThemeService test**

```ts
// src/app/theme/theme.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { ThemeService } from './theme.service';

describe('ThemeService', () => {
  const attr = () => document.documentElement.getAttribute('data-theme');
  let mql: { matches: boolean; addEventListener: jest.Mock };

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
    mql = { matches: false, addEventListener: jest.fn() };
    window.matchMedia = jest.fn().mockReturnValue(mql) as unknown as typeof window.matchMedia;
  });

  it('defaults to the system preference when nothing is saved (light)', () => {
    const svc = TestBed.inject(ThemeService);
    expect(svc.mode()).toBe('system');
    expect(attr()).toBe('light');
  });

  it('resolves system=dark from prefers-color-scheme', () => {
    mql.matches = true;
    TestBed.inject(ThemeService);
    expect(attr()).toBe('dark');
  });

  it('applies and persists an explicit choice', () => {
    const svc = TestBed.inject(ThemeService);
    svc.setMode('dark');
    expect(attr()).toBe('dark');
    expect(localStorage.getItem('sfr.theme')).toBe('dark');
  });

  it('a saved choice wins over system on construction', () => {
    localStorage.setItem('sfr.theme', 'dark');
    TestBed.inject(ThemeService);
    expect(attr()).toBe('dark');
  });
});
```

- [ ] **Step 3: Run it — fails**

```bash
npm test -- theme.service
```

- [ ] **Step 4: Implement**

```ts
// src/app/theme/theme.service.ts
import { Injectable, signal } from '@angular/core';
import { ThemeMode } from './themes/registry';

const KEY = 'sfr.theme';

@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly media = window.matchMedia('(prefers-color-scheme: dark)');
  readonly mode = signal<ThemeMode>(this.readSaved());

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

  private resolved(): 'light' | 'dark' {
    const m = this.mode();
    return m === 'system' ? (this.media.matches ? 'dark' : 'light') : m;
  }

  private applyResolved(): void {
    document.documentElement.setAttribute('data-theme', this.resolved());
  }
}
```

- [ ] **Step 5: Run it — passes**

```bash
npm test -- theme.service
```

- [ ] **Step 6: Add the no-flash boot script** to `<head>` in `src/index.html`, before Angular loads:

```html
<script>
  (function () {
    try {
      var m = localStorage.getItem('sfr.theme');
      var dark = m === 'dark' || ((m === 'system' || !m) && matchMedia('(prefers-color-scheme: dark)').matches);
      document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    } catch (e) {}
  })();
</script>
```

- [ ] **Step 7: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): ThemeService (light/dark/system) with no-flash boot"
```

> **Note on auth/shell components (Tasks 13–21):** each is a single-file standalone component (`*.component.ts` + `*.component.spec.ts`) using an inline `template` and inline flat-CSS `styles` (token-based, no hex — so Stylelint's SCSS scope does not apply). This keeps each unit self-contained.

---

## Task 13: Routes, HTTP interceptor wiring, and the auth shell

**Files:**
- Create: `frontend/src/app/auth/auth-shell/auth-shell.component.ts`
- Modify: `frontend/src/app/app.config.ts`, `frontend/src/app/app.routes.ts`
- Create: `frontend/src/app/app.routes.spec.ts`

- [ ] **Step 1: Wire the interceptor into HttpClient**

```ts
// src/app/app.config.ts — replace provideHttpClient() with:
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { authInterceptor } from './core/auth.interceptor';
// ...
provideHttpClient(withInterceptors([authInterceptor])),
```

- [ ] **Step 2: Build the auth shell (centered card)**

```ts
// src/app/auth/auth-shell/auth-shell.component.ts
import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-auth-shell',
  template: `
    <main>
      <section class="card">
        <h1>{{ title }}</h1>
        @if (subtitle) {
          <p class="sub">{{ subtitle }}</p>
        }
        <ng-content />
      </section>
    </main>
  `,
  styles: [
    `
      main {
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: var(--space-4);
        background: var(--surface-0);
      }
      .card {
        width: 100%;
        max-width: 380px;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: var(--space-6);
      }
      h1 {
        font-size: var(--fs-xl);
        margin-bottom: var(--space-2);
      }
      .sub {
        color: var(--text-muted);
        font-size: var(--fs-sm);
        margin-bottom: var(--space-5);
      }
    `,
  ],
})
export class AuthShellComponent {
  @Input({ required: true }) title!: string;
  @Input() subtitle: string | null = null;
}
```

- [ ] **Step 3: Write the route table** (lazy-loaded standalone components)

```ts
// src/app/app.routes.ts
import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth.guard';

export const routes: Routes = [
  { path: 'login', canActivate: [guestGuard], loadComponent: () => import('./auth/login/login.component').then((m) => m.LoginComponent) },
  { path: 'register', canActivate: [guestGuard], loadComponent: () => import('./auth/register/register.component').then((m) => m.RegisterComponent) },
  { path: 'verify-email', loadComponent: () => import('./auth/verify-email/verify-email.component').then((m) => m.VerifyEmailComponent) },
  { path: 'reset-password-request', canActivate: [guestGuard], loadComponent: () => import('./auth/reset-request/reset-request.component').then((m) => m.ResetRequestComponent) },
  { path: 'reset-password', loadComponent: () => import('./auth/reset-password/reset-password.component').then((m) => m.ResetPasswordComponent) },
  { path: 'auth/callback', loadComponent: () => import('./auth/oauth-callback/oauth-callback.component').then((m) => m.OAuthCallbackComponent) },
  { path: '', canActivate: [authGuard], loadComponent: () => import('./shell/shell.component').then((m) => m.ShellComponent) },
  { path: '**', redirectTo: '' },
];
```

- [ ] **Step 4: Write a route-shape test** (guards the exact contract paths)

```ts
// src/app/app.routes.spec.ts
import { routes } from './app.routes';

describe('routes', () => {
  const paths = routes.map((r) => r.path);
  it('exposes the exact paths the backend links to', () => {
    for (const p of ['login', 'register', 'verify-email', 'reset-password-request', 'reset-password', 'auth/callback', '']) {
      expect(paths).toContain(p);
    }
  });
});
```

- [ ] **Step 5: Verify the route test only**

```bash
cd frontend && npm test -- app.routes
```

Expected: the routes test passes. **Do not run `npm run check` or `npm run build` yet** — the lazy `loadComponent` targets are created in Tasks 14–21, so lint/build would fail on the missing modules until then. `app.routes.spec.ts` only inspects the route array; it never resolves the dynamic imports. The full gate and production build come online at Task 21, which runs `npm run check` + `npm run build` once every target exists.

- [ ] **Step 6: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): routing, interceptor wiring, and auth shell"
```

---

## Task 14: Login screen

**Files:**
- Create: `frontend/src/app/auth/login/login.component.ts` + `login.component.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/auth/login/login.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { LoginComponent } from './login.component';

describe('LoginComponent', () => {
  let ctrl: HttpTestingController;
  const navigate = jest.fn();

  beforeEach(async () => {
    localStorage.clear();
    navigate.mockReset();
    await TestBed.configureTestingModule({
      imports: [LoginComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
  });

  function create() {
    const f = TestBed.createComponent(LoginComponent);
    f.detectChanges(); // triggers ngOnInit → providers GET
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: ['google'] });
    return f;
  }

  it('lists OAuth providers and builds provider URLs', () => {
    const f = create();
    expect(f.componentInstance.providers()).toEqual(['google']);
    expect(f.componentInstance.oauthUrl('google')).toBe('https://api.test/api/auth/oauth/google');
  });

  it('logs in, loads the user, and navigates home', () => {
    const f = create();
    f.componentInstance.form.setValue({ email: 'a@b.c', password: 'password12345' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/auth/login').flush({ token: 'jwt' });
    ctrl.expectOne('https://api.test/api/me').flush({ id: 1, email: 'a@b.c', roles: [], status: 'active', createdAt: 'x' });
    expect(navigate).toHaveBeenCalledWith(['/']);
  });

  it('renders the problem detail on a failed login', () => {
    const f = create();
    f.componentInstance.form.setValue({ email: 'a@b.c', password: 'wrongpass1234' });
    f.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/auth/login')
      .flush({ type: 'invalid_credentials', title: 'x', status: 401, detail: 'Email address or password is incorrect.' }, { status: 401, statusText: 'Unauthorized' });
    expect(f.componentInstance.error()).toBe('Email address or password is incorrect.');
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- login
```

- [ ] **Step 3: Implement**

```ts
// src/app/auth/login/login.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { parseProblem } from '../../core/problem';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule, RouterLink, AuthShellComponent, ButtonComponent, FormErrorComponent],
  template: `
    <app-auth-shell title="Sign in" subtitle="Welcome back to your reader.">
      <form (ngSubmit)="submit()" [formGroup]="form">
        <label class="field">
          <span>Email</span>
          <input type="email" formControlName="email" autocomplete="email" />
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" formControlName="password" autocomplete="current-password" />
        </label>
        <app-form-error [message]="error()" />
        <app-button type="submit" variant="primary" [loading]="loading()">Sign in</app-button>
      </form>

      @if (providers().length) {
        <div class="divider"><span>or</span></div>
        @for (p of providers(); track p) {
          <button class="oauth" type="button" (click)="startOAuth(p)">Continue with {{ label(p) }}</button>
        }
      }

      <p class="links">
        <a routerLink="/register">Create account</a>
        <a routerLink="/reset-password-request">Forgot password?</a>
      </p>
    </app-auth-shell>
  `,
  styles: [
    `
      .divider {
        text-align: center;
        color: var(--text-muted);
        font-size: var(--fs-sm);
        margin: var(--space-4) 0;
      }
      .oauth {
        width: 100%;
        height: var(--control-h);
        margin-bottom: var(--space-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .links {
        display: flex;
        justify-content: space-between;
        margin-top: var(--space-5);
        font-size: var(--fs-sm);
      }
    `,
  ],
})
export class LoginComponent implements OnInit {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly router = inject(Router);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly providers = signal<string[]>([]);

  ngOnInit(): void {
    this.http.get<{ providers: string[] }>(`${this.base}/api/auth/oauth/providers`).subscribe({
      next: (r) => this.providers.set(r.providers ?? []),
      error: () => this.providers.set([]),
    });
  }

  submit(): void {
    if (this.form.invalid || this.loading()) return;
    this.loading.set(true);
    this.error.set(null);
    const { email, password } = this.form.getRawValue();
    this.auth.login(email, password).subscribe({
      next: () =>
        this.auth.loadMe().subscribe({
          next: () => void this.router.navigate(['/']),
          error: () => void this.router.navigate(['/']),
        }),
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e).detail ?? 'Sign in failed.');
        this.loading.set(false);
      },
    });
  }

  oauthUrl(provider: string): string {
    return `${this.base}/api/auth/oauth/${provider}`;
  }

  startOAuth(provider: string): void {
    location.assign(this.oauthUrl(provider));
  }

  label(provider: string): string {
    return provider.charAt(0).toUpperCase() + provider.slice(1);
  }
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- login
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): login screen with password and OAuth entry"
```

---

## Task 15: ALTCHA solver and challenge service

**Files:**
- Create: `frontend/src/app/auth/altcha.ts` + `altcha.spec.ts`
- Create: `frontend/src/app/auth/altcha.service.ts` + `altcha.service.spec.ts`

- [ ] **Step 1: Write the failing solver test** (generates its own vector with WebCrypto)

```ts
// src/app/auth/altcha.spec.ts
import { AltchaChallenge, solveAltcha } from './altcha';

async function sha256hex(input: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

describe('solveAltcha', () => {
  it('finds the number whose sha256(salt+n) matches and encodes the payload', async () => {
    const salt = 'abc?expires=999';
    const number = 7;
    const challenge = await sha256hex(salt + number);
    const c: AltchaChallenge = { algorithm: 'SHA-256', challenge, salt, signature: 'sig', maxnumber: 50 };

    const payload = await solveAltcha(c);
    const decoded = JSON.parse(atob(payload));
    expect(decoded).toEqual({ algorithm: 'SHA-256', challenge, number: 7, salt, signature: 'sig' });
  });

  it('throws when the challenge is unsolvable within maxnumber', async () => {
    const c: AltchaChallenge = { algorithm: 'SHA-256', challenge: 'deadbeef', salt: 's', signature: 'x', maxnumber: 3 };
    await expect(solveAltcha(c)).rejects.toThrow();
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- altcha.spec
```

- [ ] **Step 3: Implement the solver**

```ts
// src/app/auth/altcha.ts
export interface AltchaChallenge {
  algorithm: string;
  challenge: string;
  salt: string;
  signature: string;
  maxnumber: number;
}

/** Brute-force the ALTCHA proof-of-work: find the smallest n≥0 whose
 *  sha256hex(salt+n) equals the challenge, then base64-encode the solution the
 *  backend's verify() expects. Costs the honest client measurable CPU; the
 *  backend enforces the difficulty floor. */
export async function solveAltcha(c: AltchaChallenge): Promise<string> {
  const enc = new TextEncoder();
  for (let n = 0; n <= c.maxnumber; n++) {
    const digest = await crypto.subtle.digest('SHA-256', enc.encode(c.salt + n));
    const hex = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    if (hex === c.challenge) {
      return btoa(JSON.stringify({ algorithm: c.algorithm, challenge: c.challenge, number: n, salt: c.salt, signature: c.signature }));
    }
  }
  throw new Error('ALTCHA challenge could not be solved');
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- altcha.spec
```

- [ ] **Step 5: Write the failing challenge-service test**

```ts
// src/app/auth/altcha.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { AltchaService } from './altcha.service';

describe('AltchaService', () => {
  it('fetches a challenge from the backend', () => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'https://api.test' }],
    });
    const svc = TestBed.inject(AltchaService);
    const ctrl = TestBed.inject(HttpTestingController);
    let got: unknown;
    svc.challenge().subscribe((c) => (got = c));
    ctrl.expectOne('https://api.test/api/auth/altcha-challenge').flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    expect(got).toEqual({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
  });
});
```

- [ ] **Step 6: Implement the service, run it, verify passes**

```ts
// src/app/auth/altcha.service.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { AltchaChallenge } from './altcha';

@Injectable({ providedIn: 'root' })
export class AltchaService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  challenge(): Observable<AltchaChallenge> {
    return this.http.get<AltchaChallenge>(`${this.base}/api/auth/altcha-challenge`);
  }
}
```

```bash
npm test -- altcha
```

Expected: all ALTCHA tests pass.

- [ ] **Step 7: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): ALTCHA proof-of-work solver and challenge service"
```

---

## Task 16: Register screen

**Files:**
- Create: `frontend/src/app/auth/register/register.component.ts` + `register.component.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
// src/app/auth/register/register.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { RegisterComponent } from './register.component';
import * as altcha from '../altcha';

describe('RegisterComponent', () => {
  let ctrl: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [RegisterComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'https://api.test' }],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    jest.spyOn(altcha, 'solveAltcha').mockResolvedValue('SOLVED');
  });

  it('solves ALTCHA and registers, then shows the pending message', async () => {
    const f = TestBed.createComponent(RegisterComponent);
    const c = f.componentInstance;
    c.form.setValue({ email: 'a@b.c', password: 'password12345' });
    const done = c.submit();
    ctrl.expectOne('https://api.test/api/auth/altcha-challenge').flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((r) => setTimeout(r)); // drain the challenge→solve→post microtask chain
    const reg = ctrl.expectOne('https://api.test/api/auth/register');
    expect(reg.request.body).toEqual({ email: 'a@b.c', password: 'password12345', altcha: 'SOLVED' });
    reg.flush({ status: 'pending_verification' }, { status: 202, statusText: 'Accepted' });
    await done;
    expect(c.done()).toBe(true);
  });

  it('surfaces a field error from validation_error', async () => {
    const f = TestBed.createComponent(RegisterComponent);
    const c = f.componentInstance;
    c.form.setValue({ email: 'a@b.c', password: 'password12345' });
    const done = c.submit();
    ctrl.expectOne('https://api.test/api/auth/altcha-challenge').flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((r) => setTimeout(r)); // drain the challenge→solve→post microtask chain
    ctrl.expectOne('https://api.test/api/auth/register').flush(
      { type: 'validation_error', title: 'x', status: 422, errors: { email: ['Already registered'] } },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    await done;
    expect(c.error()).toContain('Already registered');
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- register
```

- [ ] **Step 3: Implement**

```ts
// src/app/auth/register/register.component.ts
import { Component, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { parseProblem } from '../../core/problem';
import { AltchaService } from '../altcha.service';
import { solveAltcha } from '../altcha';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';

@Component({
  selector: 'app-register',
  imports: [ReactiveFormsModule, RouterLink, AuthShellComponent, ButtonComponent, FormErrorComponent],
  template: `
    <app-auth-shell title="Create account" subtitle="Register, then confirm your email.">
      @if (done()) {
        <p class="ok">Check your email for a confirmation link. After you confirm, an administrator reviews your account before you can sign in.</p>
        <a routerLink="/login">Back to sign in</a>
      } @else {
        <form (ngSubmit)="submit()" [formGroup]="form">
          <label class="field">
            <span>Email</span>
            <input type="email" formControlName="email" autocomplete="email" />
          </label>
          <label class="field">
            <span>Password (at least 12 characters)</span>
            <input type="password" formControlName="password" autocomplete="new-password" />
          </label>
          <app-form-error [message]="error()" />
          <app-button type="submit" variant="primary" [loading]="loading()">Create account</app-button>
        </form>
        <p class="links"><a routerLink="/login">Already have an account?</a></p>
      }
    </app-auth-shell>
  `,
  styles: [
    `
      .ok {
        color: var(--text-secondary);
        margin-bottom: var(--space-4);
      }
      .links {
        margin-top: var(--space-5);
        font-size: var(--fs-sm);
      }
    `,
  ],
})
export class RegisterComponent {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly altcha = inject(AltchaService);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(12)]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly done = signal(false);

  async submit(): Promise<void> {
    if (this.form.invalid || this.loading()) return;
    this.loading.set(true);
    this.error.set(null);
    try {
      const challenge = await firstValueFrom(this.altcha.challenge());
      const solution = await solveAltcha(challenge);
      const { email, password } = this.form.getRawValue();
      await firstValueFrom(this.http.post(`${this.base}/api/auth/register`, { email, password, altcha: solution }));
      this.done.set(true);
    } catch (e) {
      const p = parseProblem(e as HttpErrorResponse);
      const firstFieldError = p.errors ? Object.values(p.errors)[0]?.[0] : undefined;
      this.error.set(firstFieldError ?? p.detail ?? 'Registration failed. Try again.');
    } finally {
      this.loading.set(false);
    }
  }
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- register
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): register screen with ALTCHA proof-of-work"
```

---

## Task 17: Verify-email screen

**Files:**
- Create: `frontend/src/app/auth/verify-email/verify-email.component.ts` + spec

- [ ] **Step 1: Write the failing test**

```ts
// src/app/auth/verify-email/verify-email.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { VerifyEmailComponent } from './verify-email.component';

function setup(token: string | null) {
  TestBed.configureTestingModule({
    imports: [VerifyEmailComponent],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: ActivatedRoute, useValue: { queryParamMap: of({ get: () => token }) } },
    ],
  });
  const f = TestBed.createComponent(VerifyEmailComponent);
  f.detectChanges();
  return { f, ctrl: TestBed.inject(HttpTestingController) };
}

describe('VerifyEmailComponent', () => {
  it('posts the token and reports success', () => {
    const { f, ctrl } = setup('tok-123');
    ctrl.expectOne((r) => r.url === 'https://api.test/api/auth/verify-email' && r.body.token === 'tok-123').flush({});
    expect(f.componentInstance.state()).toBe('ok');
  });

  it('reports error when the token is missing', () => {
    const { f, ctrl } = setup(null);
    ctrl.expectNone('https://api.test/api/auth/verify-email');
    expect(f.componentInstance.state()).toBe('error');
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- verify-email
```

- [ ] **Step 3: Implement**

```ts
// src/app/auth/verify-email/verify-email.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';

@Component({
  selector: 'app-verify-email',
  imports: [RouterLink, AuthShellComponent, SpinnerComponent],
  template: `
    <app-auth-shell title="Confirm email">
      @switch (state()) {
        @case ('loading') {
          <app-spinner />
        }
        @case ('ok') {
          <p>Your email is confirmed. An administrator will review your account before you can sign in.</p>
          <a routerLink="/login">Back to sign in</a>
        }
        @case ('error') {
          <p class="err">This confirmation link is invalid or has expired.</p>
          <a routerLink="/login">Back to sign in</a>
        }
      }
    </app-auth-shell>
  `,
  styles: [
    `
      .err {
        color: var(--danger);
        margin-bottom: var(--space-4);
      }
    `,
  ],
})
export class VerifyEmailComponent implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly route = inject(ActivatedRoute);
  readonly state = signal<'loading' | 'ok' | 'error'>('loading');

  ngOnInit(): void {
    this.route.queryParamMap.subscribe((params) => {
      const token = params.get('token');
      if (!token) {
        this.state.set('error');
        return;
      }
      this.http.post(`${this.base}/api/auth/verify-email`, { token }).subscribe({
        next: () => this.state.set('ok'),
        error: () => this.state.set('error'),
      });
    });
  }
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- verify-email
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): email verification screen"
```

---

## Task 18: Password-reset-request screen

**Files:**
- Create: `frontend/src/app/auth/reset-request/reset-request.component.ts` + spec

- [ ] **Step 1: Write the failing test**

```ts
// src/app/auth/reset-request/reset-request.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { ResetRequestComponent } from './reset-request.component';
import * as altcha from '../altcha';

describe('ResetRequestComponent', () => {
  let ctrl: HttpTestingController;
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ResetRequestComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'https://api.test' }],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    jest.spyOn(altcha, 'solveAltcha').mockResolvedValue('SOLVED');
  });

  it('solves ALTCHA, posts the request, and shows a neutral confirmation', async () => {
    const f = TestBed.createComponent(ResetRequestComponent);
    const c = f.componentInstance;
    c.form.setValue({ email: 'a@b.c' });
    const done = c.submit();
    ctrl.expectOne('https://api.test/api/auth/altcha-challenge').flush({ algorithm: 'SHA-256', challenge: 'c', salt: 's', signature: 'x', maxnumber: 5 });
    await new Promise((r) => setTimeout(r)); // drain the challenge→solve→post microtask chain
    const req = ctrl.expectOne('https://api.test/api/auth/password-reset-request');
    expect(req.request.body).toEqual({ email: 'a@b.c', altcha: 'SOLVED' });
    req.flush({});
    await done;
    expect(c.done()).toBe(true);
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- reset-request
```

- [ ] **Step 3: Implement**

```ts
// src/app/auth/reset-request/reset-request.component.ts
import { Component, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { AltchaService } from '../altcha.service';
import { solveAltcha } from '../altcha';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';

@Component({
  selector: 'app-reset-request',
  imports: [ReactiveFormsModule, RouterLink, AuthShellComponent, ButtonComponent],
  template: `
    <app-auth-shell title="Reset password" subtitle="We’ll email you a reset link.">
      @if (done()) {
        <p class="ok">If that address has an account, a reset link is on its way. The link is valid for 24 hours.</p>
        <a routerLink="/login">Back to sign in</a>
      } @else {
        <form (ngSubmit)="submit()" [formGroup]="form">
          <label class="field">
            <span>Email</span>
            <input type="email" formControlName="email" autocomplete="email" />
          </label>
          <app-button type="submit" variant="primary" [loading]="loading()">Send reset link</app-button>
        </form>
        <p class="links"><a routerLink="/login">Back to sign in</a></p>
      }
    </app-auth-shell>
  `,
  styles: [
    `
      .ok {
        color: var(--text-secondary);
        margin-bottom: var(--space-4);
      }
      .links {
        margin-top: var(--space-5);
        font-size: var(--fs-sm);
      }
    `,
  ],
})
export class ResetRequestComponent {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly altcha = inject(AltchaService);

  readonly form = this.fb.group({ email: ['', [Validators.required, Validators.email]] });
  readonly loading = signal(false);
  readonly done = signal(false);

  async submit(): Promise<void> {
    if (this.form.invalid || this.loading()) return;
    this.loading.set(true);
    try {
      const challenge = await firstValueFrom(this.altcha.challenge());
      const solution = await solveAltcha(challenge);
      await firstValueFrom(this.http.post(`${this.base}/api/auth/password-reset-request`, { email: this.form.getRawValue().email, altcha: solution }));
    } catch {
      // Neutral by design: never reveal whether the address exists or the call failed.
    } finally {
      this.done.set(true);
      this.loading.set(false);
    }
  }
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- reset-request
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): password-reset request screen (neutral, ALTCHA)"
```

---

## Task 19: Password-reset (set new password) screen

**Files:**
- Create: `frontend/src/app/auth/reset-password/reset-password.component.ts` + spec

- [ ] **Step 1: Write the failing test**

```ts
// src/app/auth/reset-password/reset-password.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { ResetPasswordComponent } from './reset-password.component';

describe('ResetPasswordComponent', () => {
  const navigate = jest.fn();
  function setup(token: string) {
    TestBed.configureTestingModule({
      imports: [ResetPasswordComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate } },
        { provide: ActivatedRoute, useValue: { queryParamMap: of({ get: () => token }) } },
      ],
    });
    const f = TestBed.createComponent(ResetPasswordComponent);
    f.detectChanges();
    return { f, ctrl: TestBed.inject(HttpTestingController) };
  }

  it('posts token+password and navigates to login on success', () => {
    navigate.mockReset();
    const { f, ctrl } = setup('tok-9');
    f.componentInstance.form.setValue({ password: 'newpassword12' });
    f.componentInstance.submit();
    const req = ctrl.expectOne('https://api.test/api/auth/password-reset');
    expect(req.request.body).toEqual({ token: 'tok-9', password: 'newpassword12' });
    req.flush({});
    expect(navigate).toHaveBeenCalledWith(['/login'], { queryParams: { reset: '1' } });
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- reset-password
```

- [ ] **Step 3: Implement**

```ts
// src/app/auth/reset-password/reset-password.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { parseProblem } from '../../core/problem';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';

@Component({
  selector: 'app-reset-password',
  imports: [ReactiveFormsModule, RouterLink, AuthShellComponent, ButtonComponent, FormErrorComponent],
  template: `
    <app-auth-shell title="Set a new password">
      @if (token()) {
        <form (ngSubmit)="submit()" [formGroup]="form">
          <label class="field">
            <span>New password (at least 12 characters)</span>
            <input type="password" formControlName="password" autocomplete="new-password" />
          </label>
          <app-form-error [message]="error()" />
          <app-button type="submit" variant="primary" [loading]="loading()">Save password</app-button>
        </form>
      } @else {
        <p class="err">This reset link is invalid or has expired.</p>
        <a routerLink="/reset-password-request">Request a new link</a>
      }
    </app-auth-shell>
  `,
  styles: [
    `
      .err {
        color: var(--danger);
        margin-bottom: var(--space-4);
      }
    `,
  ],
})
export class ResetPasswordComponent implements OnInit {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly token = signal<string | null>(null);
  readonly form = this.fb.group({ password: ['', [Validators.required, Validators.minLength(12)]] });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  ngOnInit(): void {
    this.route.queryParamMap.subscribe((p) => this.token.set(p.get('token')));
  }

  submit(): void {
    const token = this.token();
    if (!token || this.form.invalid || this.loading()) return;
    this.loading.set(true);
    this.error.set(null);
    this.http.post(`${this.base}/api/auth/password-reset`, { token, password: this.form.getRawValue().password }).subscribe({
      next: () => void this.router.navigate(['/login'], { queryParams: { reset: '1' } }),
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e).detail ?? 'Could not reset the password. Request a new link.');
        this.loading.set(false);
      },
    });
  }
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- reset-password
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): set-new-password screen"
```

---

## Task 20: OAuth callback screen

**Files:**
- Create: `frontend/src/app/auth/oauth-callback/oauth-callback.component.ts` + spec

- [ ] **Step 1: Write the failing test**

```ts
// src/app/auth/oauth-callback/oauth-callback.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { TokenStore } from '../../core/token.store';
import { OAuthCallbackComponent } from './oauth-callback.component';

function setup(params: Record<string, string | null>) {
  const navigate = jest.fn();
  TestBed.configureTestingModule({
    imports: [OAuthCallbackComponent],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: Router, useValue: { navigate } },
      { provide: ActivatedRoute, useValue: { queryParamMap: of({ get: (k: string) => params[k] ?? null }) } },
    ],
  });
  localStorage.clear();
  const f = TestBed.createComponent(OAuthCallbackComponent);
  f.detectChanges();
  return { f, ctrl: TestBed.inject(HttpTestingController), navigate, tokens: TestBed.inject(TokenStore) };
}

describe('OAuthCallbackComponent', () => {
  it('exchanges the code CREDENTIALED, stores the token, loads me, and navigates home', () => {
    const { ctrl, navigate, tokens } = setup({ code: 'one-time' });
    const req = ctrl.expectOne('https://api.test/api/auth/oauth/exchange');
    expect(req.request.withCredentials).toBe(true);
    expect(req.request.body).toEqual({ code: 'one-time' });
    req.flush({ token: 'jwt-oauth' });
    expect(tokens.token()).toBe('jwt-oauth');
    ctrl.expectOne('https://api.test/api/me').flush({ id: 1, email: 'a@b.c', roles: [], status: 'active', createdAt: 'x' });
    expect(navigate).toHaveBeenCalledWith(['/']);
  });

  it('shows the error and does not call exchange when the provider returned ?error', () => {
    const { f, ctrl } = setup({ error: 'access_denied' });
    ctrl.expectNone('https://api.test/api/auth/oauth/exchange');
    expect(f.componentInstance.state()).toBe('error');
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- oauth-callback
```

- [ ] **Step 3: Implement**

```ts
// src/app/auth/oauth-callback/oauth-callback.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { TokenStore } from '../../core/token.store';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';

@Component({
  selector: 'app-oauth-callback',
  imports: [RouterLink, AuthShellComponent, SpinnerComponent],
  template: `
    <app-auth-shell title="Signing you in">
      @switch (state()) {
        @case ('loading') {
          <app-spinner />
        }
        @case ('error') {
          <p class="err">Sign-in did not complete. Please try again.</p>
          <a routerLink="/login">Back to sign in</a>
        }
      }
    </app-auth-shell>
  `,
  styles: [
    `
      .err {
        color: var(--danger);
        margin-bottom: var(--space-4);
      }
    `,
  ],
})
export class OAuthCallbackComponent implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly tokens = inject(TokenStore);
  private readonly auth = inject(AuthService);
  readonly state = signal<'loading' | 'error'>('loading');

  ngOnInit(): void {
    this.route.queryParamMap.subscribe((params) => {
      const error = params.get('error');
      const code = params.get('code');
      if (error || !code) {
        this.state.set('error');
        return;
      }
      // CREDENTIALED: the one-time code is only half — the flow cookie is the
      // other half. Omitting withCredentials yields a 400 identical to a bad code.
      this.http.post<{ token: string }>(`${this.base}/api/auth/oauth/exchange`, { code }, { withCredentials: true }).subscribe({
        next: (res) => {
          this.tokens.set(res.token);
          this.auth.loadMe().subscribe({
            next: () => void this.router.navigate(['/']),
            error: () => void this.router.navigate(['/']),
          });
        },
        error: () => this.state.set('error'),
      });
    });
  }
}
```

- [ ] **Step 4: Run it — passes**

```bash
npm test -- oauth-callback
```

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): OAuth callback (credentialed exchange)"
```

---

## Task 21: Authenticated shell (placeholder for 5b)

**Files:**
- Create: `frontend/src/app/shell/shell.component.ts` + spec

- [ ] **Step 1: Write the failing test**

```ts
// src/app/shell/shell.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { ThemeService } from '../theme/theme.service';
import { ShellComponent } from './shell.component';

describe('ShellComponent', () => {
  let ctrl: HttpTestingController;
  beforeEach(async () => {
    localStorage.clear();
    window.matchMedia = jest.fn().mockReturnValue({ matches: false, addEventListener: jest.fn() }) as never;
    await TestBed.configureTestingModule({
      imports: [ShellComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate: jest.fn() } },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loads the current user on init and shows the email', () => {
    const f = TestBed.createComponent(ShellComponent);
    f.detectChanges();
    ctrl.expectOne('https://api.test/api/me').flush({ id: 1, email: 'me@ex.com', roles: [], status: 'active', createdAt: 'x' });
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).textContent).toContain('me@ex.com');
  });

  it('setMode on the theme service changes the applied theme', () => {
    const f = TestBed.createComponent(ShellComponent);
    f.detectChanges();
    ctrl.expectOne('https://api.test/api/me').flush({ id: 1, email: 'me@ex.com', roles: [], status: 'active', createdAt: 'x' });
    TestBed.inject(ThemeService).setMode('dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    // sanity: AuthService is wired
    expect(TestBed.inject(AuthService).user()?.email).toBe('me@ex.com');
  });
});
```

- [ ] **Step 2: Run it — fails**

```bash
npm test -- shell
```

- [ ] **Step 3: Implement**

```ts
// src/app/shell/shell.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { AuthService } from '../core/auth.service';
import { ThemeService } from '../theme/theme.service';
import { ThemeMode } from '../theme/themes/registry';
import { IconComponent } from '../shared/icon/icon.component';

@Component({
  selector: 'app-shell',
  imports: [IconComponent],
  template: `
    <header>
      <span class="brand">Simple Feed Reader</span>
      <div class="right">
        <div class="theme" role="group" aria-label="Theme">
          @for (m of modes; track m.id) {
            <button [class.active]="theme.mode() === m.id" (click)="theme.setMode(m.id)" [attr.aria-pressed]="theme.mode() === m.id" [title]="m.label">
              <app-icon [name]="m.icon" [size]="18" />
            </button>
          }
        </div>
        <div class="account">
          <button (click)="menuOpen.set(!menuOpen())" aria-haspopup="menu" [attr.aria-expanded]="menuOpen()">
            {{ auth.user()?.email ?? '…' }}
            <app-icon name="expand_more" [size]="18" />
          </button>
          @if (menuOpen()) {
            <div class="menu" role="menu">
              <button role="menuitem" (click)="auth.logout()">Sign out</button>
            </div>
          }
        </div>
      </div>
    </header>
    <main>
      <p class="placeholder">Your reader lands here in 5b.</p>
    </main>
  `,
  styles: [
    `
      header {
        height: 56px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 var(--space-4);
        border-bottom: 1px solid var(--border);
        background: var(--surface-1);
      }
      .brand {
        font-weight: 500;
      }
      .right {
        display: flex;
        align-items: center;
        gap: var(--space-4);
      }
      .theme {
        display: inline-flex;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow: hidden;
      }
      .theme button {
        padding: var(--space-2);
        background: var(--surface-1);
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
      }
      .theme button.active {
        background: var(--accent-soft);
        color: var(--accent);
      }
      .account {
        position: relative;
      }
      .account > button {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        background: none;
        border: none;
        color: var(--text-primary);
        cursor: pointer;
      }
      .menu {
        position: absolute;
        right: 0;
        top: 40px;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        min-width: 140px;
      }
      .menu button {
        width: 100%;
        text-align: left;
        padding: var(--space-3);
        background: none;
        border: none;
        color: var(--text-primary);
        cursor: pointer;
      }
      main {
        padding: var(--space-6);
      }
      .placeholder {
        color: var(--text-muted);
      }
    `,
  ],
})
export class ShellComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly theme = inject(ThemeService);
  readonly menuOpen = signal(false);
  readonly modes: { id: ThemeMode; label: string; icon: string }[] = [
    { id: 'light', label: 'Light', icon: 'light_mode' },
    { id: 'dark', label: 'Dark', icon: 'dark_mode' },
    { id: 'system', label: 'System', icon: 'contrast' },
  ];

  ngOnInit(): void {
    if (!this.auth.user()) this.auth.loadMe().subscribe({ error: () => undefined });
  }
}
```

- [ ] **Step 4: Run it — passes, then run the full gate and a production build**

```bash
npm test -- shell && npm run check && npm run build
```

Expected: shell tests pass; `npm run check` green; `npm run build` now succeeds (all lazy route targets exist).

- [ ] **Step 5: Commit**

```bash
cd .. && git add frontend && git commit -m "feat(frontend): authenticated shell with theme toggle and account menu"
```

---

## Task 22: Playwright integration smoke against Docker

**Files:**
- Create: `frontend/playwright.config.ts`, `frontend/e2e/auth-smoke.spec.ts`
- Modify: `frontend/package.json` (deps + scripts)

**Precondition:** the Docker stack is up (`docker compose up -d` from the repo root) so `https://localhost:8443` responds. This smoke exercises the *real* cross-origin + CORS + problem+json path that mocked unit tests cannot.

- [ ] **Step 1: Install Playwright** (from `frontend/`)

```bash
npm install -D @playwright/test && npx playwright install chromium
```

- [ ] **Step 2: Configure Playwright to serve the app on :4200 and trust the mkcert dev cert**

```ts
// playwright.config.ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  use: { baseURL: 'http://localhost:4200', ignoreHTTPSErrors: true },
  webServer: {
    command: 'npm start -- --host localhost --port 4200',
    url: 'http://localhost:4200',
    reuseExistingServer: true,
    timeout: 120_000,
  },
});
```

`--port 4200` matters: the backend's CORS allows exactly `http://localhost:4200` (`APP_FRONTEND_URL`).

- [ ] **Step 3: Write the smoke** (unauthenticated paths + theme — no seeded account needed)

```ts
// e2e/auth-smoke.spec.ts
import { test, expect } from '@playwright/test';

test('login page loads and offers registration', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Create account' })).toBeVisible();
});

test('a wrong password shows the backend message (real cross-origin + problem+json)', async ({ page }) => {
  await page.goto('/login');
  await page.locator('input[type=email]').fill('nobody@example.com');
  await page.locator('input[type=password]').fill('definitely-wrong-1');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText(/incorrect/i)).toBeVisible();
});

test('theme choice persists across reload', async ({ page }) => {
  await page.goto('/login');
  await page.evaluate(() => localStorage.setItem('sfr.theme', 'dark'));
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});
```

- [ ] **Step 4: Add the script** to `package.json`: `"e2e": "playwright test"`.

- [ ] **Step 5: Run it** (Docker up)

```bash
npm run e2e
```

Expected: 3 passed. If Docker is down, the wrong-password test fails on network — document this precondition in the commit body.

- [ ] **Step 6: Commit**

```bash
cd .. && git add frontend && git commit -m "test(frontend): Playwright auth smoke against the Docker stack"
```

---

## Task 23: CI wiring, docs, and final gate sweep

**Files:**
- Modify: `.github/workflows/*.yml` (add a frontend job)
- Modify: `README.md`, `docs/architecture.md` (frontend note)
- Create: `frontend/README.md`

- [ ] **Step 1: Inspect the existing CI and add a frontend job**

```bash
ls .github/workflows/
```

Add a job (in the existing workflow or a new `frontend.yml`) that runs on pushes touching `frontend/`:

```yaml
  frontend:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: frontend
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
          cache-dependency-path: frontend/package-lock.json
      - run: npm ci
      - run: npm run check
      - run: npm run build
```

(The Playwright smoke needs the Docker stack and is not part of this unit-gate job; run it locally or in a dedicated integration job later.)

- [ ] **Step 2: Document the frontend** — add a `frontend/README.md` covering: install (`npm ci`), dev (`npm start` on :4200 against Docker `https://localhost:8443`), `npm run check`, `npm run build` (outputs to `dist/`; the production copy into `backend/public/app/` is a release step), theming (Graphite tokens, add a theme = new SCSS + registry entry), and that the token in `localStorage` is the whole auth story. Update the root `README.md` line "with an Angular SPA to follow" to point at `frontend/`.

- [ ] **Step 3: Configure the production build output path** for release. In `frontend/angular.json`, note (or set via a named configuration) that the release build copies to `backend/public/app/`. For 5a, document it in `frontend/README.md`; do not wire the copy into every CI run (design spec implies release-time).

- [ ] **Step 4: Final gate sweep**

```bash
cd frontend && npm run check && npm run build && cd ..
```

Expected: green. Confirm no stray hex outside `theme/` (`npm run stylelint` is part of `check`).

- [ ] **Step 5: Confirm the working tree is clean and the lockfile is tracked**

```bash
git status --short
git ls-files frontend/package-lock.json
```

Expected: `package-lock.json` is listed (tracked); no unexpected untracked files.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "chore(frontend): CI frontend job and documentation"
```

---

## Definition of done (5a)

- `frontend/` Angular 20 workspace builds (`npm run build`) and the full gate (`npm run check`) is green: ESLint + Prettier + Stylelint + Jest.
- Graphite theme applies in light and dark; `ThemeService` resolves saved/system, persists, and reacts to OS changes; no theme flash on load.
- A user can, against the real backend: register (ALTCHA solved), confirm email, sign in (password or OAuth), reset a password, and land in the authenticated shell; sign out returns to `/login`.
- The bearer token is the only credential (localStorage + interceptor); the OAuth exchange is the sole credentialed call.
- Stylelint proves no component hard-codes a colour (tokens only).
- The Playwright smoke passes against the Docker stack.
- Handoff to 5b: the shell placeholder is the frame the reader builds into.
