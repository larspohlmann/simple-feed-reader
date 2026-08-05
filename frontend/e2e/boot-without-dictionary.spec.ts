// e2e/boot-without-dictionary.spec.ts
import { test, expect, Page } from '@playwright/test';

/**
 * #280: a mobile browser discards the backgrounded tab and resume-reloads on a
 * radio that is still reconnecting. Boot used to gate on the dictionary fetch,
 * so a failed or stalled request left a permanently blank page. The app must
 * now render — in the bundled English fallback — in both failure modes.
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
