import { test, expect, Locator, Page } from '@playwright/test';
import { presetLocalStorage, signInAsAdmin } from './support/auth';
import { entryWire } from './support/reader';

const PHONE = { width: 375, height: 667 };

/** A handful of rows is enough: the gesture only ever starts at the top. */
const ENTRIES = Array.from({ length: 10 }, (_, i) =>
  entryWire({
    id: i + 1,
    title: `Entry number ${i + 1}`,
    url: `https://example.invalid/${i + 1}`,
    summary: 'A summary long enough to give the row some height. '.repeat(3),
    excerpt: 'A summary long enough to give the row some height. '.repeat(3),
    publishedAt: '2026-07-25T10:00:00Z',
    createdAt: '2026-07-25T10:00:00Z',
    subscriptionId: 5,
    source: 'stub',
  }),
);

async function signInToList(page: Page): Promise<boolean> {
  // Pin the flat list so the first row's geometry is predictable (the default
  // magazine layout groups same-source entries).
  await presetLocalStorage(page, { 'sfr.layout': 'list' });
  return signInAsAdmin(page);
}

async function stubEntries(page: Page): Promise<void> {
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
  });
  await page.route('**/api/refresh*', async (route) =>
    route.fulfill({
      status: 200,
      json: { status: 'completed', progress: { done: 1, total: 1 }, remaining: 0 },
    }),
  );
}

/**
 * Press at the top of the list and drag down by `dy`, leaving the finger down.
 * Real TouchEvents from the page, so the component's own listeners run exactly
 * as they do on a phone; a Playwright touchscreen tap cannot express a drag.
 */
async function pullDown(rows: Locator, dy: number): Promise<void> {
  await rows.evaluate((el, distance) => {
    const box = el.getBoundingClientRect();
    const startY = box.top + 8;
    const at = (y: number) =>
      new Touch({ identifier: 1, target: el, clientX: box.left + box.width / 2, clientY: y });
    const send = (type: string, y: number) =>
      el.dispatchEvent(new TouchEvent(type, { touches: [at(y)], cancelable: true, bubbles: true }));
    send('touchstart', startY);
    // Several moves, as a finger produces — the handler tracks the latest point.
    for (let step = 1; step <= 4; step++) send('touchmove', startY + (distance * step) / 4);
  }, dy);
}

async function releaseTouch(rows: Locator): Promise<void> {
  await rows.evaluate((el) =>
    el.dispatchEvent(new TouchEvent('touchend', { touches: [], cancelable: true, bubbles: true })),
  );
}

test.describe('Pull-to-refresh on a phone', () => {
  test.use({ viewport: PHONE, hasTouch: true });

  // #105: the chip is anchored to the entry-list host, which since #87 starts at
  // the viewport top *underneath* the floating app bar. Anchored there it could
  // never be pulled clear of the bars, so the gesture gave no feedback at all.
  test('the indicator comes out from under the bars while pulling', async ({ page }) => {
    const signedIn = await signInToList(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubEntries(page);
    await page.reload();

    const rows = page.locator('.rows');
    await expect(rows).toBeVisible();
    await page.evaluate(() => document.fonts.ready);

    await pullDown(rows, 140);

    const chip = page.locator('.pull-indicator');
    await expect(chip).toBeVisible();
    const chipBox = (await chip.boundingBox())!;
    const listHeader = (await page.locator('.list-header').boundingBox())!;
    // Fully below the bar it slides out from under — not hidden behind it.
    expect(chipBox.y).toBeGreaterThanOrEqual(listHeader.y + listHeader.height);
    expect(chipBox.y + chipBox.height).toBeLessThanOrEqual(PHONE.height);

    await releaseTouch(rows);
  });

  test('releasing a decisive pull refreshes; a short one does not', async ({ page }) => {
    const signedIn = await signInToList(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubEntries(page);
    await page.reload();

    const rows = page.locator('.rows');
    await expect(rows).toBeVisible();

    let refreshes = 0;
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().includes('/api/refresh')) refreshes++;
    });

    // Short of the threshold: nothing happens.
    await pullDown(rows, 40);
    await releaseTouch(rows);
    await page.waitForTimeout(300);
    expect(refreshes).toBe(0);

    // A pull a thumb actually makes.
    await pullDown(rows, 160);
    await releaseTouch(rows);
    await expect.poll(() => refreshes, { timeout: 5_000 }).toBe(1);
  });
});
