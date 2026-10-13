import { expect, Page, Route, test } from '@playwright/test';
import { presetLocalStorage } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';

/**
 * #1615: a social post's article ends in a right-aligned row of its live
 * counts, the like last; the article header offers no second thumbs up. Every
 * API read is stubbed, so the spec owns the counts it asserts on.
 */
const POST = entryWire({
  id: 1,
  title: 'A post worth counting',
  social: { network: 'bluesky', isLiked: false, canLike: true },
});

type CountsAnswer = Parameters<Route['fulfill']>[0];

async function stubPost(page: Page, counts: CountsAnswer): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route('**/api/client-errors', (route) => route.fulfill({ status: 204 }));
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [POST], nextCursor: null } }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1',
    (route) =>
      route.request().method() === 'GET'
        ? route.fulfill({ json: entryDetailJson(POST, '<p>The post itself.</p>') })
        : route.fallback(),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/reader',
    (route) => route.fulfill({ json: readerFailedJson() }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/social',
    (route) => route.fulfill(counts),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/like',
    (route) => route.fulfill({ json: { isLiked: route.request().method() === 'PUT' } }),
  );
}

async function openPost(page: Page) {
  await page.setViewportSize({ width: 1280, height: 800 });
  await presetLocalStorage(page, { 'sfr.layout': 'pane' });
  await page.goto('/?subscription=1');
  await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
  const view = page.locator('app-reader-view');
  await expect(view.locator('.content p')).toHaveText('The post itself.');
  return view;
}

test('the counts sit right-aligned below the post, the like last and live', async ({ page }) => {
  await stubPost(page, { json: { likes: 41, reposts: 5, replies: 7, quotes: 2 } });
  const view = await openPost(page);

  const row = view.locator('app-social-counts .row');
  await expect(row.locator('.count .number')).toHaveText(['5', '7', '2']);
  const like = row.locator('button.like');
  await expect(like.locator('.number')).toHaveText('41');
  expect(await row.evaluate((element) => element.lastElementChild?.matches('button.like'))).toBe(
    true,
  );
  const rowBox = (await row.boundingBox())!;
  const likeBox = (await like.boundingBox())!;
  expect(Math.abs(rowBox.x + rowBox.width - (likeBox.x + likeBox.width))).toBeLessThanOrEqual(1);
  await expect(view.locator('app-entry-actions.actions')).not.toContainText('thumb_up');

  await like.click();

  await expect(like.locator('.number')).toHaveText('42');
  await expect(like).toHaveAttribute('aria-pressed', 'true');
});

test('a post whose counts fail keeps its like button and shows no numbers', async ({ page }) => {
  await stubPost(page, {
    status: 502,
    contentType: 'application/problem+json',
    body: JSON.stringify({
      type: 'social_network_unreachable',
      title: 'The network could not be reached',
      status: 502,
    }),
  });
  const view = await openPost(page);

  const like = view.locator('app-social-counts button.like');
  await expect(like).toBeVisible();
  await expect(like.locator('.number')).toHaveCount(0);
  await expect(view.locator('app-social-counts .count')).toHaveCount(0);
});
