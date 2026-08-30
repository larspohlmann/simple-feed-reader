import { expect, Page, test } from '@playwright/test';
import { readerFailedJson } from './support/reader';

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';
const DESKTOP = { width: 1280, height: 800 };
const SEARCH_TERM = 'desktop fixture';

function entry(id: number, title: string) {
  return {
    id,
    title,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: 'A fixture summary.',
    contentHtml: '<p>Fixture body.</p>',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: '2026-08-25T12:00:00+00:00',
    createdAt: '2026-08-25T12:00:00+00:00',
    subscriptionId: 607,
    source: 'Desktop search fixture',
    faviconUrl: null,
    isHidden: true,
    isFavorite: false,
    isKept: false,
    isViewed: true,
  };
}

const MAGAZINE_ENTRY = entry(6070, 'Magazine fixture entry');
const SEARCH_ENTRY = entry(6071, 'Desktop search fixture result');

const SUBSCRIPTIONS = {
  subscriptions: [
    {
      id: 607,
      feedId: 607,
      title: 'Desktop search fixture',
      faviconUrl: null,
      customTitle: null,
      feedUrl: 'https://fixtures.invalid/feed.xml',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: '2026-08-25T12:00:00+00:00',
      lastFetchedAt: '2026-08-25T12:00:00+00:00',
      position: 0,
      tags: [],
      unreadCount: 0,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
  viewedCount: 0,
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

async function stubReaderData(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({
        status: 200,
        json: { entries: [MAGAZINE_ENTRY], nextCursor: null },
      });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/entries/search',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: [SEARCH_ENTRY], nextCursor: null } });
    },
  );
  await page.route('**/api/entries/*/reader', async (route) => {
    await route.fulfill({ status: 200, json: readerFailedJson('unextractable') });
  });
  await stubGet(page, '/api/subscriptions', SUBSCRIPTIONS);
  await stubGet(page, '/api/tags', { tags: [] });
  await stubGet(page, '/api/saved-searches', { savedSearches: [] });
  await stubGet(page, '/api/catalog', {
    categories: [
      {
        id: 607,
        key: 'fixture',
        name: 'Fixture',
        icon: 'rss_feed',
        color: '#3b82f6',
        feeds: [
          {
            id: 607,
            title: 'Desktop search fixture',
            description: null,
            siteUrl: null,
            faviconUrl: '',
            subscribed: true,
          },
        ],
      },
    ],
  });
  await stubGet(page, '/api/recommendations/runs/current', {
    status: 'none',
    batchesTotal: null,
    batchesDone: 0,
    error: null,
    background: false,
    streamedChars: 0,
    forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
  });
  await stubGet(page, '/api/version', {
    version: 'dev',
    commit: 'local',
    builtAt: '',
    latest: null,
    updateAvailable: false,
  });
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

test.describe('desktop search split view', () => {
  test.use({ viewport: DESKTOP });

  test('opens search results beside the reader and restores magazine on clear', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await page.getByRole('button', { name: 'Magazine layout, boxed' }).click();
    await expect(page.locator('.rows.magazine')).toBeVisible();

    await page.getByPlaceholder('Search articles').fill(SEARCH_TERM);
    await expect(page.getByText(SEARCH_ENTRY.title, { exact: true })).toBeVisible();

    const main = page.locator('main.main');
    await expect(main).toHaveClass(/\bsplit\b/);
    await expect(main.locator('.reader .placeholder')).toContainText('Select an article to read.');

    await page.getByText(SEARCH_ENTRY.title, { exact: true }).click();

    await expect(main.locator('.reader h1.title')).toHaveText(SEARCH_ENTRY.title);
    await expect(main.locator('.article-overlay')).toHaveCount(0);

    await page.getByRole('button', { name: 'Clear search' }).click();

    await expect(page.getByText(MAGAZINE_ENTRY.title, { exact: true })).toBeVisible();
    await expect(main).not.toHaveClass(/\bsplit\b/);
    await expect(page.locator('.rows.magazine')).toBeVisible();
  });
});
