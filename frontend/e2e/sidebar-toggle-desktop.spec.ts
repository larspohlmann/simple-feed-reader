import { test, expect, Page } from '@playwright/test';
import { signInAsAdmin } from './support/auth';

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

async function signInWithEmptyList(page: Page): Promise<boolean> {
  await stubEntries(page);
  return signInAsAdmin(page);
}

test('hides the sidebar, keeps it hidden across a reload, then shows it again', async ({
  page,
}) => {
  await page.setViewportSize(TABLET_LANDSCAPE);
  const signedIn = await signInWithEmptyList(page);
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
