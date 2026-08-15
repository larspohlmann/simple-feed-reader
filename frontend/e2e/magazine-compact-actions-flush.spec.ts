// e2e/magazine-compact-actions-flush.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-smoke.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/**
 * Short titles on purpose. The bug this guards (#414) hid behind long ones: the
 * compact card's `.body` was shrink-to-fit, so its width was the width of its
 * widest line. A title that already filled the card left the actions looking
 * flush by accident, and only a short title exposed them stranded mid-card.
 */
function entry(id: number, source: string, subscriptionId: number) {
  return {
    id,
    title: `Short ${id}`,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: null,
    contentHtml: '<p>Fixture body.</p>',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: '2026-08-01T12:50:34+00:00',
    createdAt: '2026-08-01T12:50:34+00:00',
    subscriptionId,
    source,
    faviconUrl: null,
    isRead: false,
    isFavorite: false,
    isKept: false,
  };
}

/**
 * No images anywhere and a contentHtml stub well short of the planner's
 * quote-worthy length, so `isImageRich`/`isTextRich` both read false and the
 * IMAGE family is used, not the text one. That family authors no `compact`
 * slot directly — but every slot it does author (`hero`/`wide`/`split`/
 * `thumb`) demotes down `DEMOTION` to `compact` once it finds no image to
 * show, which is what actually puts every entry here into a compact card.
 *
 * `magazine-planner.ts`'s `subscriptionId` (not the display `source` string)
 * is what the run-collapse logic groups on, so the fixture keys off it: the
 * first eight entries share one `subscriptionId` — long enough to clear
 * `RUN_MIN` (8) — and three more entries each carry a distinct
 * `subscriptionId`, clearing `MIN_VIEW_SOURCES` (3) so collapsing is enabled
 * at all. That run folds into one `app-source-group`, whose `FEATURED_LEAD`
 * (3) leaves a five-entry tail previewed at `WIDGET_PREVIEW` (4) rows — so
 * both compact variants render: the standalone card, for the featured lead
 * and the three solo sources, and the grouped row inside the widget, which
 * hides its source and is the more interesting case: it has no tag pills, so
 * the actions sit alone on the kicker line.
 */
const ENTRIES = [
  entry(1, 'Heise', 2),
  entry(2, 'Heise', 2),
  entry(3, 'Heise', 2),
  entry(4, 'Heise', 2),
  entry(5, 'Heise', 2),
  entry(6, 'Heise', 2),
  entry(7, 'Heise', 2),
  entry(8, 'Heise', 2),
  entry(9, 'Golem', 1),
  entry(10, 'Tagesschau', 3),
  entry(11, 'Netzpolitik', 4),
];

/** Matched on the pathname so `/api/entries/{id}` still reaches the backend. */
async function stubEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await stubEntries(page);
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/**
 * The compact card hangs its actions off the kicker line with
 * `margin-inline-start: auto`, which resolves against the body's width — so the
 * body has to fill the card. It did not: it was the only magazine body without
 * `flex: 1`, which left the icons stranded at the end of the text instead of at
 * the card's edge (#414).
 *
 * Measured against the card's own content box, not a constant, so the test says
 * nothing about how wide a card is — only that the actions end where its
 * padding does. jsdom lays out no flexbox, so a unit test cannot see this at
 * all; it needs a real engine.
 */
test('the compact card flushes its actions to the card edge', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Both compact variants: the standalone card, and the grouped row a
  // collapsed source run renders inside `app-source-group` (which hides its
  // source, so it carries the `.no-source` modifier).
  const standalone = page.locator('app-entry-compact .compact:not(.no-source)');
  const grouped = page.locator('app-source-group app-entry-compact .compact');
  await expect(standalone.first()).toBeVisible();
  await expect(grouped.first()).toBeVisible();

  const cards = page.locator('app-entry-compact .compact');
  const measurements = await cards.evaluateAll((elements) =>
    elements.map((card) => {
      const actions = card.querySelector('app-entry-actions');
      if (!actions) return { hasActions: false, gap: null };
      const padding = parseFloat(getComputedStyle(card).paddingRight);
      // The gap between where the icons end and where the card's content box
      // ends. Zero means flush; the bug measured in the hundreds.
      const gap = Math.round(
        card.getBoundingClientRect().right - padding - actions.getBoundingClientRect().right,
      );
      return { hasActions: true, gap };
    }),
  );

  // Guards against a vacuous pass: `strays` below silently drops any card
  // missing its actions element, so a regression that dropped the element
  // entirely would otherwise measure nothing and pass by default.
  expect(measurements.length, 'expected compact cards were not found').toBeGreaterThan(0);
  expect(
    measurements.every((measurement) => measurement.hasActions),
    'every compact card must carry an app-entry-actions element',
  ).toBe(true);

  const strays = measurements
    .map((measurement) => measurement.gap)
    .filter((gap): gap is number => gap !== null && gap > 2);

  expect(strays, `compact actions left short of the card edge by (px): ${strays}`).toEqual([]);
});
