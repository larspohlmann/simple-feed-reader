import { test, expect, Page } from '@playwright/test';
import { presetLocalStorage } from './support/auth';
import {
  entryDetailJson,
  entryWire,
  oneFeedJson,
  readerFailedJson,
  stubOneFeedReader,
} from './support/reader';

const DESKTOP = { width: 1280, height: 900 };
const ENTRY = entryWire({ id: 1, title: 'Text size fixture entry' });
const BODY = '<h2>Section</h2><p>Body text of the fixture article.</p>';
const TAG = { id: 1, name: 'Fixture tag', color: null, icon: null, position: 0 };

const SCALED = {
  rowTitle: 'app-entry-row .title',
  rowMeta: 'app-entry-row .meta',
  articleTitle: 'app-reader-view article .title',
  articleBody: 'app-reader-view .content p',
  articleHeading: 'app-reader-view .content h2',
};
const FIXED = {
  listHeaderTitle: 'app-list-header h2',
  listHeaderCount: 'app-list-header .title-count',
  toolbarTitle: 'app-reader-view .bar-title',
  toolbarButton: 'app-reader-view .bar .nav button',
  rowAction: 'app-entry-row app-entry-actions button',
  pill: 'app-entry-row app-entry-pills .pill',
};

async function stubReader(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route('**/api/subscriptions**', (route) =>
    route.fulfill({
      json: oneFeedJson('Fixture feed', { tags: [TAG], unreadCount: 5, includeInAllItems: true }),
    }),
  );
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ json: readerFailedJson('unextractable') }),
  );
  await page.route('**/api/entries/*/state', (route) =>
    route.fulfill({
      json: {
        state: { entryId: 1, isHidden: true, isFavorite: false, isKept: false, hiddenAt: 'x' },
      },
    }),
  );
  await page.route(
    (url) => url.pathname === `/api/entries/${ENTRY.id}`,
    (route) => route.fulfill({ json: entryDetailJson(ENTRY, BODY) }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [ENTRY], nextCursor: null } }),
  );
}

async function openArticle(page: Page): Promise<void> {
  await page.getByText(ENTRY.title).first().click();
  await expect(page.locator(SCALED.articleBody)).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

async function measure(page: Page, selectors: Record<string, string>) {
  const sizes: Record<string, number> = {};
  for (const [name, selector] of Object.entries(selectors)) {
    sizes[name] = await page
      .locator(selector)
      .first()
      .evaluate((element) => parseFloat(getComputedStyle(element).fontSize));
  }
  return sizes;
}

test('a saved text size reaches the root before the app boots', async ({ page }) => {
  await presetLocalStorage(page, { 'sfr.textSize': '130' });
  await page.goto('/login');

  const scale = await page.evaluate(() =>
    document.documentElement.style.getPropertyValue('--text-scale'),
  );
  expect(scale).toBe('1.3');
});

test.describe('on a desktop pane layout', () => {
  test.use({ viewport: DESKTOP });

  test('reading text scales; headers, toolbar, buttons and pills do not', async ({ page }) => {
    await presetLocalStorage(page, { 'sfr.layout': 'pane' });
    await stubReader(page);
    await page.goto('/');
    await openArticle(page);
    const scaledBefore = await measure(page, SCALED);
    const fixedBefore = await measure(page, FIXED);

    await page.evaluate(() => localStorage.setItem('sfr.textSize', '150'));
    await page.reload();
    await openArticle(page);
    const scaledAfter = await measure(page, SCALED);
    const fixedAfter = await measure(page, FIXED);

    for (const name of Object.keys(SCALED)) {
      expect.soft(scaledAfter[name], name).toBeCloseTo(scaledBefore[name] * 1.5, 1);
    }
    for (const name of Object.keys(FIXED)) {
      expect.soft(fixedAfter[name], name).toBe(fixedBefore[name]);
    }
  });

  test('the sidebar stepper changes the scale live', async ({ page }) => {
    await stubReader(page);
    await page.goto('/');
    await page.getByRole('button', { name: 'Larger text' }).click();

    await expect
      .poll(() =>
        page.evaluate(() => document.documentElement.style.getPropertyValue('--text-scale')),
      )
      .toBe('1.1');
  });
});
