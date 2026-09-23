import { expect, Page, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';
import { savedSearchesJson } from './support/reader';

// The sidebar column plus a split main area: `sfr.paneSplit` sets the list column's share.
const DESKTOP = { width: 1280, height: 800 };

const SUBSCRIPTIONS = {
  subscriptions: [
    {
      id: 1,
      feedId: 1,
      title: 'Design feeds with a rather long title',
      faviconUrl: null,
      imageUrl: null,
      description: null,
      customTitle: null,
      feedUrl: 'https://fixtures.invalid/feed.xml',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: '2026-08-28T00:00:00+00:00',
      lastFetchedAt: '2026-08-28T00:00:00+00:00',
      position: 0,
      tags: [],
      unreadCount: 1,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
  viewedCount: 0,
};

interface SplitList {
  name: string;
  url: string;
  layout: string;
}

const FEED: SplitList = { name: 'a feed', url: '/?subscription=1', layout: 'pane' };
const DIRECT_SEARCH: SplitList = {
  name: 'a direct search',
  url: '/?q=hamburg',
  layout: 'magazine',
};

async function openSplit(page: Page, list: SplitList, paneSplit: string): Promise<void> {
  await stubAuthToken(page);
  await page.addInitScript(
    ([layout, split]) => {
      localStorage.setItem('sfr.layout', layout);
      localStorage.setItem('sfr.paneSplit', split);
    },
    [list.layout, paneSplit],
  );
  const json = (body: unknown) => (route: { fulfill: (response: { json: unknown }) => unknown }) =>
    route.fulfill({ json: body });

  await page.route('**/api/subscriptions**', json(SUBSCRIPTIONS));
  await page.route('**/api/tags**', json({ tags: [] }));
  await page.route('**/api/entries**', json({ entries: [], nextCursor: null }));
  await page.route('**/api/me**', json({ id: 1, email: '', roles: [], preferences: {} }));
  await page.route('**/api/version**', json({ version: 'dev' }));
  await page.route('**/api/recommendations/**', json({ run: null }));
  await page.route('**/api/saved-searches**', json(savedSearchesJson()));

  await page.goto(list.url);
  await expect(page.locator('.list-header')).toBeVisible();
}

test.describe('list header in a narrow split column (#1127)', () => {
  test.use({ viewport: DESKTOP });

  test('a column at or below the compact threshold drops the action labels', async ({ page }) => {
    await openSplit(page, FEED, '45');

    await expect(page.locator('.list-header .mark-all .txt')).toBeHidden();
    await expect(page.locator('.list-header .refresh app-icon')).toHaveCSS(
      'border-top-width',
      '1px',
    );
  });

  test('a column above the compact threshold keeps the labelled links', async ({ page }) => {
    await openSplit(page, FEED, '60');

    await expect(page.locator('.list-header .mark-all .txt')).toBeVisible();
    await expect(page.locator('.list-header .refresh app-icon')).toHaveCSS(
      'border-top-width',
      '0px',
    );
  });

  test('a direct search keeps its short labels in the compact form', async ({ page }) => {
    await openSplit(page, DIRECT_SEARCH, '45');

    for (const action of await page.locator('.list-header :is(.mark-all, .save-search)').all()) {
      await expect(action.locator('.txt')).toBeHidden();
      await expect(action.locator('.txt-short')).toBeVisible();
    }
  });
});
