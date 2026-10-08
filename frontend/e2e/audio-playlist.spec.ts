import { expect, Page, test } from '@playwright/test';
import { presetLocalStorage } from './support/auth';
import { entryDetailJson, entryWire, stubOneFeedReader } from './support/reader';
import { silentWav } from './support/audio';

/**
 * #1429: episodes added from their article views play in the order the
 * playlist is rearranged into. Every API read is stubbed. Each enclosure is a
 * minute of real silence: a dead one would be skipped as a failed track.
 */
const EPISODES = [1, 2, 3].map((id) =>
  entryWire({
    id,
    title: `Episode ${id}`,
    attachments: [{ url: `https://fixtures.invalid/episode-${id}.mp3`, mimeType: 'audio/mpeg' }],
  }),
);

/** Stubs the API and serves the enclosures; returns the episode files requested so far. */
async function stubEpisodes(page: Page): Promise<Set<string>> {
  await stubOneFeedReader(page, 'Fixture podcast');
  const audio = silentWav(60);
  const requested = new Set<string>();
  await page.route('https://fixtures.invalid/**', (route) => {
    requested.add(new URL(route.request().url()).pathname);
    return route.fulfill({ body: audio, contentType: 'audio/wav' });
  });
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
  return requested;
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
  const requested = await stubEpisodes(page);
  await page.goto('/?subscription=1');

  for (const episode of EPISODES) await addFromArticle(page, episode.title);

  const bar = page.locator('app-audio-player-bar');
  await expect(bar.locator('.controls .title')).toHaveText('Episode 1');
  await bar.getByRole('button', { name: 'Playlist (3)' }).click();
  const rows = bar.locator('.row .row-title');
  await expect(rows).toHaveText(['Episode 1', 'Episode 2', 'Episode 3']);

  await bar.getByRole('button', { name: 'Move Episode 3 up' }).click();
  await expect(rows).toHaveText(['Episode 1', 'Episode 3', 'Episode 2']);

  // A minute-long episode is within the pre-cache lead at once, so the next one is fetched before Next.
  await expect.poll(() => requested.has('/episode-3.mp3')).toBe(true);
  await bar.getByRole('button', { name: 'Next track' }).click();
  await expect(bar.locator('.controls .title')).toHaveText('Episode 3');
  await expect(bar.getByRole('button', { name: 'Pause' })).toBeVisible();
  await bar.getByRole('button', { name: 'Next track' }).click();
  await expect(bar.locator('.controls .title')).toHaveText('Episode 2');
  await expect(bar.getByRole('button', { name: 'Next track' })).toBeDisabled();
});

for (const layout of ['magazine', 'list'] as const) {
  test(`episodes play and queue from their ${layout} rows without opening them (#1436)`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await presetLocalStorage(page, { 'sfr.layout': layout });
    await stubEpisodes(page);
    await page.goto('/?subscription=1');
    const row = (title: string) =>
      page.locator('app-entry-list article[role="button"]', { hasText: title });

    await row('Episode 2').getByRole('button', { name: 'Add to playlist', exact: true }).click();
    await expect(
      row('Episode 2').getByRole('button', { name: 'In playlist', exact: true }),
    ).toBeVisible();
    await row('Episode 3').getByRole('button', { name: 'Play', exact: true }).click();

    const bar = page.locator('app-audio-player-bar');
    await expect(bar.locator('.controls .title')).toHaveText('Episode 3');
    await expect(bar.getByRole('button', { name: 'Playlist (2)' })).toBeVisible();
    await expect(
      row('Episode 3').getByRole('button', { name: 'Pause', exact: true }),
    ).toBeVisible();
    await expect(page).toHaveURL(/\?subscription=1$/);
    await expect(page.locator('app-reader-view')).toHaveCount(0);
  });
}

test('the artwork opens the big player, which trades places with the playlist (#1442)', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await presetLocalStorage(page, { 'sfr.layout': 'list' });
  await stubEpisodes(page);
  await page.goto('/?subscription=1');
  const row = (title: string) =>
    page.locator('app-entry-list article[role="button"]', { hasText: title });

  await row('Episode 2').getByRole('button', { name: 'Play', exact: true }).click();
  await row('Episode 3').getByRole('button', { name: 'Add to playlist', exact: true }).click();

  const bar = page.locator('app-audio-player-bar');
  const player = page.getByRole('dialog');
  await bar.getByRole('button', { name: 'Open player', exact: true }).click();
  await expect(player.getByRole('heading', { name: 'Episode 2' })).toBeVisible();
  await expect(player.getByText('1 of 2', { exact: true })).toBeVisible();
  await expect(player.getByText(/^Fixture feed · /)).toBeVisible();

  await player.getByRole('button', { name: 'Playback speed 1×', exact: true }).click();
  await expect(
    player.getByRole('button', { name: 'Playback speed 1.25×', exact: true }),
  ).toBeVisible();

  await player.getByRole('button', { name: 'Playlist (2)', exact: true }).click();
  await expect(player).toHaveCount(0);
  await expect(bar.locator('.row .row-title')).toHaveText(['Episode 2', 'Episode 3']);

  await bar.getByRole('button', { name: 'Open player', exact: true }).click();
  await expect(bar.locator('.row')).toHaveCount(0);
  await player.getByRole('button', { name: 'Open article', exact: true }).click();
  await expect(player).toHaveCount(0);
  await expect(page).toHaveURL(/[?&]entry=2-episode-2/);
  await expect(page.locator('app-reader-view')).toBeVisible();
});
