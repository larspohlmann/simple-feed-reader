import { test, expect, Page } from '@playwright/test';
import { aLinkCard } from '../src/testing/entry-testing';
import { entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';

const DESKTOP = { width: 1280, height: 900 };

const CARD_TITLE = 'The story the author linked';

const ENTRY = entryWire({
  id: 7101,
  title: 'First post of the thread',
  subscriptionId: 1,
  source: 'Fixture feed',
  thread: [
    {
      url: 'https://fixtures.invalid/7101/1',
      publishedAt: '2026-08-01T12:51:34+00:00',
      html: '<p>Second post</p>',
      text: 'Second post',
      media: [],
      linkCard: null,
    },
    {
      url: 'https://fixtures.invalid/7101/2',
      publishedAt: '2026-08-01T12:52:34+00:00',
      html: '<p>Third post</p>',
      text: 'Third post',
      media: [],
      linkCard: aLinkCard({ title: CARD_TITLE }),
    },
  ],
});

const LINKED_PAGE = {
  status: 'ok',
  url: 'https://news.test/story',
  title: CARD_TITLE,
  byline: null,
  siteName: 'news.test',
  contentHtml: '<p>Linked page body</p>',
  excerpt: null,
  paywalled: false,
  originalHero: null,
  extractedAt: '2026-08-01T12:50:34+00:00',
};

test.use({ viewport: DESKTOP });

async function stubThreadEntry(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ json: { entries: [ENTRY], nextCursor: null } });
    },
  );
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ json: readerFailedJson('unextractable') }),
  );
  await page.route('**/api/reader?*', (route) => route.fulfill({ json: LINKED_PAGE }));
  await page.route('**/api/entries/*/state', (route) => route.fulfill({ json: {} }));
}

test('a self-reply thread draws on one line with counters and opens its card in the reader', async ({
  page,
}) => {
  await stubThreadEntry(page);
  await page.goto('/');

  await expect(page.locator('app-thread-line-post')).toHaveCount(3);
  await expect(page.locator('app-thread-count').last()).toHaveText('3/3');

  await page.locator('app-thread-line-post app-link-card button.open-in-reader').click();
  await expect(page).toHaveURL(new RegExp(`link=${ENTRY.id}\\.0`));
  await expect(page.locator('app-link-reader')).toContainText(CARD_TITLE);
});
