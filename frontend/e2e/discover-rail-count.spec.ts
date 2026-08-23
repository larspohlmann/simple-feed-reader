import { expect, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';

// #569: a two-digit count in the category rail broke across two lines ("13"
// as "1" over "3") on exactly the rows whose name was long enough to truncate.
//
// `_base.scss` sets `overflow-wrap: anywhere` globally, which by design lowers
// an element's min-content width; `.count` never opted back out with
// `white-space: nowrap`, so the flex algorithm was free to squeeze it until the
// number itself broke.

const BLANK_ICON = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/** Long enough to truncate in the 220px rail, which is what starts the squeeze. */
const LONG_NAME = 'Psychedelics & Consciousness';

const CATALOG = {
  categories: [
    {
      id: 1,
      name: LONG_NAME,
      icon: 'memory',
      color: '#3b82f6',
      // A two-digit count is the case that wrapped; one digit cannot.
      feeds: Array.from({ length: 26 }, (_, feed) => ({
        id: 100 + feed,
        title: `Feed ${feed}`,
        description: 'A stubbed catalog feed',
        faviconUrl: BLANK_ICON,
        subscribed: false,
      })),
    },
  ],
};

test.use({ viewport: { width: 1280, height: 700 } });

test('a two-digit count stays on one line beside a truncated name', async ({ page }) => {
  await stubAuthToken(page);
  await page.route('**/api/catalog', (route) => route.fulfill({ json: CATALOG }));
  await page.goto('/discover');

  const row = page.getByRole('navigation', { name: 'Categories' }).getByRole('button').first();
  await expect(row).toBeVisible();

  const geometry = await row.evaluate((button) => {
    const name = button.querySelector('.name') as HTMLElement;
    const count = button.querySelector('.count') as HTMLElement;
    return {
      countHeight: Math.round(count.getBoundingClientRect().height),
      nameHeight: Math.round(name.getBoundingClientRect().height),
      countText: count.textContent?.trim(),
      // Truncation is the precondition; if the name stopped truncating the
      // test would pass for the wrong reason.
      nameTruncated: name.scrollWidth > name.clientWidth,
    };
  });

  expect(geometry.countText).toBe('26');
  expect(geometry.nameTruncated).toBe(true);
  // Same font-size as the name, so one line each: a wrapped count is twice this.
  expect(geometry.countHeight).toBeLessThanOrEqual(geometry.nameHeight);
});
