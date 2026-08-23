import { Page, expect, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';

// #572: on a phone the feed intro block stacked the feed's icon on its own row
// and pushed the title and blurb underneath it. The block's own rule is "two
// rows, never more" — it must not push the first entry off the fold — and a
// 24px favicon sits beside text comfortably at any phone width.
//
// Both branches are covered: the app-favicon fallback, which is what ~92% of
// feeds render, and a feed publishing its own wider image.

/** A 1x1 transparent GIF, so nothing leaves the page. app-favicon sizes itself,
 *  so the intrinsic size does not matter for the icon branch. */
const BLANK = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/** A feed's own image sizes from its intrinsics, so this one needs real
 *  dimensions — a 1x1 would render 1px wide and prove nothing about the row. */
const WIDE_LOGO =
  'data:image/svg+xml;base64,' +
  Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="60"><rect width="240" height="60" fill="#c33"/></svg>',
  ).toString('base64');

const DESCRIPTION = 'In-depth news, politics, business, technology & culture';

function subscription(overrides: Record<string, unknown>) {
  return {
    subscriptions: [
      {
        id: 1,
        feedId: 1,
        title: 'Salon.com',
        faviconUrl: null,
        imageUrl: null,
        description: DESCRIPTION,
        customTitle: null,
        feedUrl: 'https://www.salon.com/feed',
        siteUrl: 'https://www.salon.com',
        status: 'active',
        sourceFormat: 'xml',
        createdAt: '2026-01-01T00:00:00+00:00',
        lastFetchedAt: '2026-01-01T00:00:00+00:00',
        position: 0,
        tags: [],
        unreadCount: 0,
        ...overrides,
      },
    ],
    favoritesCount: 0,
    keptCount: 0,
    viewedCount: 0,
  };
}

async function openFeed(page: Page, overrides: Record<string, unknown>): Promise<void> {
  await stubAuthToken(page);
  const json = (body: unknown) => (route: { fulfill: (r: { json: unknown }) => unknown }) =>
    route.fulfill({ json: body });

  await page.route('**/api/subscriptions**', json(subscription(overrides)));
  await page.route('**/api/tags**', json({ tags: [] }));
  await page.route('**/api/entries**', json({ entries: [], total: 0, page: 1, perPage: 20 }));
  await page.route('**/api/me**', json({ id: 1, email: '', roles: [], preferences: {} }));
  await page.route('**/api/version**', json({ version: 'dev' }));
  await page.route('**/api/recommendations/**', json({ run: null }));

  await page.goto('/?subscription=1');
  await page.locator('app-feed-intro').waitFor();
}

/**
 * Geometry of the image against the TEXT, measured with a Range.
 *
 * The Range gives the rect of the glyphs rather than of their block, which is
 * what actually moves when the layout changes — and it keeps these assertions
 * honest against a float, where a block beside one keeps a full-width border
 * box and only its line boxes shorten.
 */
async function measure(page: Page, imageSelector: string) {
  return page.evaluate((selector) => {
    const intro = document.querySelector('app-feed-intro') as HTMLElement;
    const image = intro.querySelector(selector) as HTMLElement;
    const lineRects = (element: Element) => {
      const range = document.createRange();
      range.selectNodeContents(element);
      return [...range.getClientRects()].filter((r) => r.width > 0);
    };
    const titleLines = lineRects(intro.querySelector('.title')!);
    const textLines = lineRects(intro.querySelector('.text')!);
    const i = image.getBoundingClientRect();
    return {
      imageWidth: Math.round(i.width),
      imageHeight: Math.round(i.height),
      imageRight: Math.round(i.right),
      imageBottom: Math.round(i.bottom),
      titleFirstLeft: Math.round(titleLines[0].left),
      titleFirstTop: Math.round(titleLines[0].top),
      lastTextLeft: Math.round(textLines[textLines.length - 1].left),
      lastTextTop: Math.round(textLines[textLines.length - 1].top),
    };
  }, imageSelector);
}

test.use({ viewport: { width: 390, height: 720 } });

test('the site icon stays beside the title on a phone', async ({ page }) => {
  await openFeed(page, { faviconUrl: BLANK });
  const box = await measure(page, 'app-favicon');

  // The icon must actually occupy the row — a collapsed box would satisfy
  // "left of the title" for free and make the assertion meaningless.
  expect(box.imageWidth).toBeGreaterThanOrEqual(8);
  expect(box.imageHeight).toBeGreaterThanOrEqual(8);

  // Title beside the icon, not under it.
  expect(box.titleFirstLeft).toBeGreaterThanOrEqual(box.imageRight);
  expect(box.titleFirstTop).toBeLessThan(box.imageBottom);

  // The blurb still reads in full; it wraps rather than being pushed out.
  await expect(page.locator('app-feed-intro .description')).toHaveText(DESCRIPTION);
});

test("a feed's own image keeps the text in its own column on a phone", async ({ page }) => {
  await openFeed(page, { imageUrl: WIDE_LOGO });
  const box = await measure(page, 'img.logo');

  expect(box.imageWidth).toBeGreaterThanOrEqual(8);

  // Beside the image, not under it.
  expect(box.titleFirstLeft).toBeGreaterThanOrEqual(box.imageRight);
  expect(box.titleFirstTop).toBeLessThan(box.imageBottom);

  // EVERY line stays in the column: the last one starts at the same left edge
  // as the first. A float would have let it run back under the image, which
  // reads badly when the blurb is short.
  expect(box.lastTextLeft).toBeGreaterThanOrEqual(box.imageRight);

  // And the column is still wide enough to read.
  const columnWidth = await page
    .locator('app-feed-intro .body')
    .evaluate((element) => element.getBoundingClientRect().width);
  expect(columnWidth).toBeGreaterThan(200);
});
