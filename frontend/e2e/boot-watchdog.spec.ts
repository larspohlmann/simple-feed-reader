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
 * The dev server behind `npm run e2e` serves shared code as chunk-*.js
 * STATIC imports of main.js, so stalling that pattern hangs the whole
 * module graph, not only a lazy route load. That still exercises exactly
 * what the watchdog exists for — a boot that dies silently, with no event
 * for any handler — and it is why the gotos below use waitUntil: 'commit':
 * a hung module graph also suppresses DOMContentLoaded and load, so any
 * later waitUntil would time out before the assertions get to run. (In the
 * production build only lazy route chunks carry the chunk-*.js name.)
 *
 * The spec owns all the data it asserts on: no account, no seeded state.
 */
const WATCHDOG_DEADLINE_MS = 15_000;

test.describe('boot watchdog', () => {
  // Every test here spans at least one full 15 s deadline.
  test.setTimeout(60_000);

  test('reveals the boot error surface when the boot stalls forever', async ({ page }) => {
    // Neither fulfilled nor aborted: chunk requests hang, like a dead radio.
    await page.route(/\/chunk-[^/?]+\.js/, () => undefined);

    await page.goto('/login', { waitUntil: 'commit' });

    await expect(page.locator('#boot-error')).toBeVisible({
      timeout: WATCHDOG_DEADLINE_MS + 5_000,
    });
  });

  test('re-hides the surface when a stalled boot eventually recovers', async ({ page }) => {
    // Only the FIRST chunk request stalls, resolving ~3 s AFTER the
    // deadline: the surface must appear at 15 s (false positive) and
    // disappear again when the render lands. One hung module already blocks
    // the whole graph; a blanket delay would chain per import level and
    // overrun the test timeout.
    let firstChunkRequestSeen = false;
    await page.route(/\/chunk-[^/?]+\.js/, async (route) => {
      if (firstChunkRequestSeen) {
        await route.continue();
        return;
      }
      firstChunkRequestSeen = true;
      await new Promise((resolve) => setTimeout(resolve, WATCHDOG_DEADLINE_MS + 3_000));
      await route.continue();
    });

    await page.goto('/login', { waitUntil: 'commit' });

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

  /**
   * #282's actual reproduction, and the one the other tests cannot reach: the
   * main graph loads fine, Angular renders <router-outlet>, and only the LAZY
   * route chunk stalls. Nothing rejects, so no handler fires; only a watchdog
   * whose cancel condition means "route content", not "any node", survives to
   * reveal the surface.
   *
   * Chunk filenames are content-hashed, so the URL is discovered live: load
   * /register (its own chunk, already in) and follow its in-app link to /login,
   * capturing the one new chunk request the client-side navigation triggers.
   * That URL is then stalled on a fresh page — a fresh module graph, so the
   * browser cannot serve it from the page that already fetched it.
   */
  test('reveals the surface when only the lazy route chunk stalls', async ({ page, context }) => {
    await page.goto('/register');
    await page.waitForLoadState('networkidle');

    const [chunkRequest] = await Promise.all([
      page.waitForRequest((request) => /\/chunk-[^/?]+\.js/.test(request.url())),
      page.getByRole('link', { name: 'Already have an account?' }).click(),
    ]);
    const loginChunkUrl = chunkRequest.url();

    const stalledPage = await context.newPage();
    await stalledPage.route(loginChunkUrl, () => undefined);

    await stalledPage.goto('/login', { waitUntil: 'commit' });

    await expect(stalledPage.locator('#boot-error')).toBeVisible({
      timeout: WATCHDOG_DEADLINE_MS + 5_000,
    });

    await stalledPage.close();
  });
});
