// e2e/sidebar-toggle-desktop.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in the other desktop specs.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/** A tablet in landscape: wide enough that the sidebar is a permanent column,
 *  not the swipe-in drawer, so the manual hide/show control applies. */
const TABLET_LANDSCAPE = { width: 1024, height: 768 };

/** Own the list data rather than reading whatever the seeded account holds, so
 *  the spec renders the same on a fresh CI database (#96). The toggle does not
 *  depend on the content; an empty list is enough to reach the reader. */
async function stubEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: [], nextCursor: null } });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await stubEntries(page);
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

test('hides the sidebar, keeps it hidden across a reload, then shows it again', async ({
  page,
}) => {
  await page.setViewportSize(TABLET_LANDSCAPE);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  const body = page.locator('app-reader-shell > .body');
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar).toBeVisible();

  await page.getByRole('button', { name: 'Hide sidebar' }).click();
  await expect(body).toHaveClass(/sidebar-hidden/);
  await expect(sidebar).toBeHidden();

  await stubEntries(page);
  await page.reload();
  await expect(body).toHaveClass(/sidebar-hidden/);
  await expect(sidebar).toBeHidden();

  await page.getByRole('button', { name: 'Show sidebar' }).click();
  await expect(body).not.toHaveClass(/sidebar-hidden/);
  await expect(sidebar).toBeVisible();
});
