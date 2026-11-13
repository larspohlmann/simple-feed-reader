import { expect, Page } from '@playwright/test';
import type { ReadingLayout } from '../../src/app/reader/reading-layout.service';

// The seeded e2e admin (`bin/console app:e2e:seed-admin`, run by `bin/e2e.sh`);
// overridable so a spec can point at another environment without edits.
export const ADMIN_EMAIL = process.env['E2E_ADMIN_EMAIL'] ?? 'e2e-admin@example.com';
export const ADMIN_PASSWORD = process.env['E2E_ADMIN_PASSWORD'] ?? 'e2e-admin-password-123';

export const STUB_PROFILE = {
  id: 1,
  email: '',
  roles: [] as string[],
  preferences: {},
  catalog: { hasEntries: false },
};

/**
 * Put a token where the route guard looks, without a round trip.
 *
 * `authGuard` only asks `TokenStore.isAuthenticated()`, which is a presence
 * check on this one localStorage key — so a spec that stubs the API it needs
 * can reach a guarded route hermetically, with no Mailpit, no seeded admin and
 * no Docker. Specs that exercise the real login (auth-smoke, onboarding) must
 * keep doing so; this is for the ones testing what happens once you are in.
 */
export async function stubAuthToken(page: Page): Promise<void> {
  // Registered first, so it answers only what no other route does: the real backend 401s the stub
  // token, and the interceptor then signs the spec out mid-test, but only where CI is slow (#2016).
  await page.route('**/api/**', (route) => route.fulfill({ status: 404 }));
  await page.route('**/api/setup/status', async (route) => {
    await route.fulfill({
      json: { needsSetup: false, mailEnabled: true, passkeySignInAvailable: false },
    });
  });
  await page.route(/\/api\/me(?:[?#]|$)/, (route) => route.fulfill({ json: STUB_PROFILE }));
  await page.route('**/api/for-you/excluded-networks', (route) =>
    route.fulfill({ json: { excludedNetworks: [] } }),
  );
  await page.route('**/api/all-items/excluded-networks', (route) =>
    route.fulfill({ json: { excludedNetworks: [] } }),
  );
  // Carries a `userId` claim: without one, the reader holds its first list load for `/api/me`.
  await page.addInitScript(() =>
    localStorage.setItem('sfr.jwt', 'eyJhbGciOiJub25lIn0.eyJ1c2VySWQiOjF9.stub-signature'),
  );
}

/** Signs in as the seeded e2e admin for real; false when that login is unavailable,
 *  so a spec can skip rather than fail on a stack without the seeded account. */
export async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar.or(page.getByRole('alert'))).toBeVisible({ timeout: 15_000 });
  return sidebar.isVisible();
}

/** Values the app reads from localStorage at boot, set before every navigation. */
export async function presetLocalStorage(
  page: Page,
  values: Record<string, string>,
): Promise<void> {
  await page.addInitScript((entries) => {
    for (const [key, value] of Object.entries(entries)) localStorage.setItem(key, value);
  }, values);
}

/** Signs in with the reading layout pinned, so a spec takes the shell branch it
 *  measures instead of whatever the previous run left in localStorage. */
export async function signInWithLayout(page: Page, layout: ReadingLayout): Promise<boolean> {
  await presetLocalStorage(page, { 'sfr.layout': layout });
  return signInAsAdmin(page);
}
