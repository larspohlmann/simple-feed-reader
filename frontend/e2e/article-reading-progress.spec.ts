// e2e/article-reading-progress.spec.ts
import { test, expect, Page } from '@playwright/test';
import { readerFailedJson } from './support/reader';

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
    route.fulfill({ status: 200, json: readerFailedJson() }),
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

/** How full the phone's rail is, as a fraction of its height. #435 stood the
 *  cue up on the right edge below the split-pane breakpoint, so below 900px the
 *  cue fills downward — the horizontal hairline is the wide layout's alone. */
async function railFilledFraction(pane: ReturnType<Page['locator']>): Promise<number> {
  return pane.evaluate((el) => {
    const rail = el.querySelector('.progress-rail')!;
    const fill = rail.querySelector('i')!;
    return fill.getBoundingClientRect().height / rail.getBoundingClientRect().height;
  });
}

test.describe('Article reading progress', () => {
  test.use({ viewport: PHONE });

  // #238: the shell locks the page and scrolls an inner container, and a phone
  // paints no persistent scrollbar for a nested scroller — so a long article
  // gave the reader no cue at all about its length or their position in it.
  test('the rail fills as the reader scrolls and is full at the end of the text', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, LONG_BODY);
    await page.reload();
    const pane = await openArticle(page);

    const rail = pane.locator('.progress-rail');
    await expect(rail).toBeVisible();
    // The two cues swap at the breakpoint rather than coexist (#435). Asserting
    // the absent one here is what would have caught this spec going stale.
    await expect(pane.locator('.progress')).toHaveCount(0);
    expect(await railFilledFraction(pane)).toBeLessThan(0.05);

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
    expect(await railFilledFraction(pane)).toBeGreaterThan(0.98);
  });

  // The cue has to survive the reading tail, which is half a viewport of blank
  // space below the last paragraph — exactly where the reader finishes the
  // article. The hairline this replaced was stranded there by its containing
  // block (#238); the rail is immune to that one, because its negative margin
  // leaves it a zero-height margin box that can travel anywhere. What it is not
  // immune to is losing `position: sticky`: drop that and the rail scrolls away
  // with the text, which is the whole defect in a different disguise. Verified
  // by making the rail static — this test then misses the scrollport by 3178px.
  test('the rail spans the scrollport, including over the reading tail', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, LONG_BODY);
    await page.reload();
    const pane = await openArticle(page);

    await pane.evaluate((el) => el.scrollTo({ top: el.scrollHeight, behavior: 'instant' }));
    await page.waitForTimeout(300);

    const gaps = await pane.evaluate((el) => {
      const rail = el.querySelector('.progress-rail')!.getBoundingClientRect();
      const scrollport = el.getBoundingClientRect();
      return {
        fromTop: rail.top - scrollport.top,
        fromBottom: scrollport.bottom - rail.bottom,
        fromRightEdge: scrollport.right - rail.right,
      };
    });
    expect(Math.abs(gaps.fromTop)).toBeLessThanOrEqual(1);
    expect(Math.abs(gaps.fromBottom)).toBeLessThanOrEqual(1);
    expect(Math.abs(gaps.fromRightEdge)).toBeLessThanOrEqual(1);
  });

  test('an article that fits the screen shows no bar', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, SHORT_BODY);
    await page.reload();
    const pane = await openArticle(page);

    await expect(pane.locator('.progress-rail')).toHaveCount(0);
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

    // The wide layout keeps the hairline the split pane was designed for, and
    // shows no rail — the other half of the swap #435 introduced.
    await expect(pane.locator('.progress-rail')).toHaveCount(0);

    const box = (await pane.locator('.progress').boundingBox())!;
    const paneBox = (await pane.boundingBox())!;
    expect(box.width).toBeCloseTo(paneBox.width, 0);
    expect(box.width).toBeLessThan(DESKTOP.width);
    expect(box.x).toBeCloseTo(paneBox.x, 0);
  });
});
