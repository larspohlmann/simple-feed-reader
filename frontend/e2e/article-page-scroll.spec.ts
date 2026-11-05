import { test, expect, Locator, Page } from '@playwright/test';
import type { ReadingLayout } from '../src/app/reader/reading-layout.service';
import { aLinkCard } from '../src/testing/entry-testing';
import { presetLocalStorage } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';
import {
  liftTouch,
  pageScrollY,
  rowTitleInView,
  scrollPageTo,
  scrollPageToEnd,
  touchDrag,
} from './support/scroll';

// A full-screen article takes the page from the list and hands it back (#2038): the browser
// bar can collapse while reading, and back lands the list on its exact offset. Every read the
// reader makes is stubbed, so the spec owns what it scrolls.
const PHONE = { width: 375, height: 812 };
const WIDE = { width: 1440, height: 900 };
/** Under the 900px wide breakpoint, over the 720px drawer one: full screen beside the sidebar. */
const MEDIUM = { width: 820, height: 900 };
const LIST_OFFSET = 1200;
const ARTICLE_OFFSET = 600;
const LAYER = 'app-reader-view app-article-layer';
const LAST_PARAGRAPH = 'Paragraph 39 of filler text';

const TALL_BODY = Array.from(
  { length: 40 },
  (_, i) =>
    `<p>Paragraph ${i} of filler text, long enough that the article runs past a screen.</p>`,
).join('');

const ENTRIES = Array.from({ length: 40 }, (_, i) =>
  entryWire({
    id: i + 1,
    title: `Entry number ${i + 1}`,
    subscriptionId: 1,
    summary: 'A summary long enough to give the row some height. '.repeat(3),
    excerpt: 'A summary long enough to give the row some height. '.repeat(3),
    share: 'link',
    linkCards: [aLinkCard({ title: 'The linked story' })],
  }),
);

const LINKED_PAGE = {
  status: 'ok',
  url: 'https://news.test/story',
  title: 'The linked story',
  byline: null,
  siteName: 'news.test',
  contentHtml: TALL_BODY,
  excerpt: null,
  paywalled: false,
  originalHero: null,
  extractedAt: '2026-08-01T12:50:34+00:00',
};

async function openReader(page: Page, layout: ReadingLayout): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await presetLocalStorage(page, { 'sfr.layout': layout });
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ json: { entries: ENTRIES, nextCursor: null } });
    },
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const id = Number(new URL(route.request().url()).pathname.split('/').pop());
      const match = ENTRIES.find((each) => each.id === id) ?? ENTRIES[0];
      await route.fulfill({ json: entryDetailJson(match, TALL_BODY) });
    },
  );
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ json: readerFailedJson('unextractable') }),
  );
  await page.route('**/api/entries/*/state', (route) => route.fulfill({ json: {} }));
  await page.route(/\/api\/reader\?/, (route) => route.fulfill({ json: LINKED_PAGE }));
  await page.goto('/');
  await expect(page.locator('app-entry-row').first()).toBeVisible();
}

/** Scrolls the list, opens the row in the middle of the screen, and returns that row. */
async function openArticleFromList(page: Page) {
  await scrollPageTo(page, LIST_OFFSET);
  const title = await rowTitleInView(page);
  expect(title, 'no row in the middle third — the case proves nothing').not.toBe('');
  const row = page.getByText(title, { exact: true }).first();
  const rowY = (await row.boundingBox())!.y;
  await row.click();
  await expect(page.locator('app-reader-view').getByText(LAST_PARAGRAPH)).toBeAttached();
  return { row, rowY };
}

async function expectListBackAtItsOffset(page: Page): Promise<void> {
  await expect(page.locator('.article-overlay')).toHaveCount(0);
  await expect(page.locator('section.list')).not.toHaveClass(/page-pinned/);
  expect(await pageScrollY(page)).toBe(LIST_OFFSET);
}

/** A locator click scrolls a sticky box's in-flow position into view first, moving the page. */
async function clickWhereItStands(page: Page, target: Locator): Promise<void> {
  await target.evaluate(() =>
    Promise.all(
      document
        .getAnimations()
        .filter((animation) => animation.effect?.getComputedTiming().endTime !== Infinity)
        .map((animation) => animation.finished),
    ),
  );
  const box = (await target.boundingBox())!;
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
}

test.describe('A full-screen article owns the page on a phone', () => {
  test.use({ viewport: PHONE });

  test.beforeEach(async ({ page }) => {
    await openReader(page, 'list');
  });

  test('opens at the top of the page over the list, held where it was', async ({ page }) => {
    const { row, rowY } = await openArticleFromList(page);

    expect(await pageScrollY(page)).toBe(0);
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect((await row.boundingBox())!.y).toBeCloseTo(rowY, 0);
  });

  test('scrolls the page while reading; its toolbar retracts, the list bar stays', async ({
    page,
  }) => {
    await openArticleFromList(page);
    const appBar = page.locator('app-reader-header');
    const appBarHidden = await appBar.evaluate((el) => el.classList.contains('hidden'));

    await scrollPageTo(page, ARTICLE_OFFSET);

    await expect(page.locator(`${LAYER} .bar`)).toHaveClass(/hidden/);
    expect((await page.locator(`${LAYER} .mini`).boundingBox())!.y).toBeCloseTo(0, 0);
    expect(await appBar.evaluate((el) => el.classList.contains('hidden'))).toBe(appBarHidden);
  });

  test('the back disc lands the list on its exact offset', async ({ page }) => {
    await openArticleFromList(page);
    await scrollPageTo(page, ARTICLE_OFFSET);

    await page.locator(`${LAYER} .corner-back`).click();

    await expectListBackAtItsOffset(page);
  });

  test('a swipe-back slides the article off the held list and lands on its offset', async ({
    page,
  }) => {
    const { row, rowY } = await openArticleFromList(page);
    await scrollPageTo(page, ARTICLE_OFFSET);

    await touchDrag(page, `${LAYER} .reader`, { x: 20, y: 400 }, { x: 260, y: 410 });
    await expect(page.locator(`${LAYER} .frame`)).toHaveAttribute('style', /translate3d\(240px/);
    expect((await row.boundingBox())!.y).toBeCloseTo(rowY, 0);

    await liftTouch(page, `${LAYER} .reader`);
    await expectListBackAtItsOffset(page);
  });

  test('a pull past the end of the article returns to the list at its offset', async ({ page }) => {
    await openArticleFromList(page);
    await scrollPageToEnd(page);

    await touchDrag(page, `${LAYER} .reader`, { x: 180, y: 600 }, { x: 180, y: 250 });
    await expect(page.locator(`${LAYER} .pull-back`)).toBeAttached();
    await liftTouch(page, `${LAYER} .reader`);

    await expectListBackAtItsOffset(page);
  });

  test('a link reader takes the page from the article and gives it back where it was', async ({
    page,
  }) => {
    await openArticleFromList(page);
    const openLink = page.locator('app-reader-view app-link-card button.open-in-reader');
    await openLink.scrollIntoViewIfNeeded();
    const articleOffset = await pageScrollY(page);
    expect(
      articleOffset,
      'the card sits on the first screen — the case proves nothing',
    ).toBeGreaterThan(0);

    await openLink.click();
    const linkReader = page.locator('app-link-reader');
    await expect(linkReader.getByText(LAST_PARAGRAPH)).toBeAttached();
    expect(await pageScrollY(page)).toBe(0);
    await expect(page.locator('.article-overlay').first()).toHaveClass(/page-pinned/);
    await scrollPageTo(page, ARTICLE_OFFSET);

    await linkReader.locator('.corner-back').click();
    await expect(linkReader).toHaveCount(0);
    expect(await pageScrollY(page)).toBe(articleOffset);
    await expect(page.locator('.article-overlay')).not.toHaveClass(/page-pinned/);

    await page.locator(`${LAYER} .corner-back`).click();
    await expectListBackAtItsOffset(page);
  });
});

test.describe('A full-screen article owns the page beneath the app bar on a wide screen', () => {
  test.use({ viewport: WIDE });

  test('scrolls the page with its toolbar under the app bar, and back lands the list', async ({
    page,
  }) => {
    await openReader(page, 'list');
    await openArticleFromList(page);
    expect(await pageScrollY(page)).toBe(0);
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
    const sidebar = page.locator('aside.sidebar');
    const sidebarY = (await sidebar.boundingBox())!.y;

    await scrollPageTo(page, ARTICLE_OFFSET);

    const appBar = (await page.locator('app-reader-header header').boundingBox())!;
    const close = page.locator(`${LAYER} .bar .close`);
    expect((await close.boundingBox())!.y).toBeGreaterThanOrEqual(appBar.y + appBar.height);
    expect((await sidebar.boundingBox())!.y).toBe(sidebarY);

    await close.click();
    await expectListBackAtItsOffset(page);
  });
});

test.describe('Switching between split view and full screen with an article open', () => {
  test.use({ viewport: WIDE });

  test('carries the list’s and the article’s offsets both ways', async ({ page }) => {
    await openReader(page, 'pane');
    await openArticleFromList(page);
    const pane = page.locator('.main.split > .reader app-reader-view .scroller');
    await pane.evaluate((el, top) => el.scrollTo({ top, behavior: 'instant' }), ARTICLE_OFFSET);
    await expect.poll(() => pane.evaluate((el) => el.scrollTop)).toBe(ARTICLE_OFFSET);
    // Two frames, so the pane's scroll event has been heard and its offset remembered.
    await page.evaluate(
      () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
    );
    expect(await pageScrollY(page)).toBe(LIST_OFFSET);

    // Resized mid-test on purpose; each wait below gates on the layout actually switching.
    await page.setViewportSize(MEDIUM);
    await expect(page.locator('.main.split')).toHaveCount(0);
    await expect(page.locator('.article-overlay app-reader-view')).toBeVisible();
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
    await expect.poll(() => pageScrollY(page)).toBe(ARTICLE_OFFSET);

    await page.setViewportSize(WIDE);
    await expect(page.locator('.main.split')).toHaveCount(1);
    await expect.poll(() => pageScrollY(page)).toBe(LIST_OFFSET);
    await expect.poll(() => pane.evaluate((el) => el.scrollTop)).toBe(ARTICLE_OFFSET);
  });
});

test.describe('An action sheet over a full-screen article', () => {
  test.use({ viewport: PHONE, hasTouch: true, isMobile: true });

  test('holds the page while open and gives it back exactly, with nothing reacting', async ({
    page,
  }) => {
    await openReader(page, 'list');
    expect(
      await page.evaluate(() => matchMedia('(pointer: coarse)').matches),
      'not a coarse pointer — the share button opens a popover, not the sheet',
    ).toBe(true);
    await openArticleFromList(page);
    await scrollPageTo(page, ARTICLE_OFFSET);
    const bar = page.locator(`${LAYER} .bar`);
    await expect(bar).toHaveClass(/hidden/);
    const rail = page.locator(`${LAYER} app-progress-rail`);
    const fill = (): Promise<number> =>
      rail.evaluate((el) => parseFloat(el.style.getPropertyValue('--rail-fill')));
    const fillBefore = await fill();

    await clickWhereItStands(page, page.locator(`${LAYER} .corner-actions button.corner-share`));
    await expect(page.locator('.app-action-sheet')).toBeVisible();
    await expect(page.locator('html')).toHaveClass(/cdk-global-scrollblock/);
    await page.evaluate(
      () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
    );
    await expect(bar).toHaveClass(/hidden/);
    expect(await fill()).toBeCloseTo(fillBefore, 1);

    await page.keyboard.press('Escape');
    await expect(page.locator('.app-action-sheet')).toHaveCount(0);
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect(await pageScrollY(page)).toBe(ARTICLE_OFFSET);
    await expect(bar).toHaveClass(/hidden/);
    expect(await fill()).toBeCloseTo(fillBefore, 1);
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
  });
});
