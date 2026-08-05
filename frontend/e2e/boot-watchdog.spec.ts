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
    // eslint-disable-next-line playwright/no-wait-for-timeout
    await page.waitForTimeout(WATCHDOG_DEADLINE_MS + 2_000);

    await expect(page.locator('#boot-error')).toBeHidden();
  });
});
