// e2e/reading-focus-long-paragraph.spec.ts
import { test, expect, Page } from '@playwright/test';
import { readerFailedJson } from './support/reader';

// Same seeded admin as reading-focus-blocks.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const PHONE = { width: 375, height: 667 };

// One paragraph far taller than half the phone screen — the case #1077 fixes.
const LONG_PARAGRAPH = `<p>${Array.from(
  { length: 14 },
  (_, i) =>
    `This is sentence number ${i + 1}, written with enough words that it wraps ` +
    `across a couple of lines on a narrow phone screen.`,
).join(' ')}</p>`;

const entry = () => ({
  id: 1,
  title: 'One long paragraph',
  url: 'https://example.invalid/1',
  author: null,
  summary: 'summary',
  excerpt: 'summary',
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
  isHidden: false,
  isFavorite: false,
  isKept: false,
});

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.addInitScript(() => {
    localStorage.setItem('sfr.layout', 'list');
    localStorage.setItem('sfr.readingFocus', 'true');
  });
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

// This spec owns its data (#96): the entry list, the entry detail (the body
// store's own fetch) and the reader failure are all stubbed, so the one long
// paragraph is exactly what renders.
async function stubArticle(page: Page): Promise<void> {
  await page.route('**/api/entries/*/reader', async (route) =>
    route.fulfill({ status: 200, json: readerFailedJson() }),
  );
  await page.route('**/api/entries/1', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({
      status: 200,
      json: { entry: { ...entry(), contentHtml: LONG_PARAGRAPH } },
    });
  });
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({
      status: 200,
      json: { entries: [entry()], nextCursor: null },
    });
  });
}

test.describe('Reading focus splits a long paragraph', () => {
  test.use({ viewport: PHONE });

  test('fades sections within the paragraph, without reflowing it', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page);
    await page.reload();

    await page.getByText('One long paragraph', { exact: false }).first().click();
    const pane = page.locator('app-reader-view');
    await expect(pane.locator('.content p').first()).toBeVisible();
    await page.evaluate(() => document.fonts.ready);

    // Read from the middle of the paragraph, so sections lie on both sides of
    // the reading centre.
    await pane.evaluate((el) => el.scrollTo({ top: el.scrollHeight / 2, behavior: 'instant' }));
    await page.waitForTimeout(400); // focus rAF + the 0.2s opacity transition

    const spanOpacities = await pane.evaluate(() =>
      [...document.querySelectorAll('app-reader-view .content span.reading-sentence')].map((span) =>
        Number(getComputedStyle(span as HTMLElement).opacity),
      ),
    );

    // The paragraph was wrapped into several sentence spans, and they carry a
    // gradient of opacities rather than one shared value — the whole point of
    // the split. Without it the single <p> lit the whole screen.
    expect(spanOpacities.length).toBeGreaterThan(3);
    expect(new Set(spanOpacities.map((o) => o.toFixed(3))).size).toBeGreaterThan(1);
    expect(Math.max(...spanOpacities)).toBeGreaterThan(0.9);
    expect(Math.min(...spanOpacities)).toBeLessThan(0.75);

    // Wrapping is layout-neutral: the paragraph is exactly as tall with its
    // sentence spans as it is with them removed.
    const { wrapped, plain } = await pane.evaluate(() => {
      const p = document.querySelector('app-reader-view .content p') as HTMLElement;
      const wrappedHeight = p.getBoundingClientRect().height;
      const html = p.innerHTML;
      p.textContent = p.textContent;
      const plainHeight = p.getBoundingClientRect().height;
      p.innerHTML = html;
      return { wrapped: wrappedHeight, plain: plainHeight };
    });
    expect(Math.abs(wrapped - plain)).toBeLessThanOrEqual(1);
  });
});
