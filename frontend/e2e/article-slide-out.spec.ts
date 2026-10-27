import { test, expect, Page } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson } from './support/reader';

const PHONE = { width: 375, height: 667 };
const DESKTOP = { width: 1280, height: 900 };

const ENTRY = entryWire({
  id: 1,
  title: 'Entry number 1',
  url: 'https://example.invalid/1',
  summary: 'A summary long enough to give the row some height.',
  excerpt: 'A summary long enough to give the row some height.',
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
});

async function stubEntries(page: Page): Promise<void> {
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ status: 200, json: readerFailedJson('unextractable') }),
  );
  await page.route('**/api/entries/1', (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    return route.fulfill({ status: 200, json: entryDetailJson(ENTRY, '<p>Body.</p>') });
  });
  await page.route('**/api/entries/*/state', (route) =>
    route.fulfill({
      status: 200,
      json: {
        state: { entryId: 1, isHidden: false, isFavorite: false, isKept: false, hiddenAt: null },
      },
    }),
  );
  await page.route('**/api/entries*', (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    return route.fulfill({ status: 200, json: { entries: [ENTRY], nextCursor: null } });
  });
}

declare global {
  interface Window {
    leaveAnimations: string[];
  }
}

/** Opens the article and waits out its slide-in, so the next animation seen is the leave. */
async function openArticle(page: Page): Promise<void> {
  test.skip(!(await signInWithLayout(page, 'magazine')), 'seeded admin login unavailable');
  await stubEntries(page);
  await page.reload();
  await page.getByText('Entry number 1', { exact: false }).first().click();
  const overlay = page.locator('.article-overlay');
  await expect(overlay.locator('app-reader-view')).toBeVisible();
  await overlay.evaluate((element) =>
    Promise.all(element.getAnimations().map((animation) => animation.finished)),
  );
}

/** Closes the article and returns the animation each leaving overlay was given. */
async function closeArticle(page: Page, close: () => Promise<unknown>): Promise<string[]> {
  await page.evaluate(() => {
    window.leaveAnimations = [];
    new MutationObserver((records) => {
      for (const record of records) {
        const target = record.target as HTMLElement;
        if (target.matches('.article-overlay.article-leave')) {
          window.leaveAnimations.push(getComputedStyle(target).animationName);
        }
      }
    }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
  });
  await close();
  await expect(page.locator('.article-overlay')).toHaveCount(0);
  return page.evaluate(() => window.leaveAnimations);
}

const SLID_OUT = [expect.stringMatching(/article-leave$/)];

test.describe('Article slide-out (#1847)', () => {
  test.describe('wide single-pane', () => {
    test.use({ viewport: DESKTOP });

    test('the toolbar back arrow slides the article out', async ({ page }) => {
      await openArticle(page);
      const back = page.locator('app-reader-view .bar .close');

      expect(await closeArticle(page, () => back.click())).toEqual(SLID_OUT);
    });
  });

  test.describe('phone', () => {
    test.use({ viewport: PHONE });

    test('the browser back button slides the article out', async ({ page }) => {
      await openArticle(page);

      expect(await closeArticle(page, () => page.goBack())).toEqual(SLID_OUT);
    });

    test('a reduced-motion close leaves at once', async ({ page }) => {
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await openArticle(page);

      expect(await closeArticle(page, () => page.goBack())).toEqual(['none']);
    });
  });
});
