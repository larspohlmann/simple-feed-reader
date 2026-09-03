import { expect, Page, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';
import { readerFailedJson, savedSearchesJson, savedSearchWire } from './support/reader';

const SAVED_SEARCH = savedSearchWire({
  id: 501,
  term: 'climate',
  unreadEntryIds: [],
});

const ENTRY = {
  id: 1,
  title: 'Climate fixture article',
  url: 'https://fixtures.invalid/article',
  author: null,
  summary: 'A fixture for saved-search reading layouts.',
  contentHtml: '<p>Fixture article body.</p>',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: '2026-08-01T12:00:00Z',
  createdAt: '2026-08-01T12:00:00Z',
  subscriptionId: 1,
  source: 'Fixture feed',
  faviconUrl: null,
  isHidden: true,
  isViewed: true,
  isFavorite: false,
  isKept: false,
};

async function stubReader(page: Page): Promise<void> {
  await stubAuthToken(page);
  await page.addInitScript(() => localStorage.setItem('sfr.layout', 'magazine'));
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const json = (body: unknown) => route.fulfill({ json: body });
    if (path.endsWith('/setup/status')) {
      return json({ needsSetup: false, mailEnabled: false, passkeySignInAvailable: false });
    }
    if (path.endsWith('/me'))
      return json({ email: 'fixture@example.invalid', roles: ['ROLE_USER'] });
    if (path.endsWith('/subscriptions')) {
      return json({
        subscriptions: [
          {
            id: 1,
            feedId: 10,
            title: 'Fixture feed',
            customTitle: null,
            lastFetchedAt: '2026-08-01T12:00:00Z',
            feedUrl: 'https://fixtures.invalid/feed',
            siteUrl: null,
            status: 'active',
            sourceFormat: 'xml',
            createdAt: '2026-08-01T12:00:00Z',
            tags: [],
            unreadCount: 0,
            includeInAllItems: true,
            includeInForYou: true,
          },
        ],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 1,
      });
    }
    if (path.endsWith('/tags')) return json({ tags: [] });
    if (path.endsWith('/api/saved-searches')) return json(savedSearchesJson(SAVED_SEARCH));
    if (path.endsWith('/entries/saved-searches')) {
      return json({ entries: [ENTRY], nextCursor: null, savedSearchIds: { '1': 501 } });
    }
    if (path.endsWith('/entries/search') || path.endsWith('/entries')) {
      return json({ entries: [ENTRY], nextCursor: null });
    }
    if (path.endsWith('/entries/1/reader')) return json(readerFailedJson('unextractable'));
    if (path.endsWith('/entries/1')) return json(ENTRY);
    if (path.endsWith('/recommendations/runs/current')) {
      return json({
        status: 'none',
        batchesTotal: null,
        batchesDone: 0,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
      });
    }
    if (path.endsWith('/version')) {
      return json({
        version: 'dev',
        commit: 'local',
        builtAt: '',
        latest: null,
        updateAvailable: false,
      });
    }
    return json({});
  });
}

test.describe('saved-search reading layout', () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('sidebar links keep magazine while direct search uses the split pane', async ({ page }) => {
    await stubReader(page);
    await page.goto('/reader?view=saved-searches');
    await expect(page.locator('.rows.magazine article')).toHaveCount(1);
    await expect(page.locator('.main.split')).toHaveCount(0);

    await page.locator('.savedsearch-head .chevzone').click();
    await page.locator('a.savedsearch-item').click();
    await expect(page).toHaveURL(/searchOrigin=saved/);
    await expect(page.locator('app-sidebar input')).toHaveValue('');
    await expect(page.locator('.rows.magazine article')).toHaveCount(1);
    await expect(page.locator('.main.split')).toHaveCount(0);
    await page.reload();
    await expect(page.locator('.rows.magazine article')).toHaveCount(1);

    await page.locator('.rows article').click();
    await expect(page.locator('.article-overlay')).toBeVisible();
    await page.goBack();
    await expect(page.locator('.article-overlay')).toHaveCount(0);

    const field = page.locator('app-sidebar input[type="text"]');
    await expect(field).toHaveValue('');
    await field.fill('climate');
    await expect(page).toHaveURL(/q=climate/);
    await expect(page).not.toHaveURL(/searchOrigin=/);
    await expect(page.locator('.main.split app-entry-row')).toHaveCount(1);
    await expect(field).toHaveValue('climate');

    await page.locator('.savedsearch-head .chevzone').click();
    await field.fill('cl');
    await page.locator('a.savedsearch-item').click();
    await expect(field).toHaveValue('');
    await expect(page.locator('.rows.magazine article')).toHaveCount(1);
    await expect(page.locator('.main.split')).toHaveCount(0);
    await page.goBack();
    await expect(page.locator('.main.split app-entry-row')).toHaveCount(1);
    await expect(field).toHaveValue('climate');
    await page.goForward();
    await expect(page.locator('.rows.magazine article')).toHaveCount(1);
    await page.screenshot({ path: test.info().outputPath('saved-search-magazine.png') });
  });
});

test.describe('saved-search reading layout on a phone', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('uses magazine for saved searches and keeps direct search in the narrow layout', async ({
    page,
  }) => {
    await stubReader(page);
    await page.goto('/reader?q=climate&searchOrigin=saved');
    await expect(page.locator('.rows.magazine article')).toHaveCount(1);
    await expect(page.locator('.main.split')).toHaveCount(0);

    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('app-reader-header input')).toHaveValue('');
    await page.locator('app-reader-header input').fill('climate');
    await expect(page).not.toHaveURL(/searchOrigin=/);
    await expect(page.locator('.rows app-entry-row')).toHaveCount(1);
    await expect(page.locator('.rows.magazine')).toHaveCount(0);
    await expect(page.locator('.main.split')).toHaveCount(0);
  });
});
