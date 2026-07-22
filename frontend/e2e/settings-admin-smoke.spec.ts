// e2e/settings-admin-smoke.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin — the same fixture the backend ReaderJourneyE2eTest
// authenticates as (`bin/console app:e2e:seed-admin`, run by `bin/e2e.sh`).
// Overridable so this smoke can point at another environment without edits.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/**
 * Sign in through the real login form — the same selectors and cross-origin
 * flow `auth-smoke.spec.ts` drives — with the seeded admin credentials, then
 * wait for the reader shell to mount. Returns `false` (rather than failing)
 * when the credentials are rejected, so a stack without the seeded admin — or
 * a rate-limited login — skips cleanly instead of flaking. Mirrors the backend
 * real-feed e2e convention: an unavailable precondition is skipped, not failed.
 */
async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();

  // Success mounts the reader sidebar; failure surfaces the login error alert.
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

test('settings page renders and the tag dialog opens; admin queue loads', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Open Settings from the account menu.
  await page.getByRole('button', { name: /@/ }).click(); // the email button
  await page.getByRole('menuitem', { name: 'Settings' }).click();
  await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Feeds' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Tags' })).toBeVisible();

  // The New-tag dialog opens and closes (no network write).
  await page.getByRole('button', { name: 'New tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'New tag' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();

  // The admin queue renders (the seeded account is an admin).
  await page.goto('/admin/users');
  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Filter by status' })).toBeVisible();
});
