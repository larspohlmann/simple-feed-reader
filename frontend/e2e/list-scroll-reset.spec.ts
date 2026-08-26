// e2e/list-scroll-reset.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `reader-smoke.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/** How far down the list the spec scrolls before switching lists. */
const SCROLLED_TO = 900;

const TAG = { id: 7001, name: 'Scroll fixture', color: null, icon: null, position: 0 };

/** The two lists under test, each named by the sidebar link that opens it and by
 *  a row title only that list holds — the switch is only complete once the
 *  incoming list's own rows are on screen, and identical fixtures could not say. */
const ALL_LIST = { link: 'All items', rowPrefix: 'All fixture entry' };
const TAG_LIST = { link: TAG.name, rowPrefix: 'Tag fixture entry' };

/** The results a search shows, named apart from both lists for the same reason:
 *  only their own rows prove the search — and not the list under it — is up. */
const SEARCH_RESULTS = { rowPrefix: 'Search fixture entry' };

/** A term long enough for the field to search on (MIN_SEARCH_LENGTH). */
const SEARCH_TERM = 'fixture';

/** The phone the header's own search bar is built for. */
const PHONE = { width: 375, height: 667 };

/** Where the list rests when the search is opened. The header retracts on the
 *  way down and comes back on the way up, so the search button is only in reach
 *  after a scroll back — which is how a thumb reaches it too. */
const RESTS_AT = SCROLLED_TO - 200;

function entry(id: number, title: string) {
  return {
    id,
    title,
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
    isHidden: false,
    isFavorite: false,
    isKept: false,
  };
}

/** Long enough that both lists scroll well past SCROLLED_TO. */
function entriesFor(list: { rowPrefix: string }) {
  return Array.from({ length: 60 }, (_, i) => entry(i + 1, `${list.rowPrefix} ${i + 1}`));
}

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
      unreadCount: 60,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
};

async function stubGet(page: Page, pathname: string, json: unknown): Promise<void> {
  await page.route(
    (url) => url.pathname === pathname,
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json });
    },
  );
}

/**
 * Own every list this spec scrolls. The assertion is about a scroll offset, so
 * both lists must be reliably taller than the viewport — reading whatever the
 * seeded account happens to hold would pass on a developer machine and fail on a
 * fresh database.
 *
 * Matched on the pathname so `/api/entries/{id}` and `/api/entries/{id}/state`
 * still reach the real backend.
 */
async function stubReaderData(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const url = new URL(route.request().url());
      const list = url.searchParams.get('tag') === String(TAG.id) ? TAG_LIST : ALL_LIST;
      await route.fulfill({ status: 200, json: { entries: entriesFor(list), nextCursor: null } });
    },
  );
  await stubGet(page, '/api/entries/search', {
    entries: entriesFor(SEARCH_RESULTS),
    nextCursor: null,
  });
  await stubGet(page, '/api/subscriptions', SUBSCRIPTIONS);
  await stubGet(page, '/api/tags', { tags: [TAG] });
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
  return page.locator('app-entry-list .rows').first();
}

function scrollTop(page: Page): Promise<number> {
  return listScroller(page).evaluate((el) => el.scrollTop);
}

/**
 * Wait for a list's own rows before touching the scroller. The outgoing list
 * stays rendered while the next query runs (#254), so the scroller is visible
 * throughout a switch — and an offset scrolled before the incoming rows land is
 * never written to that list's memory, which would leave every assertion below
 * passing against an empty memory.
 */
async function showsRowsOf(page: Page, list: { rowPrefix: string }): Promise<void> {
  await expect(page.getByText(`${list.rowPrefix} 1`, { exact: true })).toBeVisible();
}

async function openList(page: Page, list: { link: string; rowPrefix: string }): Promise<void> {
  await page
    .getByRole('navigation', { name: 'Feeds' })
    .getByRole('link', { name: list.link })
    .click();
  await showsRowsOf(page, list);
}

/** On a phone the sidebar is a drawer, so a list is opened through it. */
async function openTagListFromDrawer(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Toggle sidebar' }).click();
  await openList(page, TAG_LIST);
}

async function scrollListTo(page: Page, top: number): Promise<void> {
  await listScroller(page).evaluate((el, to) => el.scrollTo({ top: to }), top);
  // The offset is only remembered from the scroll event, which fires after the
  // assignment; waiting on the settled value is what makes the rest deterministic.
  await expect.poll(() => scrollTop(page)).toBe(top);
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

    // Give BOTH lists a remembered place, so neither assertion below can pass
    // merely because that list had never been scrolled.
    await showsRowsOf(page, ALL_LIST);
    await scrollListTo(page, SCROLLED_TO);
    await openList(page, TAG_LIST);
    await scrollListTo(page, SCROLLED_TO);

    // --- Back restores the place the list was left at. ---
    await page.goBack();
    await showsRowsOf(page, ALL_LIST);
    await expect.poll(() => scrollTop(page)).toBe(SCROLLED_TO);

    // --- A click asks for the list afresh: top, not where it was left. ---
    await openList(page, TAG_LIST);
    await expect.poll(() => scrollTop(page)).toBe(0);
  });

  /**
   * Settings destroys the reader shell. The rule has to survive that: a listener
   * tied to the shell's lifetime would come back knowing nothing about the list
   * the user had left, and the next click would restore instead of reset.
   */
  test('a click still starts at the top after a trip through settings', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await showsRowsOf(page, ALL_LIST);
    await openList(page, TAG_LIST);
    await scrollListTo(page, SCROLLED_TO);

    await page.getByRole('button', { name: 'Account' }).click();
    await page.getByRole('menuitem', { name: 'Settings' }).click();
    await expect(page).toHaveURL(/\/settings/);
    await page.getByRole('link', { name: 'Reader' }).click();
    await showsRowsOf(page, ALL_LIST);

    await openList(page, TAG_LIST);

    await expect.poll(() => scrollTop(page)).toBe(0);
  });

  test('a reload lands where the list was left, as a resume-reload must', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await showsRowsOf(page, ALL_LIST);
    await scrollListTo(page, SCROLLED_TO);

    await page.reload();

    await showsRowsOf(page, ALL_LIST);
    await expect.poll(() => scrollTop(page)).toBe(SCROLLED_TO);
  });
});

/**
 * A search is a list the user asks for by typing, and closing it is a return to
 * the list it was started from — not a click on a new one (#579). On a phone the
 * search bar covers the header, so this is where the loss was seen.
 */
test.describe('list scroll position around a search', () => {
  test.use({ viewport: PHONE, isMobile: true, hasTouch: true });

  test('closing the search lands back where the list was left', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await showsRowsOf(page, ALL_LIST);
    await openTagListFromDrawer(page);
    await scrollListTo(page, SCROLLED_TO);
    await scrollListTo(page, RESTS_AT);

    await page.getByRole('button', { name: 'Search' }).click();
    await page.getByPlaceholder('Search articles').fill(SEARCH_TERM);
    await showsRowsOf(page, SEARCH_RESULTS);
    // The results are their own list: they start at the top, and scrolling them
    // must not be mistaken for scrolling the list underneath.
    await expect.poll(() => scrollTop(page)).toBe(0);

    // The bar's ✕ is a two-step contract on a phone: the first tap empties the
    // box, the second ends the search and closes the bar.
    await page.getByRole('button', { name: 'Clear search' }).click();
    await page.getByRole('button', { name: 'Close search' }).click();

    await showsRowsOf(page, TAG_LIST);
    await expect.poll(() => scrollTop(page)).toBe(RESTS_AT);
  });
});
