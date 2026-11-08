import { test, expect, Page } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson } from './support/reader';
import { pageScrollY, rowTitleInView, scrollPageTo } from './support/scroll';

// The list scrolls the document at every width (#2038): a mobile browser collapses its
// bar only for a page scroll. Every entry is stubbed, so the spec owns what it scrolls.
const PHONE = { width: 375, height: 812 };
const WIDE = { width: 1440, height: 900 };
const LIST_OFFSET = 1200;

const ENTRIES = Array.from({ length: 40 }, (_, i) =>
  entryWire({
    id: i + 1,
    title: `Entry number ${i + 1}`,
    url: `https://example.invalid/${i + 1}`,
    summary: 'A summary long enough to give the row some height. '.repeat(3),
    excerpt: 'A summary long enough to give the row some height. '.repeat(3),
    subscriptionId: 5,
    source: 'stub',
  }),
);

const TALL_BODY = Array.from(
  { length: 40 },
  (_, i) =>
    `<p>Paragraph ${i} of filler text, long enough that the article overflows its pane.</p>`,
).join('');

async function stubReader(page: Page): Promise<void> {
  await page.route('**/api/tags', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { tags: [] } });
  });
  await page.route('**/api/entries/*/state', async (route) => {
    await route.fulfill({
      status: 200,
      json: {
        state: { entryId: 1, isHidden: true, isFavorite: false, isKept: false, hiddenAt: 'x' },
      },
    });
  });
  await page.route('**/api/entries/*/reader', async (route) => {
    await route.fulfill({ status: 200, json: readerFailedJson('unextractable') });
  });
  await page.route(
    (url) => /^\/api\/entries\/\d+$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const id = Number(new URL(route.request().url()).pathname.split('/').pop());
      const match = ENTRIES.find((each) => each.id === id) ?? ENTRIES[0];
      await route.fulfill({ status: 200, json: entryDetailJson(match, TALL_BODY) });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
}

async function dragDivider(page: Page, deltaX: number): Promise<void> {
  const box = (await page.locator('.pane-divider').boundingBox())!;
  const centreX = box.x + box.width / 2;
  const centreY = box.y + box.height / 2;
  await page.mouse.move(centreX, centreY);
  await page.mouse.down();
  await page.mouse.move(centreX + deltaX, centreY, { steps: 8 });
  await page.mouse.up();
}

test.describe('The list scrolls the page on a phone', () => {
  test.use({ viewport: PHONE });

  test.beforeEach(async ({ page }) => {
    await stubReader(page);
    const signedIn = await signInWithLayout(page, 'list');
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );
    await expect(page.locator('app-entry-row').first()).toBeVisible();
  });

  test('scrolling the list moves the document, and the app bar retracts', async ({ page }) => {
    await scrollPageTo(page, LIST_OFFSET);
    await expect(page.locator('app-reader-header')).toHaveClass(/hidden/);
    expect(await page.locator('.rows').evaluate((rows) => rows.scrollTop)).toBe(0);
  });

  test('the rail fills at the column edge, with no page scrollbar', async ({ page }) => {
    await scrollPageTo(page, LIST_OFFSET);
    const rail = page.locator('app-entry-list app-progress-rail');
    await expect(rail).not.toHaveClass(/idle/);
    await expect
      .poll(() => rail.evaluate((el) => parseFloat(el.style.getPropertyValue('--rail-fill'))))
      .toBeGreaterThan(0);
    const box = (await rail.boundingBox())!;
    expect(box.x + box.width).toBeCloseTo(PHONE.width, 0);
    const scrollbar = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('scrollbar-width'),
    );
    expect(scrollbar).toBe('none');
  });

  test('a full-screen article pins the list, and back lands on its exact offset', async ({
    page,
  }) => {
    await scrollPageTo(page, LIST_OFFSET);
    const title = await rowTitleInView(page);
    expect(title, 'no row in the middle third — the case proves nothing').not.toBe('');

    await page.getByText(title, { exact: true }).first().click();
    await expect(page.locator('.article-overlay app-reader-view')).toBeVisible();
    await expect(page.locator('section.list')).toHaveClass(/page-pinned/);
    await expect(page.locator('html')).not.toHaveClass(/cdk-global-scrollblock/);
    expect(await pageScrollY(page)).toBe(0);

    await page.locator('app-reader-view .bar .close').click();
    await expect(page.locator('.article-overlay')).toHaveCount(0);
    await expect(page.locator('section.list')).not.toHaveClass(/page-pinned/);
    expect(await pageScrollY(page)).toBe(LIST_OFFSET);
  });

  test('the open drawer holds the page at its offset and height', async ({ page }) => {
    await scrollPageTo(page, LIST_OFFSET);
    const height = await page.evaluate(() => document.documentElement.scrollHeight);
    // The retracted bar hides the menu button, so the drawer opens the way a thumb opens it.
    await page.locator('.rows').evaluate((rows) => {
      const touch = (x: number) => [
        new Touch({ identifier: 1, target: rows, clientX: x, clientY: 300 }),
      ];
      rows.dispatchEvent(new TouchEvent('touchstart', { bubbles: true, touches: touch(6) }));
      rows.dispatchEvent(new TouchEvent('touchmove', { bubbles: true, touches: touch(220) }));
      rows.dispatchEvent(new TouchEvent('touchend', { bubbles: true, touches: [] }));
    });
    await expect(page.locator('html')).toHaveClass(/page-held/);
    // A page that shrinks to the viewport expands Brave's collapsed toolbar (#2088).
    expect(await page.evaluate(() => document.documentElement.scrollHeight)).toBe(height);
    await page.mouse.wheel(0, 600);
    expect(await pageScrollY(page)).toBe(LIST_OFFSET);

    await page
      .locator('.backdrop')
      .click({ position: { x: PHONE.width - 10, y: PHONE.height / 2 } });
    await expect(page.locator('html')).not.toHaveClass(/page-held/);
    expect(await pageScrollY(page)).toBe(LIST_OFFSET);
  });
});

test.describe('The list scrolls the page beside a pinned reader pane', () => {
  test.use({ viewport: WIDE });

  test.beforeEach(async ({ page }) => {
    await stubReader(page);
    const signedIn = await signInWithLayout(page, 'pane');
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );
    await page.getByText('Entry number 1', { exact: true }).click();
    await expect(page.getByText('Paragraph 0 of filler text').first()).toBeVisible();
  });

  test('the document moves while the sidebar and the reader pane stay put', async ({ page }) => {
    const sidebar = page.locator('aside.sidebar');
    const pane = page.locator('.main.split > .reader');
    const sidebarBefore = (await sidebar.boundingBox())!;
    const paneBefore = (await pane.boundingBox())!;

    await scrollPageTo(page, LIST_OFFSET);

    expect((await sidebar.boundingBox())!.y).toBe(sidebarBefore.y);
    expect((await pane.boundingBox())!.y).toBe(paneBefore.y);
  });

  test('a wheel at the reader pane end does not scroll the list', async ({ page }) => {
    const scroller = page.locator('.main.split > .reader app-reader-view .scroller');
    expect(
      await scroller.evaluate((el) => el.scrollHeight > el.clientHeight),
      'the pane does not overflow — the case proves nothing',
    ).toBe(true);
    await scroller.evaluate((el) => el.scrollTo({ top: el.scrollHeight, behavior: 'instant' }));
    await scrollPageTo(page, 400);

    const paneBox = (await scroller.boundingBox())!;
    await page.mouse.move(paneBox.x + paneBox.width / 2, paneBox.y + paneBox.height / 2);
    await page.mouse.wheel(0, 800);
    await page.waitForTimeout(300);
    expect(await pageScrollY(page)).toBe(400);

    // The control: the same wheel over the list does move the page.
    const listBox = (await page.locator('.main.split > .list').boundingBox())!;
    await page.mouse.move(listBox.x + listBox.width / 2, paneBox.y + paneBox.height / 2);
    await page.mouse.wheel(0, 800);
    await expect.poll(() => pageScrollY(page)).toBeGreaterThan(400);
  });

  test('the list rail sits at the column edge and follows a divider drag', async ({ page }) => {
    const rail = page.locator('app-entry-list app-progress-rail');
    const column = page.locator('.main.split > .list');
    await expect(rail).not.toHaveClass(/idle/);
    const rightEdge = async (target: typeof rail): Promise<number> => {
      const box = (await target.boundingBox())!;
      return box.x + box.width;
    };

    expect(Math.abs((await rightEdge(rail)) - (await rightEdge(column)))).toBeLessThanOrEqual(1);
    const before = await rightEdge(rail);

    await dragDivider(page, -120);

    await expect
      .poll(async () => Math.abs((await rightEdge(rail)) - (await rightEdge(column))))
      .toBeLessThanOrEqual(1);
    expect(await rightEdge(rail)).toBeLessThan(before - 60);
  });
});
