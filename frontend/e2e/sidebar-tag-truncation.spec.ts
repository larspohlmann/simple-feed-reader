import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

// Above the 720px breakpoint, so the sidebar is an in-flow 260px column rather
// than the mobile drawer — that fixed width is what the row has to fit into.
const DESKTOP = { width: 1280, height: 900 };

/** Comfortably wider than the 260px sidebar at any sane font size. */
const LONG_TAG = 'Wissenschaft und Forschung International';
const SHORT_TAG = 'Science';

const TAGS = [
  { id: 1, name: LONG_TAG, color: null, icon: null, position: 0 },
  { id: 2, name: SHORT_TAG, color: null, icon: null, position: 1 },
];

function subscription(id: number, title: string, tagId: number) {
  return {
    id,
    feedId: id,
    title,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://example.invalid/${id}/feed.xml`,
    siteUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-07-25T10:00:00Z',
    lastFetchedAt: null,
    position: id,
    tags: [{ ...TAGS.find((t) => t.id === tagId)!, position: 0 }],
    unreadCount: 1026,
  };
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

/**
 * Stub the two calls the sidebar builds its tag tree from, so the row under
 * test has a known long name instead of depending on the seeded account's data.
 */
async function stubTagTree(page: Page): Promise<void> {
  await page.route('**/api/tags', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { tags: TAGS } });
  });
  await page.route('**/api/subscriptions', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({
      status: 200,
      json: {
        subscriptions: [subscription(5, 'A feed', 1), subscription(6, 'Another feed', 2)],
        favoritesCount: 0,
        keptCount: 0,
      },
    });
  });
}

/**
 * The Material Symbols webfont lands a beat after first paint and reflows every
 * row, so measuring before it settles reads stale geometry.
 */
async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
}

test.describe('Sidebar tag rows', () => {
  test.use({ viewport: DESKTOP });

  // A tag row is a fixed leading slot, a growing name, an unread count and a
  // trailing `⋯` menu. The name is the only part allowed to give: if it does
  // not truncate, the row grows past the 260px column and pushes the count and
  // the menu out of the sidebar, which makes the tag unmanageable rather than
  // merely ugly (#131).
  test('a long tag name truncates instead of pushing the count and menu out', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubTagTree(page);
    await page.reload();

    const sidebar = page.getByRole('navigation', { name: 'Feeds' });
    const menu = page.getByRole('button', { name: `Manage ${LONG_TAG}` });
    await expect(menu).toBeVisible();
    await settle(page);

    // The name is clipped to its box rather than rendered at full width.
    const nameOverflows = await page
      .locator('.taghead', { hasText: LONG_TAG })
      .locator('.nav.grow > span:not(.count):not(.lead)')
      .evaluate((el) => el.scrollWidth > el.clientWidth);
    expect(nameOverflows).toBe(true);

    // Nothing escapes the column: the row menu's right edge stays inside the
    // sidebar's, and the sidebar gains no horizontal scroll.
    const sidebarBox = (await sidebar.boundingBox())!;
    const menuBox = (await menu.boundingBox())!;
    expect(menuBox.x + menuBox.width).toBeLessThanOrEqual(sidebarBox.x + sidebarBox.width);

    const overflows = await sidebar.evaluate((el) => el.scrollWidth > el.clientWidth);
    expect(overflows).toBe(false);

    // Laid out inside the column is not the same as reachable: assert the menu
    // actually opens from a real click at its centre.
    await menu.click();
    await expect(page.getByRole('menuitem', { name: 'Edit tag' })).toBeVisible();
  });
});
