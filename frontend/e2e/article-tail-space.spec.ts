// e2e/article-tail-space.spec.ts
import { test, expect, Page } from '@playwright/test';
import { readerFailedJson } from './support/reader';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const PHONE = { width: 375, height: 667 };

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
  isHidden: false,
  isFavorite: false,
  isKept: false,
});

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.addInitScript(() => localStorage.setItem('sfr.layout', 'list'));
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
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

test.describe('Article tail space', () => {
  test.use({ viewport: PHONE });

  // #107: the reading focus fully highlights only the block at the viewport
  // centre, and the article used to stop scrolling with its last paragraph
  // pinned to the bottom edge — permanently dimmed.
  test('the last paragraph can be scrolled to the centre and fully highlighted', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, LONG_BODY);
    await page.reload();
    const pane = await openArticle(page);

    // All the way to the end of the article.
    await pane.evaluate((el) => el.scrollTo({ top: el.scrollHeight, behavior: 'instant' }));
    await page.waitForTimeout(400);

    const last = pane.locator('.content p').last();
    const box = (await last.boundingBox())!;
    // Lifted clear of the bottom edge — it now sits in the upper two thirds.
    expect(box.y + box.height).toBeLessThan(PHONE.height * 0.75);

    // Park its centre on the reading centre, the way a reader would.
    await pane.evaluate((el) => {
      const p = el.querySelectorAll('.content p');
      const rect = p[p.length - 1].getBoundingClientRect();
      const paneRect = el.getBoundingClientRect();
      const centre = rect.top + rect.height / 2 - paneRect.top;
      el.scrollTop += centre - el.clientHeight / 2;
    });
    await page.waitForTimeout(400); // focus rAF + the 0.2s opacity transition

    // Full highlight, give or take the sub-pixel the centring lands off by
    // (focusOpacity rounds to 3 decimals). Before the tail this paragraph could
    // not be lifted off the bottom edge at all — measured at y=627 of a 667px
    // screen, roughly 0.65 opacity, with no way to bring it any brighter.
    const opacity = await last.evaluate((el) => Number(getComputedStyle(el).opacity));
    expect(opacity).toBeGreaterThan(0.99);
  });

  test('an article that fits the screen gains no dead scroll', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, SHORT_BODY);
    await page.reload();
    const pane = await openArticle(page);

    const overflow = await pane.evaluate((el) => el.scrollHeight - el.clientHeight);
    expect(overflow).toBeLessThanOrEqual(2);
  });
});
