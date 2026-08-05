// e2e/add-feed-progress.spec.ts
import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const SITE = 'https://slow-discovery.sfr-e2e.example/';

/** Long enough to observe the indicator, short enough for the 30 s test timeout. */
const DISCOVERY_MILLIS = 1500;

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/**
 * A subscribe that takes its time, which is what a real one does once discovery
 * starts probing a site that hides its feed. The delay is the whole point of
 * the test, so it is stubbed rather than provoked: no live host refuses us on
 * cue, and UrlGuard refuses locally served fixtures.
 */
async function stubSlowDiscovery(page: Page): Promise<void> {
  await page.route('**/api/subscriptions', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    await new Promise((resolve) => setTimeout(resolve, DISCOVERY_MILLIS));
    await route.fulfill({
      status: 201,
      json: { subscription: { id: 4243, title: 'Found the hidden feed' } },
    });
  });
}

test('the add-feed dialog reports that a slow search is running', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await stubSlowDiscovery(page);

  await page.getByRole('button', { name: 'Add feed' }).click();
  const dialog = page.getByRole('dialog', { name: 'Add a feed' });
  await expect(dialog).toBeVisible();

  await dialog.getByRole('textbox', { name: 'Feed or site URL' }).fill(SITE);
  await dialog.getByRole('button', { name: 'Add' }).click();

  const searching = dialog.getByText('Looking for a feed…');
  await expect(searching).toBeVisible();

  // And it stops: an indicator that outlives its request is worse than none.
  await expect(dialog).toBeHidden();
});
