import { expect, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';

// #561, mobile half: the chips strip is the ONLY way to reach a category on a
// phone — the rail is hidden below the breakpoint. As a single nowrap strip,
// 23 categories ran to eight screenfuls of sideways swiping with nothing on
// screen to suggest more existed, and the 13th category went unfound on the
// live instance. Wrapped rows make the set scannable and reachable.

const CATEGORY_COUNT = 23;
/** The one deliberately placed out of the first screenful. */
const BURIED = 13;

const BLANK_ICON = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

const CATALOG = {
  categories: Array.from({ length: CATEGORY_COUNT }, (_, index) => ({
    id: index + 1,
    name: index + 1 === BURIED ? 'Psychedelics & Consciousness' : `Category ${index + 1}`,
    icon: 'memory',
    color: '#3b82f6',
    feeds: [
      {
        id: (index + 1) * 100,
        title: `Feed ${index + 1}`,
        description: 'A stubbed catalog feed',
        faviconUrl: BLANK_ICON,
        subscribed: false,
      },
    ],
  })),
};

test.use({ viewport: { width: 390, height: 720 }, hasTouch: true, isMobile: true });

test('a category late in the list is reachable from the chips on a phone', async ({ page }) => {
  await stubAuthToken(page);
  await page.route('**/api/catalog', (route) => route.fulfill({ json: CATALOG }));
  await page.goto('/discover');

  const chips = page.locator('app-category-chips .chips');
  await chips.waitFor();

  // Wrapped, not a sideways strip: no horizontal overflow at all.
  const horizontal = await chips.evaluate((el) => el.scrollWidth - el.clientWidth);
  expect(horizontal).toBe(0);

  // The whole set is at most a few flicks away, rather than eight screenfuls.
  const flicks = await chips.evaluate((el) => Math.ceil(el.scrollHeight / el.clientHeight));
  expect(flicks).toBeLessThanOrEqual(4);

  // Reachable, which is the whole complaint — not "at the end". The buried
  // category sits mid-strip, so scrolling to the bottom would overshoot it.
  const buried = page.getByRole('button', { name: /Psychedelics/ });
  await buried.scrollIntoViewIfNeeded();
  await expect(buried).toBeInViewport({ ratio: 1 });

  // And it navigates: tapping a chip is the point of the strip existing.
  await buried.click();
  await expect(page.getByRole('group', { name: 'Psychedelics & Consciousness' })).toBeInViewport();
});
