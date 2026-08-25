// e2e/reading-focus-blocks.spec.ts
import { test, expect, Page } from '@playwright/test';
import { readerFailedJson } from './support/reader';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const PHONE = { width: 375, height: 667 };

const para = (n: number) =>
  `<p>Paragraph ${n}. ${'Enough words to take a few lines. '.repeat(4)}</p>`;

/**
 * The shape readability produced for the reported wired.com article (#109):
 * a wrapper chain down to a level holding two *sections*, each of which holds
 * the paragraphs. Nothing in the article sits at the first branching level.
 */
const NESTED_BODY = `
  <div class="page"><div>
    <div><figure><img alt=""></figure><div>${[1, 2, 3, 4, 5, 6].map(para).join('')}</div></div>
    <div>${[7, 8, 9, 10, 11, 12].map(para).join('')}</div>
  </div></div>`;

const entry = (contentHtml: string) => ({
  id: 1,
  title: 'Nested article',
  url: 'https://example.invalid/1',
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

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.addInitScript(() => localStorage.setItem('sfr.layout', 'list'));
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

async function stubArticle(page: Page): Promise<void> {
  await page.route('**/api/entries/*/reader', async (route) =>
    route.fulfill({ status: 200, json: readerFailedJson() }),
  );
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({
      status: 200,
      json: { entries: [entry(NESTED_BODY)], nextCursor: null },
    });
  });
}

test.describe('Reading focus block detection', () => {
  test.use({ viewport: PHONE });

  test('fades each paragraph on its own, not the section around them', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page);
    await page.reload();

    await page.getByText('Nested article', { exact: false }).first().click();
    const pane = page.locator('app-reader-view');
    await expect(pane.locator('.content p').last()).toBeVisible();
    await page.evaluate(() => document.fonts.ready);

    // Read somewhere in the middle, where a paragraph is on the reading centre.
    await pane.evaluate((el) => el.scrollTo({ top: el.scrollHeight / 2, behavior: 'instant' }));
    await page.waitForTimeout(400); // focus rAF + the 0.2s opacity transition

    const opacities = await pane.evaluate((el) =>
      [...el.querySelectorAll('.content p')].map((p) => Number(getComputedStyle(p).opacity)),
    );

    // Before the fix the two section <div>s carried the whole article's fade, so
    // every paragraph's own computed opacity was 1 and they dimmed in unison:
    // no paragraph dimmed, one distinct value, no peak.
    expect(opacities.filter((o) => o < 1).length).toBeGreaterThan(4);
    expect(new Set(opacities).size).toBeGreaterThan(3); // a gradient, not two groups
    // One paragraph is at the reading centre and the far ones are well dimmed.
    // Not exactly 1: the nearest paragraph's centre only lands on the reading
    // line when the scroll position happens to put it there.
    expect(Math.max(...opacities)).toBeGreaterThan(0.9);
    expect(Math.min(...opacities)).toBeLessThan(0.75);
  });
});
