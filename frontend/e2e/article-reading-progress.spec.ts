// e2e/article-reading-progress.spec.ts
import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const PHONE = { width: 375, height: 667 };
// Wide enough for the split layout, where the article shares the main area with
// the list — the one place a viewport-anchored bar would run on under the list.
const DESKTOP = { width: 1280, height: 900 };

const LONG_BODY = Array.from(
  { length: 25 },
  (_, i) =>
    `<p>Paragraph ${i + 1}. ${'Long enough to take a few lines on a phone. '.repeat(3)}</p>`,
).join('');
const SHORT_BODY = '<p>One short paragraph, nowhere near a screenful.</p>';

const entry = (id: number, contentHtml: string) => ({
  id,
  title: `Article ${id}`,
  url: `https://example.invalid/${id}`,
  author: null,
  summary: 'summary',
  contentHtml,
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
  isRead: false,
  isFavorite: false,
  isKept: false,
});

/** Pin the layout, so a test picks its shell branch instead of inheriting
 *  whatever the previous run left in localStorage — see article-back-desktop. */
async function signInAsAdmin(page: Page, layout: 'list' | 'pane' = 'list'): Promise<boolean> {
  await page.addInitScript((mode) => localStorage.setItem('sfr.layout', mode), layout);
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/** Serve one article, extraction failing so the stubbed body is what renders. */
async function stubArticle(page: Page, body: string): Promise<void> {
  await page.route('**/api/entries/*/reader', async (route) =>
    route.fulfill({ status: 200, json: { status: 'failed', reason: 'fetch', url: null } }),
  );
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: [entry(1, body)], nextCursor: null } });
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

/** How full the bar is, as a fraction of the pane's width. */
async function filledFraction(pane: ReturnType<Page['locator']>): Promise<number> {
  return pane.evaluate((el) => {
    const fill = el.querySelector('.progress i')!;
    return fill.getBoundingClientRect().width / el.getBoundingClientRect().width;
  });
}

test.describe('Article reading progress', () => {
  test.use({ viewport: PHONE });

  // #238: the shell locks the page and scrolls an inner container, and a phone
  // paints no persistent scrollbar for a nested scroller — so a long article
  // gave the reader no cue at all about its length or their position in it.
  test('the bar fills as the reader scrolls and is full at the end of the text', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, LONG_BODY);
    await page.reload();
    const pane = await openArticle(page);

    const bar = pane.locator('.progress');
    await expect(bar).toBeVisible();
    expect(await filledFraction(pane)).toBeLessThan(0.05);

    // The end of the text — NOT the end of the scroller, which carries half a
    // viewport of reading tail past it.
    await pane.evaluate((el) => {
      const content = el.querySelector('.content')!;
      const bottom = content.getBoundingClientRect().bottom - el.getBoundingClientRect().top;
      el.scrollTo({ top: el.scrollTop + bottom - el.clientHeight, behavior: 'instant' });
    });
    await page.waitForTimeout(200);

    // Full at the last line. Measured against `scrollHeight` — the naive
    // formula — the tail would hold this at roughly two thirds.
    expect(await filledFraction(pane)).toBeGreaterThan(0.98);
  });

  // The tail hangs off the article, not off `.reader`, precisely so the sticky
  // bar can travel the whole way: a sticky box stops at its containing block's
  // content box, so tail padding on `.reader` used to strand the bar half a
  // viewport short and it scrolled out of sight over the tail.
  test('the bar stays pinned to the bottom edge, including over the reading tail', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, LONG_BODY);
    await page.reload();
    const pane = await openArticle(page);

    await pane.evaluate((el) => el.scrollTo({ top: el.scrollHeight, behavior: 'instant' }));
    await page.waitForTimeout(300);

    const gapFromBottom = await pane.evaluate((el) => {
      const bar = el.querySelector('.progress')!.getBoundingClientRect();
      return el.getBoundingClientRect().bottom - bar.bottom;
    });
    expect(Math.abs(gapFromBottom)).toBeLessThanOrEqual(1);
  });

  test('an article that fits the screen shows no bar', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, SHORT_BODY);
    await page.reload();
    const pane = await openArticle(page);

    await expect(pane.locator('.progress')).toHaveCount(0);
  });
});

test.describe('Article reading progress on the split layout', () => {
  test.use({ viewport: DESKTOP });

  // The bar belongs to the article, not to the window: on the split layout the
  // list occupies the left of the same row, and a bar spanning the viewport
  // would report the article's position underneath the list as well.
  test('the bar spans the reading pane only, not the window', async ({ page }) => {
    const signedIn = await signInAsAdmin(page, 'pane');
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, LONG_BODY);
    await page.reload();
    const pane = await openArticle(page);

    const box = (await pane.locator('.progress').boundingBox())!;
    const paneBox = (await pane.boundingBox())!;
    expect(box.width).toBeCloseTo(paneBox.width, 0);
    expect(box.width).toBeLessThan(DESKTOP.width);
    expect(box.x).toBeCloseTo(paneBox.x, 0);
  });
});
