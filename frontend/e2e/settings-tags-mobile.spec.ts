import { test, expect, Page } from '@playwright/test';

// Same seeded admin as the other e2e specs (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

// A narrow phone. The settings/tags rows must fit here with no horizontal
// overflow, in both the view and the inline-edit state (#212).
const MOBILE = { width: 360, height: 780 };

// A single unbreakable token wider than the identity column: the case that
// used to drag the feed count off the viewport before the name could shrink.
const LONG_TAG = 'Wirtschaftswissenschaftsnachrichtenaggregation';
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
    unreadCount: 3,
  };
}

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

/** Feed the tags page a known long tag instead of the seeded account's data. */
async function stubTags(page: Page): Promise<void> {
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

/** Whether the page as a whole gained horizontal scroll. */
function pageOverflows(page: Page): Promise<boolean> {
  return page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );
}

test.describe('Settings tags on mobile', () => {
  test.use({ viewport: MOBILE });

  // A long tag name is the only elastic part of the row. Before #212 it could
  // neither shrink nor break, so it overran the identity column and pushed the
  // feed count off the phone viewport. The name must give — in the view row and
  // in the inline editor alike — so the list stays inside the screen.
  test('a long tag row fits the viewport in view and edit states', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubTags(page);
    await page.goto('/settings/tags');

    // Anchor on the drag handle's label, not the name text: the text moves into
    // an input value in edit mode, but the handle — and its label — stay.
    const longRow = page.locator('li.tag', {
      has: page.getByRole('button', { name: `Reorder ${LONG_TAG}` }),
    });
    await expect(longRow).toBeVisible();
    await settle(page);

    // View state: nothing escapes the viewport.
    expect(await pageOverflows(page)).toBe(false);

    // Inline-edit state: the name field, colour picker and icon grid must fit
    // the same width.
    await longRow.getByRole('button', { name: 'Edit' }).click();
    await expect(longRow.getByRole('button', { name: 'Save' })).toBeVisible();
    await settle(page);
    expect(await pageOverflows(page)).toBe(false);
  });
});
