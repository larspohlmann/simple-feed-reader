# Article Slide-Out on Every Close Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the single-pane article closes, it slides out to the right, whatever closed it: the browser or phone back button, or the toolbar back arrow on a wide screen.

**Architecture:** The article overlay already slides in with the `article-enter` keyframe on `.article-overlay` (`reader-shell.component.scss`). Angular 20.3's `animate.leave` puts a class on an element when its `@if` drops it, and keeps the DOM until that class's CSS animation ends. So `animate.leave="article-leave"` on both `.article-overlay` wrappers, plus a mirrored `article-leave` keyframe, covers every close path in one place. The leaving view is detached, so the article stays frozen on screen while it slides. When no animation is computed (reduced motion → `animation: none`, or jsdom), Angular removes the element at once.

**Tech Stack:** Angular 20.3 standalone (`animate.leave`), SCSS, Playwright e2e, Jest.

**Spec:** GitHub issue #1847.

## Global Constraints

- One mechanism: `animate.leave` on the shell's `.article-overlay` wrappers. No router listeners, no `popstate` handling, no new service.
- `ArticleGestures` stays as it is. Its swipe/toolbar slide on phones still moves the `.frame`. After that, the overlay's own leave plays off-screen, which is invisible and harmless because the leaving overlay has `pointer-events: none`.
- The split pane (`.main.split`) is untouched: it has no overlay to slide.
- Reduced motion: `.article-overlay.article-leave { animation: none; }` in the existing `prefers-reduced-motion` block, so the close is instant.
- Duration and easing match the enter: `0.22s`. The leave uses `ease-in`, the mirror of the enter's `ease-out`.
- No hex colours or ad-hoc `px`; styles stay in the sibling `.scss`.
- Commits: `feat(#1847): summary`. No new comments unless they clear the CLAUDE.md bar.

---

### Task 1: The overlay slides out on every close

**Files:**
- Create: `frontend/e2e/article-slide-out.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html` (the two `<div class="article-overlay">` in the single-pane `@else` branch, ~lines 118 and 126)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.scss` (next to `.article-overlay`, `@keyframes article-enter` and its reduced-motion block, ~lines 90–123)

**Interfaces:**
- Produces: the CSS class `article-leave`, which Angular adds to a leaving `.article-overlay`. Its computed `animation-name` ends in `article-leave` (emulated encapsulation prefixes keyframe names, so match with `endsWith`).

- [ ] **Step 1: Write the failing e2e**

Create `frontend/e2e/article-slide-out.spec.ts`. A `MutationObserver` records what each leaving overlay looked like. It is installed before the close, because polling with `toHaveCount` could miss a 220 ms window.

```ts
import { test, expect, Page } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson } from './support/reader';

const PHONE = { width: 375, height: 667 };
const DESKTOP = { width: 1280, height: 900 };

const ENTRY = entryWire({
  id: 1,
  title: 'Entry number 1',
  url: 'https://example.invalid/1',
  summary: 'A summary long enough to give the row some height.',
  excerpt: 'A summary long enough to give the row some height.',
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
});

async function stubEntries(page: Page): Promise<void> {
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ status: 200, json: readerFailedJson('unextractable') }),
  );
  await page.route('**/api/entries/1', (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    return route.fulfill({ status: 200, json: entryDetailJson(ENTRY, '<p>Body.</p>') });
  });
  await page.route('**/api/entries/*/state', (route) =>
    route.fulfill({
      status: 200,
      json: { state: { entryId: 1, isHidden: false, isFavorite: false, isKept: false, hiddenAt: null } },
    }),
  );
  await page.route('**/api/entries*', (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    return route.fulfill({ status: 200, json: { entries: [ENTRY], nextCursor: null } });
  });
}

/** Opens the article and waits out its slide-in, so the next animation seen is the leave. */
async function openArticle(page: Page): Promise<void> {
  await stubEntries(page);
  await page.reload();
  await page.getByText('Entry number 1', { exact: false }).first().click();
  await expect(page.locator('.article-overlay app-reader-view')).toBeVisible();
  await page.waitForTimeout(400);
}

/** Records the animation each `.article-overlay` is given as it leaves. */
async function watchLeaves(page: Page): Promise<void> {
  await page.evaluate(() => {
    const seen: string[] = [];
    (window as unknown as { leaveAnimations: string[] }).leaveAnimations = seen;
    new MutationObserver((records) => {
      for (const record of records) {
        const target = record.target as HTMLElement;
        if (target.matches('.article-overlay.article-leave')) {
          seen.push(getComputedStyle(target).animationName);
        }
      }
    }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
  });
}

async function leaveAnimations(page: Page): Promise<string[]> {
  return page.evaluate(() => (window as unknown as { leaveAnimations: string[] }).leaveAnimations);
}

test.describe('Article slide-out (#1847)', () => {
  test.describe('wide single-pane', () => {
    test.use({ viewport: DESKTOP });

    test('the toolbar back arrow slides the article out', async ({ page }) => {
      test.skip(!(await signInWithLayout(page, 'magazine')), 'seeded admin login unavailable');
      await openArticle(page);
      await watchLeaves(page);

      await page.locator('app-reader-view .bar .close').click();

      await expect(page.locator('.article-overlay')).toHaveCount(0);
      const animations = await leaveAnimations(page);
      expect(animations).toHaveLength(1);
      expect(animations[0]).toMatch(/article-leave$/);
    });
  });

  test.describe('phone', () => {
    test.use({ viewport: PHONE });

    test('the browser back button slides the article out', async ({ page }) => {
      test.skip(!(await signInWithLayout(page, 'magazine')), 'seeded admin login unavailable');
      await openArticle(page);
      await watchLeaves(page);

      await page.goBack();

      await expect(page.locator('.article-overlay')).toHaveCount(0);
      const animations = await leaveAnimations(page);
      expect(animations).toHaveLength(1);
      expect(animations[0]).toMatch(/article-leave$/);
    });

    test('a reduced-motion close leaves at once', async ({ page }) => {
      await page.emulateMedia({ reducedMotion: 'reduce' });
      test.skip(!(await signInWithLayout(page, 'magazine')), 'seeded admin login unavailable');
      await openArticle(page);
      await watchLeaves(page);

      await page.goBack();

      await expect(page.locator('.article-overlay')).toHaveCount(0);
      expect(await leaveAnimations(page)).toEqual(['none']);
    });
  });
});
```

Notes for the implementer:
- `entryWire`, `entryDetailJson` and `readerFailedJson` already exist in `frontend/e2e/support/reader.ts`. Check their signatures there before running, and adapt the field names if any differ.
- The reduced-motion test expects `['none']` because Angular still adds the class for one frame. It sees no animation and removes the element at once. If Angular skips the class entirely when no animation is computed, the right expectation is `[]`. Read it from the real run, and keep the assertion as "no `article-leave` animation ran".

- [ ] **Step 2: Run it to make sure it fails**

The Docker stack must be up, run from this checkout (`backend/bin/e2e-preflight.sh` guards this). Then run:

```bash
cd frontend && npx playwright test e2e/article-slide-out.spec.ts
```

Expected: the two slide tests FAIL with `expect(received).toHaveLength(1)` / received `[]`, because no class is ever added. The reduced-motion test also fails (`[]` ≠ `['none']`), or passes under the `[]` variant from the note above.

- [ ] **Step 3: Add `animate.leave` to both overlays**

In `frontend/src/app/reader/shell/reader-shell.component.html`, inside the single-pane `@else` branch:

```html
      @if (openEntry()) {
        <div class="article-overlay" animate.leave="article-leave">
```

```html
      @if (linkTarget()) {
        <div class="article-overlay" animate.leave="article-leave">
```

- [ ] **Step 4: Add the leave keyframe and its reduced-motion opt-out**

In `frontend/src/app/reader/shell/reader-shell.component.scss`, after the `@media (width < bp.$bp-lg)` block for `.article-overlay`:

```scss
.article-overlay.article-leave {
  pointer-events: none;
  animation: article-leave 0.22s ease-in forwards;
}
```

After `@keyframes article-enter { … }`:

```scss
@keyframes article-leave {
  from {
    transform: translateX(0);
  }

  to {
    transform: translateX(100%);
  }
}
```

Extend the existing reduced-motion block so it reads:

```scss
@media (prefers-reduced-motion: reduce) {
  .article-overlay,
  .article-overlay.article-leave {
    animation: none;
  }
}
```

`.article-overlay.article-leave` is more specific than `.article-overlay`, which is why the reduced-motion rule must name it.

- [ ] **Step 5: Run the e2e to make sure it passes**

```bash
cd frontend && npx playwright test e2e/article-slide-out.spec.ts e2e/article-back-desktop.spec.ts
```

Expected: all PASS. `article-back-desktop.spec.ts` still passes because its `toBeHidden()` waits out the 220 ms.

- [ ] **Step 6: Run the frontend gate and the build**

```bash
docker compose exec -T frontend npm test -- src/app/reader
cd frontend && npm run check && npx ng build
```

Expected: all green. The existing `reader-shell.component.spec.ts` and `reader-view.component.spec.ts` tests still pass: jsdom computes no animation, so Angular removes the overlay at once there. `ng build` catches template errors that `npm run check` misses.

- [ ] **Step 7: Commit**

```bash
git add frontend/e2e/article-slide-out.spec.ts frontend/src/app/reader/shell/reader-shell.component.html frontend/src/app/reader/shell/reader-shell.component.scss
git commit -m "feat(#1847): the article slides out on every close, not only the phone toolbar's back button"
```

The commit body records the design rationale: one `animate.leave` on the overlay instead of hooking each close path, and the off-screen tail on phones made harmless by `pointer-events: none`.

### Task 2: Live check on :4200

**Files:** none.

- [ ] **Step 1: Make sure the dev container serves the new code**

Load `http://localhost:4200` in the built-in browser. Confirm the served chunk contains `article-leave`: search the `main`/reader chunk in the network panel, or hard-reload.

- [ ] **Step 2: Check each close path by eye**

Each of these should slide out to the right, with no flash of a blank or empty article:
- wide magazine layout, toolbar back arrow
- wide magazine layout, browser back
- phone width (Mobile viewport preset, which also avoids the bot-blocked UA): browser back
- phone width: toolbar back arrow (the existing slide, with no visible second animation)
- phone width: rightward swipe and pull-past-end (the pull now also slides right after its spinner)

The split-pane layout should close with no overlay animation, as before.

- [ ] **Step 3: Reset the viewport** to the `desktop` preset.
