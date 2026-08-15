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
function entry(id: number, source: string) {
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
    subscriptionId: 1,
    source,
    faviconUrl: null,
    isRead: false,
    isFavorite: false,
    isKept: false,
  };
}

/**
 * No images anywhere, so the planner picks its text family — the one whose
 * templates actually contain `compact`. Repeating one source gives the planner
 * a run to fold into a source group, so both compact variants render: the
 * standalone card, and the grouped row that hides its source.
 */
const ENTRIES = [
  entry(1, 'Golem'),
  entry(2, 'Heise'),
  entry(3, 'Heise'),
  entry(4, 'Heise'),
  entry(5, 'Heise'),
  entry(6, 'Tagesschau'),
  entry(7, 'Golem'),
  entry(8, 'Netzpolitik'),
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

  const cards = page.locator('app-entry-compact .compact');
  await expect(cards.first()).toBeVisible();

  const strays = await cards.evaluateAll((elements) =>
    elements
      .map((card) => {
        const actions = card.querySelector('app-entry-actions');
        if (!actions) return null;
        const padding = parseFloat(getComputedStyle(card).paddingRight);
        // The gap between where the icons end and where the card's content box
        // ends. Zero means flush; the bug measured in the hundreds.
        return Math.round(
          card.getBoundingClientRect().right - padding - actions.getBoundingClientRect().right,
        );
      })
      .filter((gap): gap is number => gap !== null && gap > 2),
  );

  expect(strays, `compact actions left short of the card edge by (px): ${strays}`).toEqual([]);
});
