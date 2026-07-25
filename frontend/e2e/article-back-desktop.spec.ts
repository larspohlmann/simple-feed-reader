import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

// Wide enough for both shell breakpoints that matter here: the sidebar is a
// column (>720px) and the pane layout is allowed (>=900px). With the 260px
// sidebar the reading pane is ~1020px in overlay mode, comfortably past the
// 860px container query that lifts the back button into the left gutter.
const DESKTOP = { width: 1280, height: 900 };

/** Long enough that the article scrolls well past its own sticky toolbar. */
const BODY_HTML = Array.from(
  { length: 20 },
  (_, i) =>
    `<p>Paragraph ${i} of filler text, long enough to give the article real height so the reading pane can actually scroll.</p>`,
).join('');

const ENTRIES = Array.from({ length: 10 }, (_, i) => ({
  id: i + 1,
  title: `Entry number ${i + 1}`,
  url: `https://example.invalid/${i + 1}`,
  author: null,
  summary: 'A summary long enough to give the row some height. '.repeat(3),
  contentHtml: BODY_HTML,
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
  isRead: false,
  isFavorite: false,
  isKept: false,
}));

/**
 * Sign in with the layout pinned, so the test picks its shell branch instead of
 * inheriting whatever the previous run left in localStorage: 'magazine' (or
 * 'list') puts the article in a full-pane overlay whose back button lives in
 * the article itself, 'pane' splits the main area and gives the article its own
 * sticky toolbar. Both are desktop, and both have to stay clear of the app bar.
 */
async function signInAsAdmin(page: Page, layout: 'magazine' | 'pane'): Promise<boolean> {
  await page.addInitScript((mode) => localStorage.setItem('sfr.layout', mode), layout);
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
  // Force extraction to fail so the view stays in 'original' mode (the entry's
  // own contentHtml) instead of depending on a real outbound fetch of the
  // stubbed URL — the same reason header-scroll-mobile.spec.ts stubs it.
  await page.route('**/api/entries/*/reader', async (route) => {
    await route.fulfill({
      status: 200,
      json: { status: 'failed', url: null, reason: 'unextractable' },
    });
  });
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

/**
 * Wait for the layout to stop moving before measuring: the Material Symbols
 * webfont lands a beat after first paint and reflows the rows, and the article
 * overlay slides in over ~220ms.
 */
async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
}

/**
 * What the user's cursor would actually hit at the centre of `selector`.
 * `toBeVisible()` is not enough here: the app bar floats over the panes
 * (position: absolute, z-index 10), so a control can be laid out, painted and
 * "visible" to Playwright while the bar sits on top of it and swallows every
 * click. Returns whether the topmost element at that point is the control
 * itself or something inside it.
 */
async function hitAtCentre(page: Page, selector: string): Promise<string> {
  return page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return 'missing';
    const r = el.getBoundingClientRect();
    const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) as HTMLElement;
    if (!hit) return 'nothing';
    // Name whatever is on top, so a failure says which element is in the way.
    return el.contains(hit) ? 'self' : `${hit.tagName.toLowerCase()}.${hit.className}`;
  }, selector);
}

test.describe('Article back button on desktop', () => {
  test.use({ viewport: DESKTOP });

  // The full-pane article (magazine/list layout). Its back button is hung in
  // the article's left gutter by a container query, absolutely positioned
  // against the article panel — whose padding-box top edge is *above* the
  // padding that reserves the floating app bar's height. Positioned naively it
  // lands inside that reservation and the bar covers it (#97).
  test('the article’s own back button clears the app bar', async ({ page }) => {
    const signedIn = await signInAsAdmin(page, 'magazine');
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();
    await page.getByText('Entry number 1', { exact: false }).first().click();

    const back = page.locator('app-reader-view .back');
    await expect(back).toBeVisible();
    await settle(page);

    const bar = (await page.locator('app-reader-header header').boundingBox())!;
    const box = (await back.boundingBox())!;

    // Fully below the bar, not merely peeking out from under it.
    expect(box.y).toBeGreaterThanOrEqual(bar.y + bar.height);
    expect(await hitAtCentre(page, 'app-reader-view .back')).toBe('self');

    // And it does what it says: clicking returns to the list.
    await back.click();
    await expect(page.locator('app-reader-view')).toBeHidden();
  });

  // The split-pane article keeps its own toolbar, which sticks to the top of
  // the reading pane's scroller. That scroller passes under the floating app
  // bar by design, so the toolbar has to stick below the bar rather than at
  // the scroller's own top edge — otherwise scrolling slides the back button
  // (and prev/next) underneath it.
  test('the split-pane toolbar stays clear of the app bar while scrolling', async ({ page }) => {
    const signedIn = await signInAsAdmin(page, 'pane');
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();
    await page.getByText('Entry number 1', { exact: false }).first().click();

    const close = page.locator('app-reader-view .bar .close');
    await expect(close).toBeVisible();
    await settle(page);

    const bar = (await page.locator('app-reader-header header').boundingBox())!;
    expect((await close.boundingBox())!.y).toBeGreaterThanOrEqual(bar.y + bar.height);

    // Scroll the reading pane: the toolbar sticks, and must stick below the bar.
    await page.locator('app-reader-view').evaluate((el) => el.scrollTo({ top: 800 }));
    await page.waitForTimeout(200);

    expect((await close.boundingBox())!.y).toBeGreaterThanOrEqual(bar.y + bar.height);
    expect(await hitAtCentre(page, 'app-reader-view .bar .close')).toBe('self');

    await close.click();
    await expect(page.locator('app-reader-view .bar')).toBeHidden();
  });
});
