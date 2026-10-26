import { expect, Page, test } from '@playwright/test';
import { presetLocalStorage } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';

/**
 * #1815: Listen under an embedded video sends it to the audio player. Every API read is stubbed; the
 * provider frame is a stub page that answers no message, so the smoke asserts on the bars, not on playback.
 */
const VIDEO = entryWire({
  id: 1,
  title: 'A video to listen to',
  url: 'https://www.youtube.com/watch?v=JsBZOcqZerk',
});
const VIDEO_BODY =
  '<p><a href="https://www.youtube-nocookie.com/embed/JsBZOcqZerk">Watch on YouTube</a></p><p>Thanks</p>';

async function stubVideoArticle(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route('https://www.youtube-nocookie.com/**', (route) =>
    route.fulfill({ body: '<!doctype html><title>player</title>', contentType: 'text/html' }),
  );
  await page.route('https://i.ytimg.com/**', (route) => route.fulfill({ status: 404 }));
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [VIDEO], nextCursor: null } }),
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+\/reader$/.test(url.pathname),
    (route) => route.fulfill({ json: readerFailedJson('unextractable') }),
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+$/.test(url.pathname),
    (route) => route.fulfill({ json: entryDetailJson(VIDEO, VIDEO_BODY) }),
  );
}

test.beforeEach(async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await presetLocalStorage(page, { 'sfr.layout': 'pane' });
  await stubVideoArticle(page);
  await page.goto('/?subscription=1');
});

test('Listen under a video fills the audio player bar and queues the video', async ({ page }) => {
  await page.locator('app-entry-list').getByText('A video to listen to', { exact: true }).click();

  const playerBar = page.locator('app-article-content .reader-player-bar');
  await expect(playerBar).toHaveCount(1);
  const listen = playerBar.getByRole('button', { name: 'Listen', exact: true });
  const queueToggle = playerBar.getByRole('button', { name: 'Add to playlist', exact: true });
  await expect(listen).toBeVisible();
  await expect(queueToggle).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('app-audio-player-bar .controls')).toHaveCount(0);

  await listen.click();

  const audioBar = page.locator('app-audio-player-bar');
  await expect(audioBar.locator('.controls .title')).toHaveText('A video to listen to');
  await expect(audioBar.getByRole('button', { name: 'Playlist (1)' })).toBeVisible();
  await expect(playerBar.getByRole('button', { name: 'In playlist', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
});
