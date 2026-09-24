import { expect, Page, Request, test } from '@playwright/test';
import { signInAsAdmin } from './support/auth';
import { entryWire, oneFeedJson } from './support/reader';

const TAG = { id: 7101, name: 'Order fixture', color: null, icon: null, position: 0 };
const ENTRY_COUNT = 40;

/** Entry n is published on day n, so ascending id is ascending date. */
function entry(id: number) {
  const day = new Date(Date.UTC(2026, 6, 1) + id * 86_400_000).toISOString();
  return entryWire({
    id,
    title: `Order fixture entry ${id}`,
    publishedAt: day,
    createdAt: day,
    subscriptionId: 7102,
    source: 'Order fixture feed',
  });
}

const OLDEST_FIRST = Array.from({ length: ENTRY_COUNT }, (_, i) => entry(i + 1));
const NEWEST_FIRST = [...OLDEST_FIRST].reverse();

const SUBSCRIPTIONS = oneFeedJson('Order fixture feed', {
  id: 7102,
  feedId: 7103,
  tags: [TAG],
  unreadCount: ENTRY_COUNT,
});

interface Recorded {
  markedIds: number[][];
}

async function stubReaderData(page: Page): Promise<Recorded> {
  const recorded: Recorded = { markedIds: [] };
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const url = new URL(route.request().url());
      const entries = url.searchParams.get('order') === 'asc' ? OLDEST_FIRST : NEWEST_FIRST;
      await route.fulfill({ status: 200, json: { entries, nextCursor: null } });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/entries/mark-read-batch',
    async (route) => {
      recorded.markedIds.push((route.request().postDataJSON() as { ids: number[] }).ids);
      await route.fulfill({ status: 204 });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/subscriptions',
    (route) => route.fulfill({ status: 200, json: SUBSCRIPTIONS }),
  );
  await page.route(
    (url) => url.pathname === '/api/tags',
    (route) => route.fulfill({ status: 200, json: { tags: [TAG] } }),
  );
  return recorded;
}

interface ListQuery {
  tag: string | null;
  order: string | null;
}

/** Starts listening before `action`, so the list load it triggers cannot slip past. */
async function listLoadedBy(page: Page, query: ListQuery, action: () => Promise<unknown>) {
  const matches = (request: Request) => {
    const url = new URL(request.url());
    return (
      url.pathname === '/api/entries' &&
      request.method() === 'GET' &&
      url.searchParams.get('tag') === query.tag &&
      url.searchParams.get('order') === query.order
    );
  };
  await Promise.all([page.waitForRequest(matches), action()]);
}

const TAG_NEWEST = { tag: String(TAG.id), order: null };
const TAG_OLDEST = { tag: String(TAG.id), order: 'asc' };
const ALL_NEWEST = { tag: null, order: null };

const orderLabel = (page: Page) => page.locator('.list-header .list-order .txt');
const firstRowId = (page: Page) =>
  page.locator('app-entry-list [data-entry-id]').first().getAttribute('data-entry-id');

async function flipToOldestFirst(page: Page): Promise<void> {
  await listLoadedBy(page, TAG_OLDEST, () => page.locator('.list-header .list-order').click());
  await expect(orderLabel(page)).toHaveText('Oldest first');
  await expect.poll(() => firstRowId(page)).toBe('1');
}

test.describe('list order (#1143)', () => {
  test('a flipped list loads oldest first, stays so after a reload, and only for that list', async ({
    page,
  }) => {
    await stubReaderData(page);
    test.skip(!(await signInAsAdmin(page)), 'seeded admin login unavailable');

    await listLoadedBy(page, TAG_NEWEST, () => page.goto(`/?tag=${TAG.id}`));
    await expect(orderLabel(page)).toHaveText('Newest first');
    await expect.poll(() => firstRowId(page)).toBe(String(ENTRY_COUNT));

    await flipToOldestFirst(page);

    await listLoadedBy(page, TAG_OLDEST, () => page.reload());
    await expect(orderLabel(page)).toHaveText('Oldest first');
    await expect.poll(() => firstRowId(page)).toBe('1');

    await listLoadedBy(page, ALL_NEWEST, () => page.goto('/'));
    await expect(orderLabel(page)).toHaveText('Newest first');
    await expect.poll(() => firstRowId(page)).toBe(String(ENTRY_COUNT));
  });

  test('mark everything above as read marks the older rows in an oldest-first list', async ({
    page,
  }) => {
    const recorded = await stubReaderData(page);
    test.skip(!(await signInAsAdmin(page)), 'seeded admin login unavailable');

    await listLoadedBy(page, TAG_NEWEST, () => page.goto(`/?tag=${TAG.id}`));
    await expect.poll(() => firstRowId(page)).toBe(String(ENTRY_COUNT));
    await flipToOldestFirst(page);

    await page
      .locator('app-entry-list .rows')
      .first()
      .evaluate((el) => el.scrollTo({ top: 1500 }));
    await page.locator('.mark-above').click();
    await page.locator('[data-testid=confirm]').click();

    await expect.poll(() => recorded.markedIds.length).toBe(1);
    const marked = recorded.markedIds[0];
    expect(marked).toContain(1);
    expect(Math.max(...marked)).toBeLessThan(ENTRY_COUNT / 2);
  });
});
