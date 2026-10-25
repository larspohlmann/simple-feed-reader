import { expect, Page, test } from '@playwright/test';
import { presetLocalStorage } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';
import { silentWav } from './support/audio';

/** #1808: chapters under a video and atop an episode. Every API read is stubbed; the spec owns its data. */
const CHAPTERS = [
  { startSeconds: 0, title: 'Opening' },
  { startSeconds: 20, title: 'Middle' },
  { startSeconds: 40, title: 'Ending' },
];

const VIDEO = entryWire({
  id: 1,
  title: 'A video',
  url: 'https://www.youtube.com/watch?v=JsBZOcqZerk',
});
const EPISODE = entryWire({
  id: 2,
  title: 'An episode',
  url: 'https://fixtures.invalid/episode',
  attachments: [{ url: 'https://fixtures.invalid/episode.mp3', mimeType: 'audio/mpeg' }],
});
const VIDEO_BODY =
  '<p><a href="https://www.youtube-nocookie.com/embed/JsBZOcqZerk">Watch on YouTube</a></p><p>Thanks</p>';

async function stubArticles(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route('https://www.youtube-nocookie.com/**', (route) =>
    route.fulfill({ body: '<!doctype html><title>player</title>', contentType: 'text/html' }),
  );
  const audio = silentWav(60);
  await page.route('https://fixtures.invalid/**', (route) => {
    const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers()['range'] ?? '');
    const start = range ? Number(range[1]) : 0;
    const end = range && range[2] !== '' ? Number(range[2]) : audio.length - 1;
    return route.fulfill({
      status: range ? 206 : 200,
      body: audio.subarray(start, end + 1),
      headers: {
        'content-type': 'audio/wav',
        'accept-ranges': 'bytes',
        ...(range ? { 'content-range': `bytes ${start}-${end}/${audio.length}` } : {}),
      },
    });
  });
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [VIDEO, EPISODE], nextCursor: null } }),
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+\/reader$/.test(url.pathname),
    (route) => route.fulfill({ json: readerFailedJson('unextractable') }),
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+$/.test(url.pathname),
    (route) => {
      const id = Number(new URL(route.request().url()).pathname.split('/').pop());
      if (id === VIDEO.id)
        return route.fulfill({ json: entryDetailJson(VIDEO, VIDEO_BODY, CHAPTERS) });
      if (id === EPISODE.id)
        return route.fulfill({ json: entryDetailJson(EPISODE, '<p>Show notes.</p>', CHAPTERS) });
      return route.fallback();
    },
  );
}

test.beforeEach(async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await presetLocalStorage(page, { 'sfr.layout': 'pane' });
  await stubArticles(page);
  await page.goto('/?subscription=1');
});

test('a video lists its chapters under an API-enabled player', async ({ page }) => {
  await page.locator('app-entry-list').getByText('A video', { exact: true }).click();

  const article = page.locator('app-article-content');
  await expect(article.locator('iframe')).toHaveAttribute('src', /\?enablejsapi=1&origin=/);
  await expect(article.locator('.reader-chapters__list')).toBeHidden();
  await article.getByRole('button', { name: 'Chapters', exact: true }).click();
  await expect(article.locator('.reader-chapters__row')).toHaveText([
    '0:00Opening',
    '0:20Middle',
    '0:40Ending',
  ]);
  await expect(article.locator('.reader-cinema + .reader-chapters')).toHaveCount(1);

  await article.locator('.reader-chapters__row').nth(1).click();
  await expect(article.locator('.reader-chapters__row').nth(1)).toHaveAttribute(
    'aria-current',
    'true',
  );
});

test('an episode starts at a tapped chapter and the list follows it', async ({ page }) => {
  await page.locator('app-entry-list').getByText('An episode', { exact: true }).click();

  await page
    .locator('app-article-content')
    .getByRole('button', { name: 'Chapters', exact: true })
    .click();
  const rows = page.locator('app-article-content .reader-chapters__row');
  await expect(rows).toHaveCount(3);
  await rows.nth(1).click();
  await expect(rows.nth(1)).toHaveAttribute('aria-current', 'true');

  // The highlight follows the player's position: had the seek failed, playback from 0 would move it back to
  // "Opening" within a second, so holding "Middle" past that proves the episode started at 0:20.
  await page.waitForTimeout(1500);
  await expect(rows.nth(1)).toHaveAttribute('aria-current', 'true');
  await expect(rows.nth(0)).not.toHaveAttribute('aria-current');
});
