// e2e/boot-without-dictionary.spec.ts
import { test, expect, Page } from '@playwright/test';

/**
 * #280: a mobile browser discards the backgrounded tab and resume-reloads on a
 * radio that is still reconnecting. Boot used to gate on the dictionary fetch,
 * so a failed or stalled request left a permanently blank page.
 *
 * The two tests below guard two different layers, and both are needed:
 *
 * - "request fails" guards the bundled English dictionary (`transloco-loader.ts`)
 *   plus Transloco's own `fallbackLang` retry/catch. A hard HTTP error is an
 *   error Transloco already recovers from on its own; delete the bundling and
 *   this test still catches it, because `en.json` would then go over HTTP,
 *   hit the same route stub, and fail the same way.
 * - "request stalls" guards `preloadInitialLanguage` (`boot-language.ts`) and
 *   its 3000 ms bound. A request that neither resolves nor rejects gives
 *   Transloco's `catchError` nothing to fire on, so only the explicit timeout
 *   rescues it — this is the one that reproduces the original #280 hang.
 *
 * The device persisted German, so boot genuinely needs the network-loaded
 * dictionary; English alone would never issue the request (it is bundled).
 * The spec owns all the data it asserts on: no account, no seeded state.
 */
const ENGLISH_SUBTITLE = 'Welcome back to your reader.';
const GERMAN_SUBTITLE = 'Willkommen zurück bei deinem Reader.';

async function bootAsGermanDevice(page: Page) {
  await page.addInitScript(() => localStorage.setItem('sfr.lang', 'de'));
}

/** The app rendered, and it rendered via the bundled fallback. */
async function expectFallbackRender(page: Page) {
  await expect(page.getByText(ENGLISH_SUBTITLE)).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(GERMAN_SUBTITLE)).toHaveCount(0);
}

test('renders the login screen when the dictionary request fails', async ({ page }) => {
  await bootAsGermanDevice(page);
  await page.route('**/i18n/*.json*', (route) => route.abort('failed'));

  await page.goto('/login');

  await expectFallbackRender(page);
});

test('renders the login screen when the dictionary request stalls', async ({ page }) => {
  await bootAsGermanDevice(page);
  // Neither fulfilled nor aborted: the request hangs, like a dead radio.
  await page.route('**/i18n/*.json*', () => undefined);

  await page.goto('/login');

  await expectFallbackRender(page);
});

/**
 * The dictionary fetch is bounded and non-fatal, but bootstrap isn't the only
 * thing that can leave the outlet empty: every route in app.routes.ts is
 * `loadComponent`/`loadChildren`, so a failed or stalled *lazy route chunk*
 * produces the identical permanent blank page — same trigger as #280 (Brave
 * resume-reload with main.js served from cache but the route chunk stalled on
 * a reconnecting radio), just past a different fetch. app.config.ts's
 * `withNavigationErrorHandler` is the fix; this proves it fires and that
 * `#boot-error` genuinely becomes visible, which had no coverage before.
 *
 * Chunk filenames are content-hashed and change on every build, so the exact
 * URL is discovered live rather than hardcoded: load the register screen (its
 * own chunk, loaded up front) and follow its in-app link to /login, capturing
 * the one new script request that client-side navigation triggers. Doing this
 * as an SPA navigation rather than two page.goto() calls is what isolates the
 * login chunk from the shared initial bundle — a full reload would refetch
 * everything and defeat the isolation. That discovered URL is then aborted on
 * a fresh page (a fresh module graph, so the browser can't just serve the
 * chunk from the page that already fetched it) before repeating the same
 * navigation, so the assertions below exercise a genuinely broken fetch, not
 * the main bundle.
 */
test('reveals the boot error surface when a lazy route chunk fails to load', async ({
  page,
  context,
}) => {
  const requestedBeforeNavigation = new Set<string>();
  const trackRequestedUrl = (request: { url(): string }) =>
    requestedBeforeNavigation.add(request.url());
  page.on('request', trackRequestedUrl);
  await page.goto('/register');
  await page.waitForLoadState('networkidle');
  // Detach rather than merely clear: a listener left attached would still add
  // the chunk's own URL to the set the instant it fires, racing the predicate
  // below and making every request look like one already seen "before".
  page.off('request', trackRequestedUrl);

  const [chunkRequest] = await Promise.all([
    page.waitForRequest(
      (request) =>
        /\/chunk-[^/?]+\.js/.test(request.url()) && !requestedBeforeNavigation.has(request.url()),
    ),
    page.getByRole('link', { name: 'Already have an account?' }).click(),
  ]);
  const loginChunkUrl = chunkRequest.url();

  const brokenPage = await context.newPage();
  const consoleErrors: string[] = [];
  brokenPage.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  await brokenPage.route(loginChunkUrl, (route) => route.abort('failed'));

  await brokenPage.goto('/register');
  await brokenPage.getByRole('link', { name: 'Already have an account?' }).click();

  await expect(brokenPage.locator('#boot-error')).toBeVisible({ timeout: 15_000 });
  expect((await brokenPage.locator('body').innerText()).trim()).not.toBe('');
  expect(consoleErrors.length).toBeGreaterThan(0);

  await brokenPage.close();
});
