import { expect, Page, test } from '@playwright/test';
import { STUB_PROFILE } from './support/auth';
import { entryWire, stubOneArticle, stubOneFeedReader } from './support/reader';
import { scrollPageTo } from './support/scroll';

const ENTRIES = Array.from({ length: 30 }, (_, index) => entryWire({ id: index + 1 }));
const LONG_BODY = Array.from(
  { length: 30 },
  (_, index) => `<p>Paragraph ${index + 1}. ${'Long enough to wrap on a phone. '.repeat(4)}</p>`,
).join('');

const MARK_ALL = '.corner-cluster .mark-all';
const MARK_ABOVE = '.corner-cluster .mark-above';

const EDGE = 24;
const DISC = 36;
const IN_GROUP = 8;
const BETWEEN_GROUPS = 24;
const ABOVE_PAIR = EDGE + DISC + BETWEEN_GROUPS;
const STEP = DISC + IN_GROUP;

interface DiscBox {
  left: number;
  right: number;
  bottom: number;
}

/** Each disc's distance to the viewport's left, right and bottom edges, settled past its entrance. */
async function discAt(page: Page, selector: string): Promise<DiscBox> {
  const disc = page.locator(selector);
  await expect(disc).toBeVisible();
  await page.waitForTimeout(250);
  return disc.evaluate((element) => {
    const box = element.getBoundingClientRect();
    return {
      left: Math.round(box.left),
      right: Math.round(window.innerWidth - box.right),
      bottom: Math.round(window.innerHeight - box.bottom),
    };
  });
}

async function signInAs(page: Page, handedness: 'right' | 'left'): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route(/\/api\/me(?:[?#]|$)/, (route) =>
    route.fulfill({ json: { ...STUB_PROFILE, preferences: { handedness } } }),
  );
}

async function openScrolledList(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: ENTRIES, nextCursor: null } }),
  );
  await page.goto('/?subscription=1');
  await expect(page.getByText('Fixture entry 1', { exact: true })).toBeVisible();
  await scrollPageTo(page, 1500);
}

async function openScrolledArticle(page: Page): Promise<void> {
  await stubOneArticle(page, entryWire({ id: 1 }), LONG_BODY);
  await page.goto('/?subscription=1');
  await page.getByText('Fixture entry 1', { exact: true }).click();
  await expect(page.locator('app-reader-view .content p').last()).toBeAttached();
  await page.waitForTimeout(400);
  await scrollPageTo(page, 1200);
}

test.describe('The corner cluster on a phone (#2203)', () => {
  test.use({ viewport: { width: 375, height: 667 }, isMobile: true, hasTouch: true });

  test('stacks the list discs in the right corner', async ({ page }) => {
    await signInAs(page, 'right');
    await openScrolledList(page);

    expect(await discAt(page, '.corner-sidebar')).toMatchObject({ right: EDGE, bottom: EDGE });
    expect(await discAt(page, 'app-entry-list app-to-top-button button')).toMatchObject({
      right: EDGE + STEP,
      bottom: EDGE,
    });
    expect(await discAt(page, MARK_ABOVE)).toMatchObject({ right: EDGE, bottom: ABOVE_PAIR });
    expect(await discAt(page, MARK_ALL)).toMatchObject({
      right: EDGE,
      bottom: ABOVE_PAIR + STEP,
    });
    await page.screenshot({ path: test.info().outputPath('list-right-handed.png') });
  });

  test('mirrors the list discs for a left-handed account', async ({ page }) => {
    await signInAs(page, 'left');
    await openScrolledList(page);

    expect(await discAt(page, '.corner-sidebar')).toMatchObject({ left: EDGE, bottom: EDGE });
    expect(await discAt(page, 'app-entry-list app-to-top-button button')).toMatchObject({
      left: EDGE + STEP,
      bottom: EDGE,
    });
    expect(await discAt(page, MARK_ABOVE)).toMatchObject({ left: EDGE, bottom: ABOVE_PAIR });
    expect(await discAt(page, MARK_ALL)).toMatchObject({
      left: EDGE,
      bottom: ABOVE_PAIR + STEP,
    });
    await page.screenshot({ path: test.info().outputPath('list-left-handed.png') });
  });

  test('stacks favourite, save and share over the back disc', async ({ page }) => {
    await signInAs(page, 'right');
    await openScrolledArticle(page);

    expect(await discAt(page, '.corner-back')).toMatchObject({ right: EDGE, bottom: EDGE });
    expect(await discAt(page, 'app-article-layer app-to-top-button button')).toMatchObject({
      right: EDGE + STEP,
      bottom: EDGE,
    });
    expect(await discAt(page, '.corner-share')).toMatchObject({ right: EDGE, bottom: ABOVE_PAIR });
    const [save, favourite] = ['.corner-flag >> nth=0', '.corner-flag >> nth=1'];
    expect(await discAt(page, save)).toMatchObject({ right: EDGE, bottom: ABOVE_PAIR + STEP });
    expect(await discAt(page, favourite)).toMatchObject({
      right: EDGE,
      bottom: ABOVE_PAIR + 2 * STEP,
    });
    await page.screenshot({ path: test.info().outputPath('article-right-handed.png') });

    await page.locator(favourite).tap();
    await expect(page.locator(favourite)).toHaveClass(/\bon\b/);
  });

  test('mirrors the article discs for a left-handed account', async ({ page }) => {
    await signInAs(page, 'left');
    await openScrolledArticle(page);

    expect(await discAt(page, '.corner-back')).toMatchObject({ left: EDGE, bottom: EDGE });
    expect(await discAt(page, '.corner-share')).toMatchObject({ left: EDGE, bottom: ABOVE_PAIR });
    await page.screenshot({ path: test.info().outputPath('article-left-handed.png') });
  });
});

test.describe('The corner cluster on a touch tablet (#2203)', () => {
  test.use({ viewport: { width: 1024, height: 768 }, isMobile: true, hasTouch: true });

  test('lets the column take the corner while the pair shows no disc', async ({ page }) => {
    await signInAs(page, 'right');
    await page.route(
      (url) => url.pathname === '/api/entries',
      (route) => route.fulfill({ json: { entries: ENTRIES, nextCursor: null } }),
    );
    await page.goto('/?subscription=1');
    await expect(page.getByText('Fixture entry 1', { exact: true })).toBeVisible();

    await expect(page.locator('app-entry-list .end-corner')).toHaveCount(0);
    expect((await discAt(page, MARK_ALL)).bottom).toBe(EDGE);
  });
});

test.describe('The corner cluster on a short phone list (#2203)', () => {
  test.use({ viewport: { width: 375, height: 260 }, isMobile: true, hasTouch: true });

  test('hides the stack where it would ride over the header', async ({ page }) => {
    await signInAs(page, 'right');
    await openScrolledList(page);

    await expect(page.locator(MARK_ALL)).toBeHidden();
  });
});

test.describe('The corner cluster with a mouse (#2203)', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test('keeps the actions bottom-left and ignores the handedness', async ({ page }) => {
    await signInAs(page, 'left');
    await openScrolledList(page);

    const markAll = await discAt(page, MARK_ALL);
    const toTop = await discAt(page, 'app-entry-list app-to-top-button button');
    expect(markAll.bottom).toBe(toTop.bottom);
    expect(toTop.left - markAll.left).toBeGreaterThan(200);
    await page.screenshot({ path: test.info().outputPath('desktop-left-handed.png') });
  });
});
