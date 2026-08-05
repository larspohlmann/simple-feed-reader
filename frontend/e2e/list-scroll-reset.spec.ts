// e2e/list-scroll-reset.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `reader-smoke.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/** How far down the list the spec scrolls before switching lists. */
const SCROLLED_TO = 900;

const TAG = { id: 7001, name: 'Scroll fixture', color: null, icon: null, position: 0 };

function entry(id: number) {
  return {
    id,
    title: `Scroll fixture entry ${id}`,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: 'A fixture summary, long enough to give the row some height.',
    contentHtml: '<p>Fixture body.</p>',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: '2026-08-01T12:50:34+00:00',
    createdAt: '2026-08-01T12:50:34+00:00',
    subscriptionId: 7002,
    source: 'Scroll fixture feed',
    faviconUrl: null,
    isRead: false,
    isFavorite: false,
    isKept: false,
  };
}

/** Long enough that every list under test scrolls well past SCROLLED_TO. */
const ENTRIES = Array.from({ length: 60 }, (_, i) => entry(i + 1));

const SUBSCRIPTIONS = {
  subscriptions: [
    {
      id: 7002,
      feedId: 7003,
      title: 'Scroll fixture feed',
      customTitle: null,
      lastFetchedAt: '2026-08-01T10:00:00+00:00',
      feedUrl: 'https://fixtures.invalid/feed.xml',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: '2026-08-01T10:00:00+00:00',
      tags: [TAG],
      unreadCount: ENTRIES.length,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
};

/**
 * Own every list this spec scrolls. The assertion is about a scroll offset, so
 * both lists must be reliably taller than the viewport and identical in height —
 * reading whatever the seeded account happens to hold would pass on a developer
 * machine and fail on a fresh database.
 *
 * Matched on the pathname so `/api/entries/{id}` and `/api/entries/{id}/state`
 * still reach the real backend.
 */
async function stubReaderData(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/subscriptions',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: SUBSCRIPTIONS });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/tags',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { tags: [TAG] } });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await stubReaderData(page);
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/** The list's own scroller — the shell locks the page and scrolls this instead. */
function listScroller(page: Page) {
  return page.locator('app-entry-list .rows');
}

async function scrollListTo(page: Page, top: number): Promise<void> {
  const rows = listScroller(page);
  await expect(rows.first()).toBeVisible();
  await rows.first().evaluate((el, to) => el.scrollTo({ top: to }), top);
  // The offset is only remembered from the scroll event, which fires after the
  // assignment; waiting on the settled value is what makes the rest deterministic.
  await expect.poll(() => rows.first().evaluate((el) => el.scrollTop)).toBe(top);
}

function scrollTop(page: Page): Promise<number> {
  return listScroller(page)
    .first()
    .evaluate((el) => el.scrollTop);
}

/**
 * Asking for a list is not the same as returning to one (#286). A click on a tag
 * or on "All items" asks for that list and must show it from the top; back and
 * forward return to a list, where the remembered place is the point.
 */
test.describe('list scroll position on a list switch', () => {
  test('a clicked list starts at the top, and going back restores the place left behind', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    const sidebar = page.getByRole('navigation', { name: 'Feeds' });
    await scrollListTo(page, SCROLLED_TO);

    // --- A click on a tag shows that list from the top. ---
    await sidebar.getByRole('link', { name: TAG.name }).click();
    await expect(page).toHaveURL(new RegExp(`tag=${TAG.id}`));
    await expect.poll(() => scrollTop(page)).toBe(0);

    // --- Back to "All items" restores where that list was left. ---
    await page.goBack();
    await expect(page).not.toHaveURL(new RegExp(`tag=${TAG.id}`));
    await expect.poll(() => scrollTop(page)).toBe(SCROLLED_TO);

    // --- Clicking the tag again asks for it afresh: top, not where it was. ---
    await scrollListTo(page, SCROLLED_TO);
    await sidebar.getByRole('link', { name: TAG.name }).click();
    await expect.poll(() => scrollTop(page)).toBe(0);
    await scrollListTo(page, SCROLLED_TO);
    await sidebar.getByRole('link', { name: 'All items' }).click();
    await sidebar.getByRole('link', { name: TAG.name }).click();
    await expect.poll(() => scrollTop(page)).toBe(0);
  });

  test('a reload lands where the list was left, as a resume-reload must', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await scrollListTo(page, SCROLLED_TO);

    await page.reload();

    await expect(listScroller(page).first()).toBeVisible();
    await expect.poll(() => scrollTop(page)).toBe(SCROLLED_TO);
  });
});
