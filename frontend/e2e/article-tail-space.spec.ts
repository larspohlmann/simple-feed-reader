import { test, expect, Locator, Page } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { ARTICLE_OPEN_TOP, scrollPageToEnd } from './support/scroll';
import { entryDetailJson, entryWire, readerFailedJson } from './support/reader';

/** The 8rem a short article grows by on the page so Brave can keep its toolbar collapsed. */
const BRAVE_MARGIN = 128;

const PHONE = { width: 375, height: 667 };

const LONG_BODY = Array.from(
  { length: 25 },
  (_, i) =>
    `<p>Paragraph ${i + 1}. ${'Long enough to take a few lines on a phone. '.repeat(3)}</p>`,
).join('');
const SHORT_BODY = '<p>One short paragraph, nowhere near a screenful.</p>';

const entry = (id: number) =>
  entryWire({
    id,
    title: `Article ${id}`,
    url: `https://example.invalid/${id}`,
    summary: 'summary',
    excerpt: 'summary',
    publishedAt: '2026-07-25T10:00:00Z',
    createdAt: '2026-07-25T10:00:00Z',
    subscriptionId: 5,
    source: 'stub',
  });

/** Serve one article, extraction failing so the stubbed body is what renders. */
async function stubArticle(page: Page, body: string): Promise<void> {
  await page.route('**/api/entries/*/reader', async (route) =>
    route.fulfill({ status: 200, json: readerFailedJson() }),
  );
  // The body store's own fetch (#1100): list rows carry no body of their own.
  await page.route('**/api/entries/1', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: entryDetailJson(entry(1), body) });
  });
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: [entry(1)], nextCursor: null } });
  });
}

/** Open the first row and wait for the article to render. */
async function openArticle(page: Page) {
  await page.getByText('Article 1', { exact: false }).first().click();
  const pane = page.locator('app-reader-view');
  await expect(pane.locator('.content p').last()).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300); // slide-in animation + first focus pass
  return pane;
}

async function openStubbedArticle(page: Page, body: string): Promise<Locator> {
  const signedIn = await signInWithLayout(page, 'list');
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
  await stubArticle(page, body);
  await page.reload();
  return openArticle(page);
}

function expectWithinAPixel(actual: number, expected: number): void {
  expect(Math.abs(actual - expected)).toBeLessThanOrEqual(1);
}

test.describe('Article tail space', () => {
  test.use({ viewport: PHONE });

  // #107: the reading focus fully highlights only the blocks in the reading
  // band, and the article used to stop scrolling with its last paragraph
  // pinned to the bottom edge — permanently dimmed.
  test('the last paragraph can be scrolled into the band and fully highlighted', async ({
    page,
  }) => {
    const pane = await openStubbedArticle(page, LONG_BODY);
    const scroller = pane.locator('.scroller');

    // All the way to the end of the article.
    await scrollPageToEnd(page);
    await page.waitForTimeout(400);

    const last = pane.locator('.content p').last();
    const box = (await last.boundingBox())!;
    // Lifted clear of the bottom edge — it now sits in the upper two thirds.
    expect(box.y + box.height).toBeLessThan(PHONE.height * 0.75);

    // Park its centre on the band's lower edge, the way a reader would.
    await scroller.evaluate((el) => {
      const p = el.querySelectorAll('.content p');
      const rect = p[p.length - 1].getBoundingClientRect();
      const centre = rect.top + rect.height / 2;
      window.scrollBy({ top: centre - window.innerHeight / 2, behavior: 'instant' });
    });
    await page.waitForTimeout(400); // focus rAF + the 0.2s opacity transition

    // Full highlight, give or take the sub-pixel the centring lands off by
    // (focusOpacity rounds to 3 decimals). Before the tail this paragraph could
    // not be lifted off the bottom edge at all — measured at y=627 of a 667px
    // screen, roughly 0.65 opacity, with no way to bring it any brighter.
    const opacity = await last.evaluate((el) => Number(getComputedStyle(el).opacity));
    expect(opacity).toBeGreaterThan(0.99);
  });

  // #2088: past its lead, only the margin that keeps Brave's collapsed toolbar collapsed.
  test('an article that fits the screen gains no tail scroll', async ({ page }) => {
    await openStubbedArticle(page, SHORT_BODY);

    const overflow = await page.evaluate(
      () => document.documentElement.scrollHeight - window.innerHeight,
    );
    expect(overflow).toBeLessThanOrEqual(ARTICLE_OPEN_TOP + BRAVE_MARGIN + 2);
  });
});

const DESKTOP = { width: 1920, height: 1000 };
const VIDEO = '<video src="https://example.invalid/v.mp4" width="1280" height="720"></video>';

async function tailMetrics(pane: Locator) {
  return pane.locator('.scroller').evaluate((scroller) => {
    const article = scroller.querySelector('article')!;
    const reader = getComputedStyle(scroller.querySelector('.reader')!);
    const lead = parseFloat(reader.getPropertyValue('--page-lead')) || 0;
    const readerTop = parseFloat(reader.paddingTop) - lead;
    return {
      padding: parseFloat(getComputedStyle(article).paddingBottom),
      cinemaRoom: window.innerHeight - readerTop,
      focusRoom: window.innerHeight / 2,
    };
  });
}

test.describe('Article tail space with a widened video', () => {
  test.use({ viewport: DESKTOP });

  // #1479/#1482: a widened video can reach the top of the pane however short the
  // article, and its tail outweighs the reading focus's in a long one.
  test('a short article reserves the widened video its room', async ({ page }) => {
    const pane = await openStubbedArticle(page, VIDEO + SHORT_BODY);

    const unwidened = await tailMetrics(pane);
    expect(unwidened.padding).toBeLessThan(unwidened.focusRoom);

    await pane.locator('.reader-cinema__toggle').click();
    const widened = await tailMetrics(pane);
    expectWithinAPixel(widened.padding, widened.cinemaRoom);
  });

  test("a long article trades the focus tail for the video's while it is widened", async ({
    page,
  }) => {
    const pane = await openStubbedArticle(page, VIDEO + LONG_BODY);

    const reading = await tailMetrics(pane);
    expectWithinAPixel(reading.padding, reading.focusRoom);

    await pane.locator('.reader-cinema__toggle').click();
    const widened = await tailMetrics(pane);
    expectWithinAPixel(widened.padding, widened.cinemaRoom);
  });
});
