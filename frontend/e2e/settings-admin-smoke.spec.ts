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

test('settings shell navigates sections; admin pages live inside it', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Open Settings from the account menu. On the desktop viewport the bare
  // /settings url forwards to the first section.
  await page.getByRole('button', { name: 'Account' }).click();
  await page.getByRole('menuitem', { name: 'Settings' }).click();
  await expect(page).toHaveURL(/\/settings\/tags$/);
  await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Tags' })).toBeVisible();

  // The rail navigates between sections.
  const nav = page.getByRole('navigation', { name: 'Settings' });
  await nav.getByRole('link', { name: 'Import & export' }).click();
  await expect(page).toHaveURL(/\/settings\/import$/);
  await expect(page.getByRole('heading', { name: 'Import & export' })).toBeVisible();

  // The New-tag dialog still opens from the tags section (no network write).
  await nav.getByRole('link', { name: 'Tags' }).click();
  await page.getByRole('button', { name: 'New tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'New tag' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();

  // Pre-#180 admin urls redirect into the shell.
  await page.goto('/admin/users');
  await expect(page).toHaveURL(/\/settings\/admin\/users$/);
  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Filter by status' })).toBeVisible();

  // The catalog renders read-only rows and its category dialog opens (no write).
  await page.goto('/settings/admin/catalog');
  await expect(page.getByTestId('add-category')).toBeVisible();
  await page.getByTestId('add-category').click();
  const categoryDialog = page.getByRole('dialog', { name: 'New category' });
  await expect(categoryDialog).toBeVisible();
  await categoryDialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(categoryDialog).toBeHidden();

  // The admin can open one account's detail page from the list.
  await page.goto('/settings/admin/users');
  const firstUser = page.locator('a[href^="/settings/admin/users/"]').first();
  await expect(firstUser).toBeVisible();
  await firstUser.click();
  await expect(page).toHaveURL(/\/settings\/admin\/users\/\d+$/);
  await expect(page.getByRole('heading', { name: 'Tags' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Feeds' })).toBeVisible();
});
