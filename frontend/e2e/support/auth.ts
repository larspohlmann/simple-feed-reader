import { expect, Page } from '@playwright/test';

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

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
  await page.route('**/api/setup/status', async (route) => {
    await route.fulfill({
      json: { needsSetup: false, mailEnabled: true, passkeySignInAvailable: false },
    });
  });
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
  await expect(sidebar.or(page.getByRole('alert'))).toBeVisible();
  return sidebar.isVisible();
}
