import { expect, test } from '@playwright/test';
import { entryWire, stubOneFeedReader } from './support/reader';

const ENTRIES = Array.from({ length: 30 }, (_, index) => entryWire({ id: index + 1 }));

test.describe("The list's sidebar circle on a phone", () => {
  test.use({ viewport: { width: 375, height: 667 }, isMobile: true, hasTouch: true });

  // #1952: the shell fills the circle into the list's corner slot, so a tap rule
  // scoped to the list missed it and the row underneath took the tap.
  test('opens the drawer, not the row under it', async ({ page }) => {
    await stubOneFeedReader(page, 'Fixture feed');
    await page.route(
      (url) => url.pathname === '/api/entries',
      (route) => route.fulfill({ json: { entries: ENTRIES, nextCursor: null } }),
    );
    await page.goto('/?subscription=1');
    await expect(
      page.locator('app-entry-list').getByText('Fixture entry 1', { exact: true }),
    ).toBeVisible();

    await page.locator('.corner-sidebar').click();

    await expect(page.locator('aside.sidebar')).toHaveClass(/\bopen\b/);
    await expect(page.locator('app-reader-view')).toHaveCount(0);
  });
});
