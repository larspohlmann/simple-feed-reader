// e2e/sidebar-mobile.spec.ts
import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const NEWS_TAG = { id: 1, name: 'News', color: null, icon: null, position: 0 };

/** A stubbed sidebar: one tagged feed under News, one untagged. Stubbed rather
 *  than seeded because this measures geometry and interaction, and a fixed
 *  tree keeps the assertions deterministic. */
const SUBS = {
  favoritesCount: 0,
  keptCount: 0,
  subscriptions: [
    {
      id: 5,
      feedId: 55,
      title: 'The Verge',
      customTitle: null,
      lastFetchedAt: '2026-07-25T10:00:00Z',
      feedUrl: 'https://f/5',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: 'x',
      position: 0,
      unreadCount: 3,
      tags: [NEWS_TAG],
    },
    {
      id: 6,
      feedId: 66,
      title: 'Daring Fireball',
      customTitle: null,
      lastFetchedAt: '2026-07-25T10:00:00Z',
      feedUrl: 'https://f/6',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: 'x',
      position: 1,
      unreadCount: 1,
      tags: [],
    },
  ],
};

async function stubSidebarData(page: Page): Promise<void> {
  await page.route('**/api/subscriptions', (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({ status: 200, json: SUBS })
      : route.fallback(),
  );
  await page.route('**/api/tags', (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({ status: 200, json: { tags: [NEWS_TAG] } })
      : route.fallback(),
  );
  await page.route('**/api/entries*', (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({ status: 200, json: { entries: [], nextCursor: null } })
      : route.fallback(),
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

test.describe('Sidebar on a phone', () => {
  // isMobile + hasTouch make `(pointer: coarse)` match, which is what switches
  // the sidebar to touch density and reveals the Organise switch.
  test.use({ viewport: { width: 375, height: 667 }, isMobile: true, hasTouch: true });

  test('drawer navigation, organise mode and the action sheet stay on-screen', async ({ page }) => {
    await stubSidebarData(page);
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    // Open the drawer.
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    const sidebar = page.getByRole('navigation', { name: 'Feeds' });
    await expect(sidebar).toBeVisible();

    // Navigation mode: 44px rows, and the chevron zone expands without navigating.
    const newsRow = sidebar.getByRole('link', { name: /News/ });
    await expect(newsRow).toBeVisible();
    expect((await newsRow.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    const chevron = sidebar.getByRole('button', { name: 'Toggle News' });
    expect((await chevron.boundingBox())!.width).toBeGreaterThanOrEqual(44);
    await chevron.click();
    await expect(sidebar.getByRole('link', { name: /The Verge/ })).toBeVisible();

    // Organise mode strips the drawer down to the organisable structure.
    await sidebar.getByRole('switch', { name: 'Organise' }).click();
    await expect(sidebar.getByRole('link', { name: /All items/ })).toBeHidden();
    await expect(sidebar.getByRole('button', { name: 'Refresh' })).toBeHidden();

    // The row menu is a bottom sheet, fully inside the viewport (#185: the old
    // popover could leave the screen).
    await sidebar.getByRole('button', { name: 'Manage News' }).click();
    const sheet = page.getByRole('menu', { name: 'News' });
    await expect(sheet).toBeVisible();
    // The sheet slides up over 0.2s; toBeVisible resolves at animation start,
    // while the sheet is still translated below the viewport. The quality bar
    // is about the resting geometry, so let the enter animation finish first.
    await page
      .locator('app-action-sheet')
      .evaluate((el) => Promise.all(el.getAnimations().map((animation) => animation.finished)));
    const box = (await sheet.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(375 + 1);
    expect(box.y + box.height).toBeLessThanOrEqual(667 + 1);
    await expect(sheet.getByRole('menuitem', { name: 'Delete tag' })).toBeVisible();

    // Backdrop dismisses the sheet; toggling Organise off restores navigation.
    await page.locator('.cdk-overlay-backdrop').click();
    await expect(sheet).toBeHidden();
    await sidebar.getByRole('switch', { name: 'Organise' }).click();
    await expect(sidebar.getByRole('link', { name: /All items/ })).toBeVisible();
  });
});
