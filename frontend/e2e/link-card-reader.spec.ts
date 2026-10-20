import { test, expect, Page } from '@playwright/test';
import { aLinkCard } from '../src/testing/entry-testing';
import { entryDetailJson, entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';

const DESKTOP = { width: 1280, height: 900 };

const ENTRY = entryWire({
  id: 7001,
  title: 'A post sharing a link',
  subscriptionId: 1,
  source: 'Fixture feed',
  share: 'link',
  linkCards: [aLinkCard({ title: 'The linked story' })],
});

const LINKED_PAGE = {
  status: 'ok',
  url: 'https://news.test/story',
  title: 'The linked story',
  byline: null,
  siteName: 'news.test',
  contentHtml: '<p>Linked page body</p>',
  excerpt: null,
  paywalled: false,
  originalHero: null,
  extractedAt: '2026-08-01T12:50:34+00:00',
};

test.use({ viewport: DESKTOP });

async function stubLinkCardEntry(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ json: { entries: [ENTRY], nextCursor: null } });
    },
  );
  await page.route(`**/api/entries/${ENTRY.id}`, async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ json: entryDetailJson(ENTRY, '<p>The post body</p>') });
  });
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ json: readerFailedJson('unextractable') }),
  );
  await page.route('**/api/reader?*', (route) => route.fulfill({ json: LINKED_PAGE }));
  await page.route('**/api/entries/*/state', (route) => route.fulfill({ json: {} }));
}

test('a link card opens in the reader from the magazine and from the article', async ({ page }) => {
  await stubLinkCardEntry(page);
  await page.goto('/');

  const linkReader = page.locator('app-link-reader');
  const openInReader = page.locator('app-link-card button.open-in-reader');

  await openInReader.click();
  await expect(linkReader).toContainText('Linked page body');
  await expect(page).toHaveURL(new RegExp(`link=${ENTRY.id}\\.0`));
  expect(page.url()).not.toContain('entry=');

  await linkReader.getByRole('button', { name: 'Back to list', exact: true }).click();
  await expect(linkReader).toHaveCount(0);
  await expect(openInReader).toBeVisible();
  expect(page.url()).not.toContain('link=');

  await page.getByText('A post sharing a link', { exact: true }).first().click();
  await expect(page).toHaveURL(/entry=/);
  await expect(page.locator('app-reader-view')).toContainText('The post body');

  await page.locator('app-reader-view app-link-card button.open-in-reader').click();
  await expect(linkReader).toContainText('Linked page body');
  await expect(page).toHaveURL(/entry=/);
  await expect(page).toHaveURL(/link=/);

  await linkReader.getByRole('button', { name: 'Back to article', exact: true }).click();
  await expect(linkReader).toHaveCount(0);
  await expect(page.locator('app-reader-view')).toBeVisible();
  await expect(page).toHaveURL(/entry=/);
  expect(page.url()).not.toContain('link=');
});
