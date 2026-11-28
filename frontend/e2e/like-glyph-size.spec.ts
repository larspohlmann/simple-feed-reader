import { expect, Page, test } from '@playwright/test';
import { presetLocalStorage } from './support/auth';
import { entryWire, stubOneFeedReader } from './support/reader';

/**
 * #2599: the thumb_up glyph fills more of its box than the star, bookmark and tick, so the
 * like draws it at 90% of the cluster's icon size to read the same. Every read is stubbed.
 */
const ARTICLE = entryWire({ id: 1, title: 'An article to like', social: null });

async function openList(page: Page, layout: 'list' | 'magazine'): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [ARTICLE], nextCursor: null } }),
  );
  await page.setViewportSize({ width: 1280, height: 800 });
  await presetLocalStorage(page, { 'sfr.layout': layout });
  await page.goto('/?subscription=1');
  await expect(page.locator('app-entry-actions app-entry-like')).toBeVisible();
}

async function iconHeight(page: Page, selector: string): Promise<number> {
  const box = await page.locator(`app-entry-actions ${selector}`).first().boundingBox();
  return box!.height;
}

for (const layout of ['list', 'magazine'] as const) {
  test(`the thumb draws at 90% of the star in the ${layout} layout`, async ({ page }) => {
    await openList(page, layout);

    const thumb = await iconHeight(page, 'app-entry-like app-icon');
    const star = await iconHeight(page, 'button:has(app-icon[name="star"]) app-icon');

    expect(thumb / star).toBeCloseTo(0.9, 2);
  });
}
