import { Page, expect, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';

// #561: the picker's category rail must satisfy two things at once — never grow
// past one screenful (so its last row stays reachable), and stay pinned for the
// whole scroll (so it does not slide away when the categories are few).
//
// Both had been broken, in opposite directions, and fixing one naively breaks
// the other: the rail's cap and its sticky travel were both measured against
// `.cols`, and those two want that box to be different heights. Hence a test
// per direction — a long catalog and a short one.
//
// The catalog is stubbed, so both read the same on any database.

/** Long enough to overflow the ~500px scrollport at this viewport. */
const MANY = 23;
/** Short enough that the rail fits, which is what strands a sticky box. */
const FEW = 5;

/** A 1x1 transparent GIF, so the picker's favicons never leave the page. */
const BLANK_ICON = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

function catalog(categories: number, feedsEach = 1) {
  return {
    categories: Array.from({ length: categories }, (_, index) => ({
      id: index + 1,
      name: `Category ${index + 1}`,
      icon: 'memory',
      color: '#3b82f6',
      feeds: Array.from({ length: feedsEach }, (_, feed) => ({
        id: (index + 1) * 100 + feed,
        title: `Feed ${index + 1}-${feed}`,
        description: 'A stubbed catalog feed',
        faviconUrl: BLANK_ICON,
        subscribed: false,
      })),
    })),
  };
}

async function openPicker(page: Page, categories: number, feedsEach = 1): Promise<void> {
  await stubAuthToken(page);
  await page.route('**/api/catalog', (route) =>
    route.fulfill({ json: catalog(categories, feedsEach) }),
  );
  await page.goto('/discover');
  await expect(page.getByRole('navigation', { name: 'Categories' })).toBeVisible();
}

test.use({ viewport: { width: 1280, height: 700 } });

test('every category is reachable when the rail overflows', async ({ page }) => {
  await openPicker(page, MANY);

  const rail = page.getByRole('navigation', { name: 'Categories' });
  const lastCategory = rail.getByRole('button', { name: `Category ${MANY}` });

  // The host is the scroller. Drive it to its end: if the rail sized itself
  // correctly this reaches the last row, and if it overran the scrollport
  // instead, scrollTop stays 0 because it believes it has no overflow.
  await page.locator('app-category-rail').evaluate((element) => {
    element.scrollTop = element.scrollHeight;
  });

  // ratio 1, not merely "some part of it": a row clipped by the scrollport edge
  // is still unusable, and that is the state the bug left the last rows in.
  await expect(lastCategory).toBeInViewport({ ratio: 1 });
});

test('a short rail stays pinned while the sections scroll past', async ({ page }) => {
  // Twelve feeds each, so the sections are long even though the rail is not.
  await openPicker(page, FEW, 12);

  const body = page.locator('app-overlay-panel .body');
  await body.evaluate((element) => {
    element.scrollTop = element.scrollHeight;
  });

  const rail = page.getByRole('navigation', { name: 'Categories' });
  await expect(rail.getByRole('button', { name: 'Category 1' })).toBeInViewport({ ratio: 1 });
});
