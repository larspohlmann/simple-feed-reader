// e2e/saved-searches-combined.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-kicker-one-line.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/** Two saved searches, in the sidebar's own order — the pill on a row must name
 *  the FIRST of these that matched, not whichever the fixture lists last. Both
 *  carry no unread matches of their own (`unreadEntryIds: []`), so the sidebar
 *  toggle's accessible name stays the bare "Saved searches" with no trailing
 *  count appended — the substring collision #723 warns about (a child row
 *  sharing the toggle's name prefix) is avoided by giving the child rows
 *  entirely different terms instead. */
const SAVED_SEARCHES = [
  {
    id: 501,
    term: 'climate',
    wholeWord: false,
    phrase: false,
    position: 0,
    unreadEntryIds: [],
    includeInDigest: false,
  },
  {
    id: 502,
    term: 'space',
    wholeWord: false,
    phrase: false,
    position: 1,
    unreadEntryIds: [],
    includeInDigest: false,
  },
];

function entry(id: number, title: string) {
  return {
    id,
    title,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: null,
    contentHtml: '<p>Fixture body.</p>',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: '2026-08-01T12:50:34+00:00',
    createdAt: '2026-08-01T12:50:34+00:00',
    subscriptionId: 1,
    source: 'Fixture feed',
    faviconUrl: null,
    isHidden: false,
    isFavorite: false,
    isKept: false,
  };
}

/** Entry 1 matches "climate" (501), entry 2 matches "space" (502) — deliberately
 *  out of numeric order in the map below so the test can't pass by accident on
 *  key ordering. `savedSearchIds` keys arrive as strings on the wire. */
const ALL_ENTRIES = [entry(1, 'Fixture entry 1'), entry(2, 'Fixture entry 2')];
const ALL_SAVED_SEARCH_IDS = { '2': 502, '1': 501 };

const UNREAD_ENTRIES = [entry(1, 'Fixture entry 1')];
const UNREAD_SAVED_SEARCH_IDS = { '1': 501 };

/**
 * Stub every route the combined saved-search view depends on, so the spec owns
 * every byte it asserts on: reading whatever the seeded account happens to hold
 * would pass on a developer machine and fail on a fresh database (see
 * `magazine-kicker-one-line.spec.ts`). `/api/entries` is stubbed too because the
 * shared login helper's own boot request must not 401 and bounce to `/login`.
 */
async function stubReaderData(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: [], nextCursor: null } });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/saved-searches',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { savedSearches: SAVED_SEARCHES } });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/entries/saved-searches',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const unread = new URL(route.request().url()).searchParams.get('unread') === '1';
      const json = unread
        ? { entries: UNREAD_ENTRIES, nextCursor: null, savedSearchIds: UNREAD_SAVED_SEARCH_IDS }
        : { entries: ALL_ENTRIES, nextCursor: null, savedSearchIds: ALL_SAVED_SEARCH_IDS };
      await route.fulfill({ status: 200, json });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await stubReaderData(page);
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

test('the Saved searches row opens one combined list', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Scoped to the sidebar's own toggle row, not any `getByRole('link', ...)`
  // match: a saved-search child row's term could share the "Saved searches"
  // prefix (#723), and clicking that instead would silently mis-navigate.
  await page.locator('a.savedsearch-toggle', { hasText: 'Saved searches' }).click();

  await expect(page).toHaveURL(/view=saved-searches/);
  await expect(page.getByRole('heading', { name: 'Saved searches' })).toBeVisible();
  // The label navigates INSTEAD of expanding the child list now — only the
  // chevron does that. The fixture seeds two saved searches, so an empty
  // list can't produce a false pass here.
  await expect(page.locator('a.savedsearch-item')).toHaveCount(0);

  const rows = page.locator('.rows article');
  await expect(rows).toHaveCount(2);

  // Each row names the saved search it came from — the FIRST match, in the
  // sidebar's order (entry 1 => "climate", entry 2 => "space").
  await expect(rows.nth(0).locator('.saved-search-pill')).toHaveText('climate');
  await expect(rows.nth(1).locator('.saved-search-pill')).toHaveText('space');
});

test('the unread switch narrows the combined list', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/reader?view=saved-searches');
  await expect(page.locator('.rows article')).toHaveCount(2);

  await page.getByRole('switch', { name: 'only unread' }).click();

  await expect(page).toHaveURL(/unread=1/);
  const rows = page.locator('.rows article');
  await expect(rows).toHaveCount(1);
  await expect(rows.first().locator('.saved-search-pill')).toHaveText('climate');
});
