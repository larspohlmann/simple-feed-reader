import { expect, Page, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';
import { savedSearchesJson } from './support/reader';

// The sidebar column plus a split main area: `sfr.paneSplit` sets the list column's share.
const DESKTOP = { width: 1280, height: 800 };

const SUBSCRIPTIONS = {
  subscriptions: [
    {
      id: 1,
      feedId: 1,
      title: 'Design feeds with a rather long title',
      faviconUrl: null,
      imageUrl: null,
      description: null,
      customTitle: null,
      feedUrl: 'https://fixtures.invalid/feed.xml',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: '2026-08-28T00:00:00+00:00',
      lastFetchedAt: '2026-08-28T00:00:00+00:00',
      position: 0,
      tags: [],
      unreadCount: 1,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
  viewedCount: 0,
};

interface SplitList {
  name: string;
  url: string;
  layout: string;
}

const ALL_ITEMS: SplitList = { name: 'All items', url: '/', layout: 'pane' };
const FEED: SplitList = { name: 'a feed', url: '/?subscription=1', layout: 'pane' };
const DIRECT_SEARCH: SplitList = {
  name: 'a direct search',
  url: '/?q=hamburg',
  layout: 'magazine',
};

// The `.heading` flex basis in entry-list.component.scss: 6rem at the 16px root.
const TITLE_FLOOR_PX = 96;

interface HeaderGeometry {
  headingWidth: number;
  toolsRight: number;
  contentRight: number;
}

async function headerGeometry(page: Page): Promise<HeaderGeometry> {
  return page.locator('.list-header').evaluate((header) => {
    const part = (selector: string): DOMRect => {
      const element = header.querySelector(selector);
      if (!element) throw new Error(`list header has no ${selector}`);
      return element.getBoundingClientRect();
    };
    return {
      headingWidth: part('.heading').width,
      toolsRight: part('.tools').right,
      contentRight:
        header.getBoundingClientRect().right - parseFloat(getComputedStyle(header).paddingRight),
    };
  });
}

async function expectEveryAction(page: Page, form: { labelled: boolean }): Promise<void> {
  const actions = page.locator('.list-header .list-action');
  expect(await actions.count()).toBeGreaterThan(1);
  for (const action of await actions.all()) {
    const label = action.locator('.txt');
    await (form.labelled ? expect(label).toBeVisible() : expect(label).toBeHidden());
    await expect(action.locator('app-icon')).toHaveCSS(
      'border-top-width',
      form.labelled ? '0px' : '1px',
    );
  }
}

async function openSplit(page: Page, list: SplitList, paneSplit: string): Promise<void> {
  await stubAuthToken(page);
  await page.addInitScript(
    ([layout, split]) => {
      localStorage.setItem('sfr.layout', layout);
      localStorage.setItem('sfr.paneSplit', split);
    },
    [list.layout, paneSplit],
  );
  const json = (body: unknown) => (route: { fulfill: (response: { json: unknown }) => unknown }) =>
    route.fulfill({ json: body });

  await page.route('**/api/subscriptions**', json(SUBSCRIPTIONS));
  await page.route('**/api/tags**', json({ tags: [] }));
  await page.route('**/api/entries**', json({ entries: [], nextCursor: null }));
  await page.route('**/api/me**', json({ id: 1, email: '', roles: [], preferences: {} }));
  await page.route('**/api/version**', json({ version: 'dev' }));
  await page.route('**/api/recommendations/**', json({ run: null }));
  await page.route('**/api/saved-searches**', json(savedSearchesJson()));

  await page.goto(list.url);
  await expect(page.locator('.list-header')).toBeVisible();
}

test.describe('list header in a narrow split column (#1127)', () => {
  test.use({ viewport: DESKTOP });

  for (const list of [ALL_ITEMS, FEED, DIRECT_SEARCH]) {
    test(`every action of ${list.name} is icon-only in the compact form`, async ({ page }) => {
      await openSplit(page, list, '45');
      await expectEveryAction(page, { labelled: false });
    });

    test(`every action of ${list.name} is labelled in the wide form`, async ({ page }) => {
      await openSplit(page, list, '60');
      await expectEveryAction(page, { labelled: true });
    });
  }

  for (const list of [ALL_ITEMS, FEED, DIRECT_SEARCH]) {
    for (const paneSplit of ['25.6', '35', '45', '60']) {
      test(`${list.name} at split ${paneSplit} keeps the title readable and the tools inside the column`, async ({
        page,
      }) => {
        await openSplit(page, list, paneSplit);

        const geometry = await headerGeometry(page);
        expect(geometry.headingWidth).toBeGreaterThanOrEqual(TITLE_FLOOR_PX);
        expect(geometry.toolsRight).toBeCloseTo(geometry.contentRight, 0);
      });
    }
  }
});
