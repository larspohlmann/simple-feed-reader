// e2e/reader-smoke.spec.ts
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

test('reader shell renders after signing in, and Add feed opens a dialog', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // --- The reader shell renders: header + sidebar. ---
  // The header is proven by its own controls (Add feed + the reading-layout
  // toggle), which live only in the reader header.
  await expect(page.locator('app-reader-header')).toBeVisible();
  await expect(page.getByRole('group', { name: 'Reading layout' })).toBeVisible();

  // The sidebar's always-present "All items" nav is the stable anchor — it does
  // not depend on any subscription existing.
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar.getByRole('link', { name: 'All items' })).toBeVisible();

  // --- The Add-feed dialog opens with a URL field. ---
  // No live feed is fetched here: this asserts only that the dialog surface
  // renders, so the smoke never depends on a reachable remote feed.
  await page.getByRole('button', { name: 'Add feed' }).click();

  const dialog = page.getByRole('dialog', { name: 'Add a feed' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('textbox', { name: 'Feed or site URL' })).toBeVisible();

  // Closing the dialog dismisses it — round-trips open/close with no network.
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();
});
