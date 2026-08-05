# Navigation Watchdog Implementation Plan (#285)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A navigation watchdog that turns a stalled in-app navigation — hung lazy chunk, hung guard, hung resolver — into a visible retry banner instead of a silently dead click.

**Architecture:** Three small units. `navigation-failure.ts` owns the single decision "how do we report a broken navigation": before anything has rendered it reveals the static `#boot-error` surface (nothing else can render then), afterwards it sets a signal. `navigation-watchdog.ts` arms an 8000 ms timer on `NavigationStart` and clears it on any terminal router event, reporting on expiry. The root component renders an `@if`-guarded shared error banner from that signal. The existing `withNavigationErrorHandler` is re-pointed at the same reporter, which stops a mid-session chunk *failure* from replacing a working page with "The app could not start".

**Tech Stack:** Angular 20 (standalone, signals, `@if` control flow), RxJS, `@jsverse/transloco`, Jest + `TestBed`, Playwright.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-05-navigation-watchdog-design.md`.
- Deadline is exactly **8000 ms**, a named exported constant with a comment recording why it differs from the boot watchdog's 15 s (a mid-session navigation starts from a working connection; a cold start may face a dead radio).
- **Do NOT add a per-route `import()` wrapper.** The design explicitly rejected bounding each `loadComponent`/`loadChildren`; `app.routes.ts` must not change.
- **The banner must never be static markup in `app.html`.** The #282 boot watchdog in `index.html` cancels its timer when `<app-root>` holds any element other than `<router-outlet>`; static chrome would disarm it at ~70 ms and re-open #282. An `@if` whose condition is false renders only a comment anchor, which `querySelector` ignores. This is load-bearing, and Task 3 has a test for it.
- Do NOT modify: `boot-language.ts`, `transloco-loader.ts`, `app.routes.ts`, `deploy/strato/.htaccess`, anything in `backend/`. In `index.html` change ONLY the watchdog comment (Task 4) — never its script logic.
- House style (`CLAUDE.md`): standalone components, signals, no NgModules; `final`-equivalent small units; names reveal intent; comments explain *why*. Component styles live in a sibling `.scss` file, never inline in the `.ts`.
- **No hex colours and no raw `px`** in `.scss` outside `src/app/theme/` — use tokens such as `var(--space-3)`. Both fail `npm run check`.
- Frontend gate: `npm run check` from `frontend/` (ESLint + Prettier 100-col + Stylelint + Jest). Write compliant up front; long test chains need manual line breaks for Prettier.
- Playwright e2e runs from `frontend/` via `npm run e2e` and needs the Docker stack (`docker compose up -d` from the repo root). Never `docker compose down -v`.
- Never use `git stash`, `git checkout -- <file>`, `git checkout <branch>`, or `git reset` — the checkout is shared with concurrent sessions. To temporarily revert a file, copy it to the scratchpad, edit in place, then copy back and verify with `git diff --stat -- <file>` that the restore is byte-exact.
- The Bash working directory can reset between calls — `cd` with absolute paths in every command.
- Commit messages: `fix(#285): <imperative summary>`.

---

### Task 1: The failure reporter

**Files:**
- Create: `frontend/src/app/core/navigation-failure.ts`
- Test: `frontend/src/app/core/navigation-failure.spec.ts`

**Interfaces:**
- Consumes: `revealBootErrorSurface(error: unknown): void` from `./boot-error-surface` (existing, DI-free plain function).
- Produces: `NavigationFailureReporter`, an `@Injectable({providedIn: 'root'})` service with:
  - `readonly failed: Signal<boolean>` — true while the banner should show.
  - `report(error: unknown): void` — routes to the boot surface or the signal.
  - `noteNavigationSucceeded(): void` — records that a render happened and clears the banner.

  Task 2 calls `report` and `noteNavigationSucceeded`; Task 3 reads `failed`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/core/navigation-failure.spec.ts`:

```ts
// src/app/core/navigation-failure.spec.ts
import { TestBed } from '@angular/core/testing';
import { NavigationFailureReporter } from './navigation-failure';

describe('NavigationFailureReporter', () => {
  let reporter: NavigationFailureReporter;
  let bootSurface: HTMLElement;

  beforeEach(() => {
    // The real static surface from index.html, which the reporter reveals by
    // removing `hidden` — the same element the production document carries.
    bootSurface = document.createElement('div');
    bootSurface.id = 'boot-error';
    bootSurface.hidden = true;
    document.body.appendChild(bootSurface);
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
    reporter = TestBed.inject(NavigationFailureReporter);
  });

  afterEach(() => {
    bootSurface.remove();
    jest.restoreAllMocks();
  });

  it('reveals the static surface when nothing has rendered yet', () => {
    reporter.report(new Error('chunk load failed'));

    expect(bootSurface.hasAttribute('hidden')).toBe(false);
    expect(reporter.failed()).toBe(false);
  });

  it('shows the banner instead once a navigation has succeeded', () => {
    reporter.noteNavigationSucceeded();

    reporter.report(new Error('chunk load failed'));

    expect(reporter.failed()).toBe(true);
    expect(bootSurface.hasAttribute('hidden')).toBe(true);
  });

  it('clears the banner when a later navigation succeeds', () => {
    reporter.noteNavigationSucceeded();
    reporter.report(new Error('chunk load failed'));

    reporter.noteNavigationSucceeded();

    expect(reporter.failed()).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

From `frontend/`: `npx jest src/app/core/navigation-failure.spec.ts`
Expected: FAIL — cannot resolve `./navigation-failure`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/app/core/navigation-failure.ts`:

```ts
// src/app/core/navigation-failure.ts
import { Injectable, Signal, signal } from '@angular/core';
import { revealBootErrorSurface } from './boot-error-surface';

/**
 * The one place that decides how a broken navigation reaches the user (#285).
 *
 * Before anything has rendered, the only thing that can carry a message is the
 * static `#boot-error` div in index.html — no component tree exists to host a
 * banner, which is why that surface exists at all (#280). After a successful
 * navigation the app is alive and a working page is on screen, so replacing it
 * with a full-page "The app could not start" would be both heavy-handed and
 * false; the banner reports the failure and leaves the page standing.
 *
 * Two callers report here: the navigation watchdog (a stall, which raises no
 * router event) and app.config.ts's `withNavigationErrorHandler` (a rejection).
 */
@Injectable({ providedIn: 'root' })
export class NavigationFailureReporter {
  private readonly hasRendered = signal(false);
  private readonly bannerVisible = signal(false);

  readonly failed: Signal<boolean> = this.bannerVisible.asReadonly();

  report(error: unknown): void {
    if (!this.hasRendered()) {
      revealBootErrorSurface(error);
      return;
    }
    console.error(error);
    this.bannerVisible.set(true);
  }

  /**
   * A completed navigation both proves the app can render and retracts any
   * banner still on screen: a stall the watchdog gave up on may resolve late,
   * and the user should not be told to retry something that just worked.
   */
  noteNavigationSucceeded(): void {
    this.hasRendered.set(true);
    this.bannerVisible.set(false);
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

From `frontend/`: `npx jest src/app/core/navigation-failure.spec.ts`
Expected: PASS, 3/3.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/navigation-failure.ts frontend/src/app/core/navigation-failure.spec.ts
git commit -m "fix(#285): add the navigation failure reporter"
```

---

### Task 2: The watchdog

**Files:**
- Create: `frontend/src/app/core/navigation-watchdog.ts`
- Test: `frontend/src/app/core/navigation-watchdog.spec.ts`

**Interfaces:**
- Consumes: `NavigationFailureReporter` from `./navigation-failure` with `report(error: unknown): void` and `noteNavigationSucceeded(): void`.
- Produces:
  - `NAVIGATION_DEADLINE_MS = 8000`.
  - `startNavigationWatchdog(): void` — an injection-context function, called from `app.config.ts` in Task 4.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/core/navigation-watchdog.spec.ts`:

```ts
// src/app/core/navigation-watchdog.spec.ts
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { NavigationCancel, NavigationEnd, NavigationError, NavigationStart } from '@angular/router';
import { Subject } from 'rxjs';
import { NavigationFailureReporter } from './navigation-failure';
import { NAVIGATION_DEADLINE_MS, startNavigationWatchdog } from './navigation-watchdog';

describe('startNavigationWatchdog', () => {
  let events: Subject<unknown>;
  let reporter: { report: jest.Mock; noteNavigationSucceeded: jest.Mock };

  beforeEach(() => {
    jest.useFakeTimers();
    events = new Subject<unknown>();
    reporter = { report: jest.fn(), noteNavigationSucceeded: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        { provide: Router, useValue: { events } },
        { provide: NavigationFailureReporter, useValue: reporter },
      ],
    });
    TestBed.runInInjectionContext(() => startNavigationWatchdog());
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('reports a navigation that never terminates', () => {
    events.next(new NavigationStart(1, '/settings'));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS);

    expect(reporter.report).toHaveBeenCalledTimes(1);
  });

  it('stays silent while the deadline has not passed', () => {
    events.next(new NavigationStart(1, '/settings'));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS - 1);

    expect(reporter.report).not.toHaveBeenCalled();
  });

  it('cancels on a completed navigation, and records the success', () => {
    events.next(new NavigationStart(1, '/settings'));
    events.next(new NavigationEnd(1, '/settings', '/settings'));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS * 2);

    expect(reporter.report).not.toHaveBeenCalled();
    expect(reporter.noteNavigationSucceeded).toHaveBeenCalledTimes(1);
  });

  it('cancels on a redirected navigation', () => {
    // Guards redirect: setupRedirectGuard and guestGuard both do, so a cancel
    // must not be mistaken for a stall.
    events.next(new NavigationStart(1, '/login'));
    events.next(new NavigationCancel(1, '/login', 'guard redirect'));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS * 2);

    expect(reporter.report).not.toHaveBeenCalled();
  });

  it('cancels on a failed navigation, leaving the error handler to report it', () => {
    events.next(new NavigationStart(1, '/settings'));
    events.next(new NavigationError(1, '/settings', new Error('chunk failed')));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS * 2);

    expect(reporter.report).not.toHaveBeenCalled();
  });

  it('restarts the deadline for a second navigation instead of stacking timers', () => {
    events.next(new NavigationStart(1, '/settings'));
    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS - 1_000);
    events.next(new NavigationStart(2, '/discover'));

    jest.advanceTimersByTime(1_000);
    expect(reporter.report).not.toHaveBeenCalled();

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS);
    expect(reporter.report).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

From `frontend/`: `npx jest src/app/core/navigation-watchdog.spec.ts`
Expected: FAIL — cannot resolve `./navigation-watchdog`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/app/core/navigation-watchdog.ts`:

```ts
// src/app/core/navigation-watchdog.ts
import { DestroyRef, inject } from '@angular/core';
import {
  NavigationCancel,
  NavigationEnd,
  NavigationError,
  NavigationStart,
  Router,
} from '@angular/router';
import { NavigationFailureReporter } from './navigation-failure';

/**
 * How long a navigation may run before the app admits it is not coming (#285).
 *
 * Shorter than the boot watchdog's 15 s in index.html, deliberately: that one
 * covers a cold start on a radio that may still be reconnecting, while this one
 * starts from a connection that just worked and waits on a single chunk. A
 * false positive costs little — a completed navigation retracts the banner.
 */
export const NAVIGATION_DEADLINE_MS = 8000;

/**
 * Turns a navigation that never terminates into a visible failure.
 *
 * A hung `import()` never rejects, so the router raises no `NavigationError`
 * and `withNavigationErrorHandler` cannot fire: the click is silently dead
 * with the previous page still on screen (#285). The same holds for a guard or
 * resolver that never settles — `authGuard` and `setupRedirectGuard` issue HTTP
 * with no timeout. Watching the event stream covers all of them at once,
 * without every route entry having to remember a wrapper.
 */
export function startNavigationWatchdog(): void {
  const router = inject(Router);
  const reporter = inject(NavigationFailureReporter);
  let timer: ReturnType<typeof setTimeout> | undefined;

  const cancel = (): void => {
    clearTimeout(timer);
    timer = undefined;
  };

  const subscription = router.events.subscribe((event) => {
    if (event instanceof NavigationStart) {
      // Re-arm rather than stack: a second navigation supersedes the first.
      cancel();
      timer = setTimeout(() => reporter.report(new Error(`Navigation to ${event.url} stalled.`)), NAVIGATION_DEADLINE_MS);
      return;
    }
    if (event instanceof NavigationEnd) {
      cancel();
      reporter.noteNavigationSucceeded();
      return;
    }
    if (event instanceof NavigationCancel || event instanceof NavigationError) {
      // A cancel is a guard redirect, and an error already reaches the user
      // through withNavigationErrorHandler; neither is this watchdog's to
      // report, but both end the navigation the deadline was watching.
      cancel();
    }
  });

  inject(DestroyRef).onDestroy(() => {
    cancel();
    subscription.unsubscribe();
  });
}
```

Note: the `setTimeout` line above exceeds Prettier's 100 columns. Run `npx prettier --write src/app/core/navigation-watchdog.ts` and keep its formatting.

- [ ] **Step 4: Run the test to verify it passes**

From `frontend/`: `npx jest src/app/core/navigation-watchdog.spec.ts`
Expected: PASS, 6/6.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/navigation-watchdog.ts frontend/src/app/core/navigation-watchdog.spec.ts
git commit -m "fix(#285): add the navigation watchdog"
```

---

### Task 3: The banner in the root component

**Files:**
- Modify: `frontend/src/app/app.ts`, `frontend/src/app/app.html`, `frontend/src/app/app.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/app.spec.ts` (replace the file)

**Interfaces:**
- Consumes: `NavigationFailureReporter` with `readonly failed: Signal<boolean>` from `./core/navigation-failure`; `ErrorBannerComponent` from `./shared/error-banner/error-banner.component`, whose inputs are `message` (required string) and `actionLabel` (string | null) and whose output is `action`.
- Produces: nothing later tasks consume.

- [ ] **Step 1: Add the translation keys**

In `frontend/public/i18n/en.json`, inside the existing top-level `"common"` object (which already holds `"retry": "Retry"`), add:

```json
    "navigationFailed": "That page did not load."
```

In `frontend/public/i18n/de.json`, inside its `"common"` object (which already holds `"retry": "Erneut versuchen"`), add:

```json
    "navigationFailed": "Diese Seite wurde nicht geladen."
```

Keep each file's existing key order and indentation; add the new key after `"retry"`.

- [ ] **Step 2: Write the failing test**

Replace the whole of `frontend/src/app/app.spec.ts` with:

```ts
// src/app/app.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { signal } from '@angular/core';
import { provideTranslocoTesting } from '../testing/transloco-testing';
import { App } from './app';
import { NavigationFailureReporter } from './core/navigation-failure';

describe('App', () => {
  const failed = signal(false);

  async function render() {
    await TestBed.configureTestingModule({
      // provideTranslocoTesting() returns a ModuleWithProviders and belongs in
      // `imports`, as every other component spec in this repo does it. It loads
      // the real shipped dictionaries, so the assertions below are against the
      // actual English UI strings.
      imports: [App, provideTranslocoTesting()],
      // provideRouter supplies the context <router-outlet> needs to render.
      providers: [
        provideRouter([]),
        { provide: NavigationFailureReporter, useValue: { failed } },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    return fixture;
  }

  beforeEach(() => {
    failed.set(false);
    TestBed.resetTestingModule();
  });

  it('creates the root component', async () => {
    const fixture = await render();
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('renders no element besides the outlet while navigation is healthy', async () => {
    const fixture = await render();

    // Load-bearing, not cosmetic: index.html's boot watchdog (#282) cancels
    // its 15 s timer as soon as <app-root> holds any element that is not
    // <router-outlet>. Static chrome here would disarm it ~70 ms into
    // bootstrap and bring back the blank page of #282, so the banner must
    // stay behind an @if — which renders a comment anchor, not an element.
    const host: HTMLElement = fixture.nativeElement;
    expect(host.querySelector(':not(router-outlet)')).toBeNull();
  });

  it('renders the retry banner when navigation has failed', async () => {
    const fixture = await render();

    failed.set(true);
    fixture.detectChanges();

    const banner: HTMLElement | null = fixture.nativeElement.querySelector('.banner');
    expect(banner?.textContent).toContain('That page did not load.');
    expect(banner?.querySelector('button')?.textContent).toContain('Retry');
  });
});
```

- [ ] **Step 3: Run the test to verify it fails**

From `frontend/`: `npx jest src/app/app.spec.ts`
Expected: FAIL — the banner assertions find no `.banner`, because `app.html` still holds only the outlet.

`provideTranslocoTesting` is the project's existing helper at `frontend/src/testing/transloco-testing.ts`. Do not invent a second one.

- [ ] **Step 4: Write the implementation**

Replace `frontend/src/app/app.ts` with:

```ts
// src/app/app.ts
import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { ErrorBannerComponent } from './shared/error-banner/error-banner.component';
import { NavigationFailureReporter } from './core/navigation-failure';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, ErrorBannerComponent, TranslocoPipe],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  protected readonly navigation = inject(NavigationFailureReporter);

  /**
   * A stalled chunk leaves a pending module promise that the module system
   * caches, so re-navigating would await the same hung fetch and the button
   * would look broken. Only a fresh document gets a fresh module graph.
   */
  protected reload(): void {
    location.reload();
  }
}
```

Replace `frontend/src/app/app.html` with:

```html
@if (navigation.failed()) {
  <div class="failure">
    <app-error-banner
      [message]="'common.navigationFailed' | transloco"
      [actionLabel]="'common.retry' | transloco"
      (action)="reload()"
    />
  </div>
}
<router-outlet />
```

Put this in `frontend/src/app/app.scss` (currently empty):

```scss
// src/app/app.scss

// The banner sits above the routed page rather than replacing it: the page
// still works, only the navigation away from it failed (#285).
.failure {
  padding: var(--space-3);
}
```

- [ ] **Step 5: Run the test to verify it passes**

From `frontend/`: `npx jest src/app/app.spec.ts`
Expected: PASS, 3/3.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/app.ts frontend/src/app/app.html frontend/src/app/app.scss frontend/src/app/app.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "fix(#285): show a retry banner when a navigation fails mid-session"
```

---

### Task 4: Wire it up and re-point the error handler

**Files:**
- Modify: `frontend/src/app/app.config.ts`
- Modify: `frontend/src/index.html` (the boot-watchdog comment only)
- Test: `frontend/src/app/app.config.spec.ts`

**Interfaces:**
- Consumes: `startNavigationWatchdog(): void` from `./core/navigation-watchdog` (injection-context function) and `NavigationFailureReporter` with `report(error: unknown): void` from `./core/navigation-failure`.
- Produces: the wired application config.

- [ ] **Step 1: Read the existing config spec**

Read `frontend/src/app/app.config.spec.ts` in full first. It is short: one `describe('appConfig')` with a single `it` that calls `TestBed.configureTestingModule({ providers: appConfig.providers })` and asserts `LOCALE_WRITER` resolves to `HttpLocaleWriter`. Add to it; do not restructure it.

- [ ] **Step 2: Write the failing test**

Append this test inside the existing `describe('appConfig', …)` block, and add these imports to the top of the file:

```ts
import { Router } from '@angular/router';
import { NavigationFailureReporter } from './core/navigation-failure';
```

```ts
  it('routes a real navigation error through the reporter, not straight to the boot surface', async () => {
    // A functional test on purpose: calling reporter.report() directly would
    // assert nothing about whether withNavigationErrorHandler is wired to it.
    // resetConfig installs a route whose lazy load rejects, which is what a
    // failed chunk does, and lets the real router raise the real event.
    const surface = document.createElement('div');
    surface.id = 'boot-error';
    surface.hidden = true;
    document.body.appendChild(surface);
    jest.spyOn(console, 'error').mockImplementation(() => undefined);

    TestBed.configureTestingModule({ providers: appConfig.providers });
    const reporter = TestBed.inject(NavigationFailureReporter);
    const router = TestBed.inject(Router);
    // Mid-session: a page is already on screen, so the banner is the right
    // surface and the full-page one would be a lie.
    reporter.noteNavigationSucceeded();
    router.resetConfig([
      { path: 'broken', loadComponent: () => Promise.reject(new Error('chunk failed')) },
    ]);

    await router.navigateByUrl('/broken').catch(() => undefined);

    expect(reporter.failed()).toBe(true);
    expect(surface.hasAttribute('hidden')).toBe(true);

    surface.remove();
    jest.restoreAllMocks();
  });
```

If `navigateByUrl` rejects rather than resolving, the `.catch` above already absorbs it — the assertion is about what the handler did, not about the promise.

- [ ] **Step 3: Run the test to verify it fails**

From `frontend/`: `npx jest src/app/app.config.spec.ts`
Expected: FAIL — the handler still calls `revealBootErrorSurface`, so `#boot-error` loses `hidden` and/or `failed()` stays false.

- [ ] **Step 4: Write the implementation**

In `frontend/src/app/app.config.ts`:

Replace the `revealBootErrorSurface` import with:

```ts
import { NavigationFailureReporter } from './core/navigation-failure';
import { startNavigationWatchdog } from './core/navigation-watchdog';
```

Replace the `provideRouter(...)` block and the comment above it with:

```ts
    // A lazy route chunk can fail or stall exactly like the dictionary fetch
    // (#280) — Brave's resume-reload serves main.js from the immutable cache
    // but a chunk evicted from the HTTP cache, or new since the last release,
    // breaks on the reconnecting radio. A failure raises NavigationError and
    // lands here; a stall raises nothing at all, which is what the navigation
    // watchdog below exists for (#285). Both report to the same place, which
    // decides between the static surface and an in-app banner.
    provideRouter(
      routes,
      withNavigationErrorHandler((event) => inject(NavigationFailureReporter).report(event.error)),
    ),
```

Add this initializer after the existing `provideAppInitializer` block:

```ts
    // Must run in an injection context, and only once the router exists.
    provideAppInitializer(() => startNavigationWatchdog()),
```

`inject` and `provideAppInitializer` are already imported in this file.

- [ ] **Step 5: Run the test to verify it passes**

From `frontend/`: `npx jest src/app/app.config.spec.ts`
Expected: PASS.

- [ ] **Step 6: Correct the boot-watchdog comment in `index.html`**

In `frontend/src/index.html`, inside the watchdog `<script>`, the observer comment currently ends with the sentence `If app.html ever gains static chrome, this condition must change with it.` Replace exactly that sentence with:

```
          // app.html keeps its failure banner behind an @if for this reason
          // (#285): a false @if renders only a comment anchor, which
          // querySelector ignores. Static chrome there would disarm this
          // watchdog ~70 ms into bootstrap and bring #282 back.
```

Change nothing else in that file — no script logic.

- [ ] **Step 7: Run the full frontend gate**

From `frontend/`: `npm run check`
Expected: clean. Jest total should be 913 + the new tests from Tasks 1–4.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/app.config.ts frontend/src/app/app.config.spec.ts frontend/src/index.html
git commit -m "fix(#285): wire the navigation watchdog and route errors through the reporter"
```

---

### Task 5: E2e proof against the real wiring

**Files:**
- Test: `frontend/e2e/navigation-watchdog.spec.ts` (create)
- Test: `frontend/e2e/boot-without-dictionary.spec.ts` (modify its third test only — see Step 4a)

**Interfaces:**
- Consumes: the wiring from Task 4; the banner markup from Task 3 (`.banner` class, English text `That page did not load.`).
- Produces: nothing.

A direct-invocation test can assert something the real wiring makes impossible, so the units from Tasks 1–4 need one test through the browser.

- [ ] **Step 1: Write the failing spec**

Create `frontend/e2e/navigation-watchdog.spec.ts`:

```ts
// e2e/navigation-watchdog.spec.ts
import { test, expect } from '@playwright/test';

/**
 * #285: an in-app navigation whose lazy chunk stalls is silently dead — a hung
 * import() never rejects, so no NavigationError is raised, and the #282 boot
 * watchdog has already disconnected on first render. The navigation watchdog
 * turns that into a retry banner after 8 s.
 *
 * This is the wiring test the Jest specs cannot be: they drive the watchdog
 * through a mocked event stream, which proves the logic but not that Angular
 * ever delivers those events.
 *
 * Chunk filenames are content-hashed, so the URL is discovered live: render
 * /register (its own chunk, already loaded), follow its in-app link to /login
 * and capture the one new chunk request that client-side navigation triggers.
 * That URL is then stalled on a fresh page — a fresh module graph, so the
 * browser cannot serve it from the page that already fetched it.
 *
 * The spec owns all the data it asserts on: no account, no seeded state.
 */
const NAVIGATION_DEADLINE_MS = 8_000;

test('shows the retry banner when an in-app navigation stalls', async ({ page, context }) => {
  test.setTimeout(60_000);

  await page.goto('/register');
  await page.waitForLoadState('networkidle');

  const [chunkRequest] = await Promise.all([
    page.waitForRequest((request) => /\/chunk-[^/?]+\.js/.test(request.url())),
    page.getByRole('link', { name: 'Already have an account?' }).click(),
  ]);
  const loginChunkUrl = chunkRequest.url();

  const stalledPage = await context.newPage();
  await stalledPage.goto('/register');
  await stalledPage.waitForLoadState('networkidle');

  // Only now, with /register rendered: the stall must hit an in-app
  // navigation, not the boot the #282 watchdog already covers.
  await stalledPage.route(loginChunkUrl, () => undefined);
  await stalledPage.getByRole('link', { name: 'Already have an account?' }).click();

  await expect(stalledPage.locator('.banner')).toContainText('That page did not load.', {
    timeout: NAVIGATION_DEADLINE_MS + 8_000,
  });
  // The whole point of the split: a working page keeps its content and does
  // not get the full-page "The app could not start" surface.
  await expect(stalledPage.locator('#boot-error')).toBeHidden();
  await expect(stalledPage.getByRole('button', { name: 'Retry' })).toBeVisible();

  await stalledPage.close();
});
```

- [ ] **Step 2: Run it and verify it passes**

From the repo root: `docker compose up -d` (skip if already up).
From `frontend/`: `npm run e2e -- navigation-watchdog.spec.ts` (allow 10 minutes)
Expected: PASS. If the banner never appears, capture the exact output and stop — do not weaken the assertions.

- [ ] **Step 3: Prove the test is falsifiable**

Copy `frontend/src/app/core/navigation-watchdog.ts` to the scratchpad. In the working-tree copy, change the `NavigationStart` branch so it never arms the timer (delete the `timer = setTimeout(...)` line). Re-run `npm run e2e -- navigation-watchdog.spec.ts` and confirm it FAILS. Then copy the scratchpad file back and verify `git diff --stat -- frontend/src/app/core/navigation-watchdog.ts` reports no difference. Never use git to revert — the checkout is shared.

- [ ] **Step 4: Verify no regression in the neighbouring boot specs**

From `frontend/`: `npm run e2e -- boot-watchdog.spec.ts boot-without-dictionary.spec.ts`
Expected: `boot-watchdog.spec.ts` 4/4, unchanged — it proves the `@if` banner did not disarm the #282 boot watchdog.

`boot-without-dictionary.spec.ts`'s third test, `reveals the boot error surface when a lazy route chunk fails to load`, **will fail, and it is right to fail** — Step 4a fixes it.

- [ ] **Step 4a: Re-point the #281 chunk-failure test at the behavior this branch deliberately changed**

That test navigates `/register` → `/login` with the login chunk aborted, then asserts the full-page `#boot-error` surface appears. `/register` renders first, so under this branch that is a *mid-session* failure and the reporter now shows the banner instead. The old expectation asserts exactly the behavior the design set out to remove: replacing a working page with "The app could not start", which is false. It is the test that must change, not the code.

Keep both branches covered by splitting them. In `frontend/e2e/boot-without-dictionary.spec.ts`, replace that whole third test — its docblock and its body, from the `/**` above `test('reveals the boot error surface when a lazy route chunk fails to load'` through that test's closing `});` — with:

```ts
/**
 * The dictionary fetch is bounded and non-fatal, but bootstrap isn't the only
 * thing that can leave the outlet empty: every route in app.routes.ts is
 * `loadComponent`/`loadChildren`, so a *lazy route chunk* that fails to load
 * produces the identical permanent blank page — same trigger as #280 (Brave
 * resume-reload with main.js served from cache but the route chunk evicted),
 * just past a different fetch. app.config.ts's `withNavigationErrorHandler`
 * is the fix; this proves it fires and that `#boot-error` genuinely becomes
 * visible.
 *
 * The load is direct rather than an in-app hop, and that is the whole point
 * since #285: the full-page surface is now only for a failure with nothing
 * on screen yet. A chunk that fails *after* a page has rendered shows the
 * retry banner and keeps the page — covered by navigation-watchdog.spec.ts,
 * because replacing a working page with "The app could not start" was itself
 * a defect.
 *
 * Scope, deliberately: this covers a chunk that FAILS, not one that STALLS.
 * A hung `import()` never rejects, so the router raises no NavigationError
 * and this handler never runs — that is what the watchdogs are for (#282 at
 * boot, #285 mid-session).
 *
 * Chunk filenames are content-hashed and change on every build, so the exact
 * URL is discovered live rather than hardcoded: load the register screen (its
 * own chunk, loaded up front) and follow its in-app link to /login, capturing
 * the one new script request that client-side navigation triggers. Doing this
 * as an SPA navigation rather than two page.goto() calls is what isolates the
 * login chunk from the shared initial bundle — a full reload would refetch
 * everything and defeat the isolation. That discovered URL is then aborted on
 * a fresh page (a fresh module graph, so the browser can't just serve the
 * chunk from the page that already fetched it).
 */
test('reveals the boot error surface when the first route chunk fails to load', async ({
  page,
  context,
}) => {
  await page.goto('/register');
  await page.waitForLoadState('networkidle');

  // waitForRequest only ever resolves on a request made after it is registered,
  // and networkidle above means every initial chunk is already in. So the first
  // chunk request it sees is necessarily the one the click triggers — no need to
  // track which URLs were already fetched. (This holds only while no route
  // preloading strategy is configured; app.config.ts uses none.)
  const [chunkRequest] = await Promise.all([
    page.waitForRequest((request) => /\/chunk-[^/?]+\.js/.test(request.url())),
    page.getByRole('link', { name: 'Already have an account?' }).click(),
  ]);
  const loginChunkUrl = chunkRequest.url();

  const brokenPage = await context.newPage();
  await brokenPage.route(loginChunkUrl, (route) => route.abort('failed'));

  // Straight to /login: the chunk fails before any route has rendered, so
  // there is no page worth keeping and the static surface is the only thing
  // that can carry a message.
  await brokenPage.goto('/login');

  await expect(brokenPage.locator('#boot-error')).toBeVisible({ timeout: 15_000 });
  expect((await brokenPage.locator('body').innerText()).trim()).not.toBe('');

  await brokenPage.close();
});
```

Then add this second test to `frontend/e2e/navigation-watchdog.spec.ts`, so the mid-session branch the old test used to cover keeps its coverage:

```ts
test('shows the retry banner when an in-app navigation fails outright', async ({
  page,
  context,
}) => {
  test.setTimeout(60_000);

  await page.goto('/register');
  await page.waitForLoadState('networkidle');

  const [chunkRequest] = await Promise.all([
    page.waitForRequest((request) => /\/chunk-[^/?]+\.js/.test(request.url())),
    page.getByRole('link', { name: 'Already have an account?' }).click(),
  ]);
  const loginChunkUrl = chunkRequest.url();

  const brokenPage = await context.newPage();
  await brokenPage.goto('/register');
  await brokenPage.waitForLoadState('networkidle');

  // A hard failure, not a stall: NavigationError fires immediately, so this
  // needs no deadline. Before #285 it revealed the full-page surface and threw
  // away a working page; now the reporter routes it to the banner.
  await brokenPage.route(loginChunkUrl, (route) => route.abort('failed'));
  await brokenPage.getByRole('link', { name: 'Already have an account?' }).click();

  await expect(brokenPage.locator('.banner')).toContainText('That page did not load.', {
    timeout: 15_000,
  });
  await expect(brokenPage.locator('#boot-error')).toBeHidden();

  await brokenPage.close();
});
```

Re-run both files. Expected: `boot-without-dictionary.spec.ts` 3/3 and `navigation-watchdog.spec.ts` 2/2.

- [ ] **Step 5: Commit**

```bash
git add frontend/e2e/navigation-watchdog.spec.ts frontend/e2e/boot-without-dictionary.spec.ts
git commit -m "fix(#285): prove the stalled navigation reaches the banner"
```

---

### Task 6: Full verification and PR

**Files:** none changed (verification only, plus the PR).

**Interfaces:**
- Consumes: everything from Tasks 1–5.
- Produces: the merge-ready PR.

- [ ] **Step 1: Full frontend gate and build**

From `frontend/`: `npm run check` then `npm run build`
Expected: both clean; the initial bundle stays within the 500 kB budget (it was 341.54 kB before this branch).

- [ ] **Step 2: Full Playwright suite**

From the repo root: `docker compose up -d` (skip if already up).
From `frontend/`: `npm run e2e` (allow 20 minutes)
Expected: all pass. It was 47 tests before this branch and gains 2 (both in navigation-watchdog.spec.ts); boot-without-dictionary.spec.ts keeps its 3.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin fix/285-navigation-watchdog
```

Open a PR against `develop` titled `fix(#285): a navigation watchdog for stalls the router cannot report`. The body must contain `Closes #285` and cover: why a stall raises no router event so `withNavigationErrorHandler` cannot fire; why a watchdog over the event stream was chosen over bounding each `import()` (it also covers stalled guards and resolvers, and no future route can forget it); the 8000 ms deadline and why it is shorter than the boot watchdog's 15 s; the before/after-first-render split and that it also fixes a mid-session chunk *failure* wrongly showing "The app could not start"; that the banner is behind an `@if` because static chrome in `app.html` would disarm the #282 boot watchdog; and why retry reloads the document rather than re-navigating.

- [ ] **Step 4: Verify CI**

Get the run id for the PR head with `gh run list --branch fix/285-navigation-watchdog --limit 5`, then read the conclusion with `gh run view <id>`. Do not trust `gh run watch --exit-status` or `gh pr checks` exit codes — both are unreliable in this repo. Expected: all four jobs green.

Do NOT merge the PR and do NOT create any tag.
