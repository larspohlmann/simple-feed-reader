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

test('shows the retry banner when an in-app navigation fails outright', async ({
  page,
  context,
}) => {
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
