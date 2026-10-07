import { expect, Page, test } from '@playwright/test';
import { presetLocalStorage } from './support/auth';
import { entryDetailJson, entryWire, stubOneFeedReader } from './support/reader';

/**
 * #1429: episodes added from their article views play in the order the
 * playlist is rearranged into. Every API read is stubbed, and the enclosures
 * point at an unreachable host: the bar's title follows the playlist whether or
 * not a byte of audio ever plays.
 */
const EPISODES = [1, 2, 3].map((id) =>
  entryWire({
    id,
    title: `Episode ${id}`,
    attachments: [{ url: `https://fixtures.invalid/episode-${id}.mp3`, mimeType: 'audio/mpeg' }],
  }),
);

async function stubEpisodes(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture podcast');
  await page.route('https://fixtures.invalid/**', (route) => route.abort());
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: EPISODES, nextCursor: null } }),
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+$/.test(url.pathname),
    (route) => {
      const id = Number(new URL(route.request().url()).pathname.split('/').pop());
      const episode = EPISODES.find((candidate) => candidate.id === id);
      return episode
        ? route.fulfill({ json: entryDetailJson(episode, '<p>Show notes.</p>') })
        : route.fallback();
    },
  );
}

async function addFromArticle(page: Page, title: string): Promise<void> {
  await page.locator('app-entry-list').getByText(title, { exact: true }).click();
  const toggle = page.locator('app-reader-view .listen.queue');
  await expect(toggle).toHaveText(/Add to playlist/);
  await toggle.click();
  await expect(toggle).toHaveText(/In playlist/);
}

test('episodes added from their articles play in the reordered order', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await presetLocalStorage(page, { 'sfr.layout': 'pane' });
  await stubEpisodes(page);
  await page.goto('/?subscription=1');

  for (const episode of EPISODES) await addFromArticle(page, episode.title);

  const bar = page.locator('app-audio-player-bar');
  await expect(bar.locator('.controls .title')).toHaveText('Episode 1');
  await bar.getByRole('button', { name: 'Playlist' }).click();
  const rows = bar.locator('.row .row-title');
  await expect(rows).toHaveText(['Episode 1', 'Episode 2', 'Episode 3']);

  await bar.getByRole('button', { name: 'Move Episode 3 up' }).click();
  await expect(rows).toHaveText(['Episode 1', 'Episode 3', 'Episode 2']);

  await bar.getByRole('button', { name: 'Next track' }).click();
  await expect(bar.locator('.controls .title')).toHaveText('Episode 3');
  await bar.getByRole('button', { name: 'Next track' }).click();
  await expect(bar.locator('.controls .title')).toHaveText('Episode 2');
  await expect(bar.getByRole('button', { name: 'Next track' })).toBeDisabled();
});
