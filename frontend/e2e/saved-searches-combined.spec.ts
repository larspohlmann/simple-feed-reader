// e2e/saved-searches-combined.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-kicker-one-line.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/** Two saved searches. Both carry no unread matches of their own
 *  (`unreadEntryIds: []`), so the sidebar toggle's accessible name stays the
 *  bare "Saved searches" with no trailing count appended — the substring
 *  collision #723 warns about (a child row sharing the toggle's name prefix) is
 *  avoided by giving the child rows entirely different terms instead. */
const SAVED_SEARCHES = [
  {
    id: 501,
    slug: '501-climate',
    term: 'climate',
    wholeWord: false,
    phrase: false,
    position: 0,
    unreadEntryIds: [],
    includeInDigest: false,
  },
  {
    id: 502,
    slug: '502-space',
    term: 'space',
    wholeWord: false,
    phrase: false,
    position: 1,
    unreadEntryIds: [],
    includeInDigest: false,
  },
];

function entry(id: number, title: string, savedSearch: (typeof SAVED_SEARCHES)[number]) {
  return {
    id,
    title,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: null,
    excerpt: 'Fixture body.',
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
    savedSearches: [{ id: savedSearch.id, slug: savedSearch.slug, term: savedSearch.term }],
  };
}

const [CLIMATE, SPACE] = SAVED_SEARCHES;
const ALL_ENTRIES = [entry(1, 'Fixture entry 1', CLIMATE), entry(2, 'Fixture entry 2', SPACE)];
const UNREAD_ENTRIES = [entry(1, 'Fixture entry 1', CLIMATE)];

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
    (url) => /^\/api\/entries\/saved-searches(\/\d+)?$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const unread = new URL(route.request().url()).searchParams.get('unread') === '1';
      const entries = unread ? UNREAD_ENTRIES : ALL_ENTRIES;
      await route.fulfill({ status: 200, json: { entries, nextCursor: null } });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await stubReaderData(page);
  // The unread filter is per-account storage (#1143); clear whatever an
  // earlier run left for any account so this spec always starts at "All".
  await page.addInitScript(() => {
    for (const key of Object.keys(localStorage)) {
      if (/^sfr\.user\.\d+\.unread-only$/.test(key)) localStorage.removeItem(key);
    }
  });
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

  await expect(page).toHaveURL(/\/searches\/saved\/all$/);
  await expect(page.getByRole('heading', { name: 'Saved searches' })).toBeVisible();
  // The label navigates INSTEAD of expanding the child list now — only the
  // chevron does that. The fixture seeds two saved searches, so an empty
  // list can't produce a false pass here.
  await expect(page.locator('a.savedsearch-item')).toHaveCount(0);

  const rows = page.locator('.rows article');
  await expect(rows).toHaveCount(2);

  await expect(rows.nth(0).locator('app-entry-pills .pill.saved-search .name')).toHaveText(
    'climate',
  );
  await expect(rows.nth(1).locator('app-entry-pills .pill.saved-search .name')).toHaveText('space');
});

test('the unread switch narrows the list and stays on into a saved search', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.locator('a.savedsearch-toggle', { hasText: 'Saved searches' }).click();
  const rows = page.locator('.rows article');
  await expect(rows).toHaveCount(2);

  const unreadSwitch = page.getByRole('switch', { name: 'only unread' });
  await unreadSwitch.click();

  await expect(rows).toHaveCount(1);
  await expect(page).not.toHaveURL(/unread=/);
  await expect(rows.first().locator('app-entry-pills .pill.saved-search .name')).toHaveText(
    'climate',
  );

  await rows.first().locator('app-entry-pills .pill.saved-search').click();

  await expect(page).toHaveURL(/\/searches\/saved\/501-climate/);
  await expect(unreadSwitch).toHaveAttribute('aria-checked', 'true');
  await expect(rows).toHaveCount(1);
});
