import { expect, Page, test } from '@playwright/test';
import { MIN_LIST_PERCENT } from '../src/app/reader/pane-split';
import { stubOneFeedReader } from './support/reader';

// The sidebar column plus a split main area: `sfr.paneSplit` sets the list column's share.
const DESKTOP = { width: 1280, height: 800 };

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
  await stubOneFeedReader(page, 'Design feeds with a rather long title');
  await page.addInitScript(
    ([layout, split]) => {
      localStorage.setItem('sfr.layout', layout);
      localStorage.setItem('sfr.paneSplit', split);
    },
    [list.layout, paneSplit],
  );

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
    for (const paneSplit of [String(MIN_LIST_PERCENT), '35', '45', '60']) {
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

// Split mode starts at 900px, where the percent floor alone leaves the column too narrow
// for a feed's five compact actions; the column's own length floor must hold them (#1143).
for (const width of [900, 1024]) {
  test.describe(`list header at the split floor in a ${width}px window`, () => {
    test.use({ viewport: { width, height: 800 } });

    for (const list of [ALL_ITEMS, FEED]) {
      test(`${list.name} keeps the title readable and the tools inside the column`, async ({
        page,
      }) => {
        await openSplit(page, list, String(MIN_LIST_PERCENT));

        const geometry = await headerGeometry(page);
        expect(geometry.headingWidth).toBeGreaterThanOrEqual(TITLE_FLOOR_PX);
        expect(geometry.toolsRight).toBeCloseTo(geometry.contentRight, 0);
      });
    }
  });
}
