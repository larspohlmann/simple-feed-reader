import { expect, Page, Request, test } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryWire } from './support/reader';
import type { EntryDto } from '../src/app/reader/models';
import type { TrendingResponseDto } from '../src/app/reader/trending/trending-page';
import type { TrendingWindow } from '../src/app/reader/trending/trending.models';

/**
 * Stubs `GET /api/trending` and `POST /api/trending/hidden` so the spec owns every story it asserts on
 * (#2017): a fresh database has no snapshot, and the seeded account's real one changes every few minutes.
 * No card is opened and no hide reaches the server, so no state on the shared stack moves.
 */
const outlet = (id: number, source: string): EntryDto =>
  entryWire({ id, source, subscriptionId: id });

const story = (
  lead: Pick<EntryDto, 'id' | 'title'>,
  duplicates: EntryDto[],
  outletCount: number,
  viewed: boolean,
) => entryWire({ ...lead, source: 'Fixture lead', duplicates, story: { outletCount, viewed } });

const DAY: TrendingResponseDto = {
  window: 'day',
  builtAt: '2026-11-04T08:00:00Z',
  stories: [
    story(
      { id: 9001, title: 'Trending fixture day story' },
      [9002, 9003, 9004, 9005, 9006, 9007].map((id) => outlet(id, `Outlet ${id}`)),
      7,
      false,
    ),
    story(
      { id: 9011, title: 'Trending fixture opened story' },
      [outlet(9012, 'Outlet 9012')],
      3,
      true,
    ),
  ],
};

const WEEK: TrendingResponseDto = {
  window: 'week',
  builtAt: '2026-11-04T08:00:00Z',
  stories: [
    story(
      { id: 9021, title: 'Trending fixture week story' },
      [outlet(9022, 'Outlet 9022')],
      5,
      false,
    ),
  ],
};

async function stubTrending(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/trending',
    async (route) => {
      const window = new URL(route.request().url()).searchParams.get('window') as TrendingWindow;
      await route.fulfill({ status: 200, json: window === 'week' ? WEEK : DAY });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/trending/hidden',
    (route) => route.fulfill({ status: 204 }),
  );
}

const trendingRequest = (window: TrendingWindow) => (request: Request) => {
  const url = new URL(request.url());
  return url.pathname === '/api/trending' && url.searchParams.get('window') === window;
};

const hideRequest = (request: Request) =>
  new URL(request.url()).pathname === '/api/trending/hidden' && request.method() === 'POST';

const slotOf = (page: Page, id: number) =>
  page.locator('.row-slot', { has: page.locator(`[data-entry-id="${id}"]`) });

for (const layout of ['magazine', 'list'] as const) {
  test(`Trending lists stories, caps the footer, hides a story and switches the window (${layout})`, async ({
    page,
  }) => {
    await stubTrending(page);
    test.skip(
      !(await signInWithLayout(page, layout)),
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    const dayLoaded = page.waitForRequest(trendingRequest('day'));
    await page
      .getByRole('navigation', { name: 'Feeds' })
      .getByRole('link', { name: /^Trending\b/ })
      .click();
    await dayLoaded;

    const story = slotOf(page, 9001);
    await expect(story.getByText('Trending fixture day story')).toBeVisible();
    await expect(story.locator('.sources')).toHaveText('7 sources');
    await expect(story.locator('.also-foot .lead')).toHaveText('Also covered by');
    await expect(story.locator('.also-entry')).toHaveCount(4);

    await story.locator('.also-more').click();
    await expect(story.locator('.also-entry')).toHaveCount(6);
    expect(new URL(page.url()).searchParams.get('entry')).toBeNull();

    const opened = slotOf(page, 9011);
    await expect(opened.locator('.dot')).not.toHaveClass(/\bon\b/);
    await expect(story.locator('.dot')).toHaveClass(/\bon\b/);

    const header = page.locator('app-list-header').first();
    await expect(header.locator('.unread-switch')).toHaveCount(0);
    await expect(header.locator('.mark-all')).toHaveCount(0);

    const hidden = page.waitForRequest(hideRequest);
    await opened.hover();
    await opened.getByRole('button', { name: 'Hide story', exact: true }).click();
    expect((await hidden).postDataJSON()).toEqual({ window: 'day', entryId: 9011 });
    await expect(page.getByText('Trending fixture opened story')).toHaveCount(0);
    await expect(story.getByText('Trending fixture day story')).toBeVisible();

    const weekLoaded = page.waitForRequest(trendingRequest('week'));
    await header.getByRole('link', { name: '7 days' }).click();
    await weekLoaded;
    await expect(page.getByText('Trending fixture week story')).toBeVisible();
    expect(new URL(page.url()).searchParams.get('window')).toBe('week');
    await expect(header.getByRole('link', { name: '7 days' })).toHaveAttribute(
      'aria-current',
      'true',
    );
    await expect(header.getByRole('link', { name: '24 h' })).not.toHaveAttribute('aria-current');
  });
}
