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
