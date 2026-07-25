// e2e/header-scroll-mobile.spec.ts
import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const PHONE = { width: 375, height: 667 };

/**
 * Enough entries to scroll well past the point where the header retracts
 * (`HEADER_NEAR_TOP` is 40px). Stubbed rather than seeded: this measures
 * layout, and a fixed list keeps the row geometry deterministic.
 */
const ENTRIES = Array.from({ length: 30 }, (_, i) => ({
  id: i + 1,
  title: `Entry number ${i + 1}`,
  url: `https://example.invalid/${i + 1}`,
  author: null,
  summary: 'A summary long enough to give the row some height. '.repeat(3),
  contentHtml: '<p>body</p>',
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
  isRead: false,
  isFavorite: false,
  isKept: false,
}));

async function signInAsAdmin(page: Page): Promise<boolean> {
  // The default layout is magazine, which collapses a run of same-source
  // entries into a group and renders only three of them. Pin the flat list so
  // every stubbed entry is a row and the geometry is predictable.
  await page.addInitScript(() => localStorage.setItem('sfr.layout', 'list'));
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

async function stubEntries(page: Page): Promise<void> {
  await page.route('**/api/entries/*/state', async (route) => {
    await route.fulfill({
      status: 200,
      json: { state: { entryId: 1, isRead: true, isFavorite: false, isKept: false, readAt: 'x' } },
    });
  });
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
  });
}

/** The list's own scroll container — the element the shell listens to. */
const ROWS = '.rows';

/**
 * Wait for the layout to stop moving on its own before measuring. The Material
 * Symbols webfont lands a beat after first paint and reflows every row by about
 * a pixel; measured too early, that shows up as ~10px of drift across 30 rows
 * and reads exactly like the bug under test.
 */
async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
}

test.describe('Hide-on-scroll header on a phone', () => {
  test.use({ viewport: PHONE });

  test('retracting the header does not move the content', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    const anchor = page.getByText('Entry number 12', { exact: false }).first();
    await expect(anchor).toBeVisible();
    await settle(page);

    const header = page.locator('app-reader-header');
    const headerBefore = (await header.boundingBox())!;

    // Scroll far enough to retract the header, and let the 0.2s transition end.
    const before = (await anchor.boundingBox())!;
    await rows.evaluate((el) => el.scrollBy(0, 300));
    await page.waitForTimeout(400);
    const after = (await anchor.boundingBox())!;

    // The header really did retract — otherwise this test proves nothing.
    const headerAfter = (await header.boundingBox())!;
    expect(headerAfter.y).toBeLessThan(headerBefore.y - 20);

    // The row moved by the scroll distance and by nothing else. Before the fix
    // the header's collapse handed the content area ~96px of extra height and
    // the row travelled that much further.
    expect(after.y).toBeCloseTo(before.y - 300, 0);
  });

  test('expanding the header again does not move the content', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    await settle(page);
    await rows.evaluate((el) => el.scrollBy(0, 400));
    await page.waitForTimeout(400);

    const anchor = page.getByText('Entry number 12', { exact: false }).first();
    const before = (await anchor.boundingBox())!;
    // Scrolling up expands the header again.
    await rows.evaluate((el) => el.scrollBy(0, -100));
    await page.waitForTimeout(400);
    const after = (await anchor.boundingBox())!;

    expect(after.y).toBeCloseTo(before.y + 100, 0);
  });

  // The article renders as an overlay over the still-mounted list, and its
  // scrolling host is transparent on purpose so a swipe-away reveals that list.
  // Anything the article reserves at its top therefore has to be reserved on
  // the opaque panel inside it, or the list shows through the gap.
  test('an open article is opaque all the way to the top', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();
    await expect(page.locator(ROWS)).toBeVisible();
    await settle(page);

    await page.getByText('Entry number 1', { exact: false }).first().click();
    const host = page.locator('app-reader-view');
    const panel = host.locator('.reader');
    await expect(panel).toBeVisible();

    const hostBox = (await host.boundingBox())!;
    const panelBox = (await panel.boundingBox())!;
    expect(panelBox.y).toBeCloseTo(hostBox.y, 0);
    expect(panelBox.height).toBeGreaterThanOrEqual(hostBox.height - 1);
  });

  test('the content area keeps its height while the header slides', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    await settle(page);
    const height = () => rows.evaluate((el) => el.clientHeight);

    const before = await height();
    await rows.evaluate((el) => el.scrollBy(0, 300));
    await page.waitForTimeout(400);
    expect(await height()).toBe(before);
  });
});
