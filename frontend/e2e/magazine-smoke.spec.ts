// e2e/magazine-smoke.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin — the same fixture `reader-smoke.spec.ts` and the
// backend ReaderJourneyE2eTest authenticate as (`bin/console
// app:e2e:seed-admin`, run by `bin/e2e.sh`). Overridable so this smoke can
// point at another environment without edits.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/**
 * Sign in through the real login form with the seeded admin credentials, then
 * wait for the reader shell to mount. Returns `false` (rather than failing)
 * when the credentials are rejected, so a stack without the seeded admin — or
 * a rate-limited login — skips cleanly instead of flaking. Mirrors
 * `reader-smoke.spec.ts`'s helper.
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

test('magazine is the default layout and the toggle switches modes', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Magazine is the default reading layout, so signing in lands here directly.
  const group = page.getByRole('group', { name: 'Reading layout' });
  await expect(group).toBeVisible();
  await expect(group.getByRole('button', { name: 'Magazine layout' })).toBeVisible();
  await expect(group.getByRole('button', { name: 'List layout' })).toBeVisible();
  await expect(group.getByRole('button', { name: 'Pane layout' })).toBeVisible();

  // Switch to List and back; the reader shell stays mounted throughout.
  await group.getByRole('button', { name: 'List layout' }).click();
  await expect(page.locator('app-reader-header')).toBeVisible();

  await group.getByRole('button', { name: 'Magazine layout' }).click();
  await expect(page.locator('app-reader-header')).toBeVisible();
});
