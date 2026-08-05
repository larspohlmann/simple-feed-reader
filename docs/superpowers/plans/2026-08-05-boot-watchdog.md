# Boot Watchdog Implementation Plan (#282)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A pre-bootstrap watchdog in `index.html` that reveals the static `#boot-error` surface when nothing has rendered 15 s after page parse, so a lazy route chunk that stalls during boot no longer leaves a permanent blank page.

**Architecture:** A ~15-line inline `<script>` after the `#boot-error` div — it runs before any bundle loads, so it is immune to every failure mode the bundles have. A `setTimeout(15000)` reveals the surface; a `MutationObserver` on `<app-root>`'s `childList` cancels the timer on first render, re-hides the surface if the render arrived late, and disconnects. The #281 plumbing (`main.ts` catch, `withNavigationErrorHandler`, `boot-error-surface.ts`) stays: it gives immediate errors on rejections, including in-app ones the watchdog cannot see after it disconnects.

**Tech Stack:** Plain ES5-style inline JS in `index.html` (matching the existing theme script), Playwright e2e.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-05-boot-watchdog-design.md`.
- Deadline is exactly **15000 ms**, named as a constant at the top of the script, with a comment recording the trade-off (false-positive flash vs. time-to-help). In app code it exists ONLY in `index.html` — no copy anywhere under `src/`. The e2e spec names the same number for its own timeouts; that is expected, not a violation.
- Do NOT modify: `boot-error-surface.ts`, `boot-language.ts`, `app.routes.ts`, `app.config.ts`, `main.ts`, `deploy/strato/.htaccess`, anything in `backend/`.
- The three existing tests in `frontend/e2e/boot-without-dictionary.spec.ts` must stay green, unchanged.
- Inline script style matches the existing theme script in `index.html`: `var`, `function () {}`, IIFE — no arrow functions, no `let`/`const` (consistency with the file, not a browser requirement).
- Frontend lint gate is `npm run check` from `frontend/` (ESLint + Prettier 100-col + Stylelint + Jest). E2e specs are linted too.
- Playwright e2e runs against the Docker stack via `npm run e2e` from `frontend/` (stack must be up: `docker compose up -d` from the repo root).
- Never use `git stash`, `git checkout -- <file>`, or `git reset` — the checkout is shared with concurrent sessions. To temporarily revert a file for a falsifiability check, copy it to the scratchpad, edit in place, then copy back and verify with `git diff --stat -- <file>` that the restore is byte-exact.
- Commit messages follow the branch convention: `fix(#282): <imperative summary>`.

---

### Task 1: The watchdog and its e2e proof

**Files:**
- Modify: `frontend/src/index.html` (the `#boot-error` comment and a new script after the div)
- Test: `frontend/e2e/boot-watchdog.spec.ts` (create)

**Interfaces:**
- Consumes: the existing `#boot-error` div and `<app-root>` in `index.html`; the login route's lazy chunk naming scheme `chunk-*.js` (esbuild names lazy chunks `chunk-<hash>.js`; initial files are `main-*.js` etc., so the route pattern below stalls only lazy chunks).
- Produces: nothing later tasks rely on beyond the committed behavior.

- [ ] **Step 1: Write the failing e2e spec**

Create `frontend/e2e/boot-watchdog.spec.ts` with exactly this content:

```ts
// e2e/boot-watchdog.spec.ts
import { test, expect } from '@playwright/test';

/**
 * #282: a lazy route chunk that STALLS during boot raises no event at all —
 * a hung import() never rejects, so the #281 plumbing (bootstrap catch,
 * withNavigationErrorHandler) never fires and <app-root> stays empty forever.
 *
 * The watchdog under test is the inline script in index.html: a 15 s timer
 * set before any bundle loads reveals #boot-error; a MutationObserver on
 * <app-root> cancels it on first render and re-hides the surface if the
 * render arrives late. Time is therefore the subject of every test here,
 * which is why the suite waits out real deadlines instead of polling for
 * app-driven events — there are none in the failure mode.
 *
 * The spec owns all the data it asserts on: no account, no seeded state.
 */
const WATCHDOG_DEADLINE_MS = 15_000;

test.describe('boot watchdog', () => {
  // Every test here spans at least one full 15 s deadline.
  test.setTimeout(60_000);

  test('reveals the boot error surface when a lazy chunk stalls forever', async ({ page }) => {
    // Neither fulfilled nor aborted: the chunk request hangs, like a dead
    // radio. Only lazy chunks match chunk-*.js; the initial bundle loads.
    await page.route(/\/chunk-[^/?]+\.js/, () => undefined);

    await page.goto('/login');

    await expect(page.locator('#boot-error')).toBeVisible({
      timeout: WATCHDOG_DEADLINE_MS + 5_000,
    });
  });

  test('re-hides the surface when a stalled chunk eventually loads', async ({ page }) => {
    // The chunk resolves ~3 s AFTER the deadline: the surface must appear
    // at 15 s (false positive) and disappear again when the render lands.
    await page.route(/\/chunk-[^/?]+\.js/, async (route) => {
      await new Promise((resolve) => setTimeout(resolve, WATCHDOG_DEADLINE_MS + 3_000));
      await route.continue();
    });

    await page.goto('/login');

    await expect(page.locator('#boot-error')).toBeVisible({
      timeout: WATCHDOG_DEADLINE_MS + 5_000,
    });
    await expect(page.getByText('Welcome back to your reader.')).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.locator('#boot-error')).toBeHidden();
  });

  test('never fires on a healthy boot', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByText('Welcome back to your reader.')).toBeVisible();

    // The only wrong outcome is the surface appearing at the 15 s mark, so
    // the test must outwait the deadline; no app event marks "timer fired".
    await page.waitForTimeout(WATCHDOG_DEADLINE_MS + 2_000);

    await expect(page.locator('#boot-error')).toBeHidden();
  });
});
```

If ESLint flags `page.waitForTimeout` (a playwright lint rule may ban it), keep the call and add a one-line disable comment stating that time itself is the subject under test here.

- [ ] **Step 2: Run the spec to verify it fails for the right reason**

From the repo root: `docker compose up -d` (skip if already up).
From `frontend/`: `npm run e2e -- boot-watchdog.spec.ts`

Expected: tests 1 and 2 FAIL with a `toBeVisible` timeout on `#boot-error` (nothing reveals it today); test 3 PASSES (the surface never appears with or without the watchdog on a healthy boot — its value is guarding the observer once the watchdog exists). If test 1 or 2 fails for any other reason (e.g. the route pattern also stalled the initial bundle and `page.goto` itself failed), fix the spec before proceeding.

- [ ] **Step 3: Implement the watchdog in `index.html`**

In `frontend/src/index.html`, replace the comment above the `#boot-error` div (it currently begins `Revealed by main.ts only when bootstrapApplication rejects.`) and add the script after the div, so the end of `<body>` reads exactly:

```html
    <!--
      Revealed when boot fails: by main.ts when bootstrapApplication rejects,
      by withNavigationErrorHandler when a lazy chunk fails, and by the
      watchdog below when nothing renders at all (#280, #282). Static and
      style-inlined on purpose: at that point no bundle code is trustworthy,
      so this surface must depend on nothing but the document itself.
      Both languages, because no dictionary loaded either.
    -->
    <div id="boot-error" hidden style="padding: 2rem; text-align: center; font-family: system-ui">
      <p>The app could not start. / Die App konnte nicht gestartet werden.</p>
      <button onclick="location.reload()">Reload / Neu laden</button>
    </div>
    <!--
      Boot watchdog (#282). A lazy chunk that STALLS raises no event — a hung
      import() never rejects — so no handler in the bundle can ever run. This
      is symptom-level on purpose: it covers every silent boot stall (chunk,
      guard, resolver, future initializer) with no app-side hook.
    -->
    <script>
      (function () {
        // 15 s is long past any working load on a slow connection, yet still
        // reaches the user staring at a genuinely dead boot. A false positive
        // costs only a brief flash: the observer below re-hides the surface
        // the moment a late render arrives.
        var BOOT_DEADLINE_MS = 15000;
        var surface = document.getElementById('boot-error');
        var timer = setTimeout(function () {
          console.error('Boot watchdog: nothing rendered within ' + BOOT_DEADLINE_MS + ' ms.');
          surface.removeAttribute('hidden');
        }, BOOT_DEADLINE_MS);
        new MutationObserver(function (mutations, observer) {
          for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].addedNodes.length === 0) continue;
            clearTimeout(timer);
            surface.setAttribute('hidden', '');
            observer.disconnect();
            return;
          }
        }).observe(document.querySelector('app-root'), { childList: true });
      })();
    </script>
  </body>
```

Nothing else in the file changes.

- [ ] **Step 4: Run the new spec to verify it passes**

From `frontend/`: `npm run e2e -- boot-watchdog.spec.ts`
Expected: all 3 PASS.

- [ ] **Step 5: Run the neighbouring boot tests to verify no regression**

From `frontend/`: `npm run e2e -- boot-without-dictionary.spec.ts`
Expected: all 3 PASS, unchanged.

- [ ] **Step 6: Run the frontend gate**

From `frontend/`: `npm run check`
Expected: clean (ESLint + Prettier + Stylelint + Jest all green).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/index.html frontend/e2e/boot-watchdog.spec.ts
git commit -m "fix(#282): reveal the boot error surface when nothing renders in time"
```

---

### Task 2: Full verification and PR

**Files:**
- None created or modified (verification only, plus the PR).

**Interfaces:**
- Consumes: the committed watchdog and spec from Task 1.
- Produces: the merged-ready PR.

- [ ] **Step 1: Frontend build**

From `frontend/`: `npm run build`
Expected: clean, initial bundle within the 500 kB budget.

- [ ] **Step 2: Full Playwright suite**

From the repo root: `docker compose up -d` (skip if already up).
From `frontend/`: `npm run e2e`
Expected: all tests pass. (Known context: the suite was 43/43 before this branch; it gains 3.)

- [ ] **Step 3: Falsifiability check on the watchdog**

Copy `frontend/src/index.html` to the scratchpad, delete the watchdog `<script>` block from the working-tree copy, run `npm run e2e -- boot-watchdog.spec.ts`, and confirm tests 1 and 2 FAIL. Copy the scratchpad file back and verify `git diff --stat -- frontend/src/index.html` reports no difference from HEAD. Never use git commands to revert.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin fix/282-boot-watchdog
```

Open a PR against `develop` titled `fix(#282): boot watchdog reveals the error surface when nothing renders`, body summarising: the stall gap #281 could not cover and why (a hung import() raises no event), the watchdog mechanism (15 s timer + MutationObserver cancel/re-hide), the decision to KEEP `withNavigationErrorHandler` (it alone covers in-app chunk failures after the observer disconnects — the issue comment's "deletable" claim was wrong), and the residual out-of-scope variant (an in-app stall after first render is still a silent dead click). Body must contain `Closes #282`.

- [ ] **Step 5: Verify CI**

Watch the PR's CI run; re-read the conclusion by run id (`gh run view <id>`), not via `gh run watch --exit-status` (it lies). Expected: all four jobs green.
