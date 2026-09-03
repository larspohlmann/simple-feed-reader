import { test, expect, Page } from '@playwright/test';
import { readerFailedJson } from './support/reader';

// Same seeded admin as the other reader specs (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

// Wide enough for the split pane: the sidebar is a column (>720px) and the wide
// layout is active (>=900px), so `sfr.layout = pane` splits the main area (#810).
const DESKTOP = { width: 1280, height: 900 };

const ENTRIES = Array.from({ length: 8 }, (_, i) => ({
  id: i + 1,
  title: `Entry number ${i + 1}`,
  url: `https://example.invalid/${i + 1}`,
  author: null,
  summary: 'A summary long enough to give the row some height. '.repeat(3),
  contentHtml: '<p>Body.</p>',
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
  isHidden: false,
  isFavorite: false,
  isKept: false,
}));

/** Sign in with the pane layout pinned, so the split is on screen regardless of
 *  what the previous run left in localStorage. */
async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.addInitScript(() => localStorage.setItem('sfr.layout', 'pane'));
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

async function stubEntries(page: Page): Promise<void> {
  await page.route('**/api/entries/*/reader', async (route) => {
    await route.fulfill({ status: 200, json: readerFailedJson('unextractable') });
  });
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
  });
}

/** The list-column width the divider writes onto `main.split`, as a number. */
async function listPercent(page: Page): Promise<number> {
  const raw = await page
    .locator('main.main.split')
    .evaluate((el) => getComputedStyle(el).getPropertyValue('--list-width').trim());
  return parseFloat(raw);
}

/** Poll the width to a whole percent: the divider writes `--list-width` through
 *  an Angular effect, so a read right after a key press can still see the old
 *  value for a frame. */
async function expectPercentNear(page: Page, value: number): Promise<void> {
  await expect.poll(async () => Math.round(await listPercent(page))).toBe(value);
}

/** Drag the divider by `deltaX` device pixels from its own centre. */
async function dragDivider(page: Page, deltaX: number): Promise<void> {
  const box = (await page.locator('.pane-divider').boundingBox())!;
  const centreX = box.x + box.width / 2;
  const centreY = box.y + box.height / 2;
  await page.mouse.move(centreX, centreY);
  await page.mouse.down();
  await page.mouse.move(centreX + deltaX, centreY, { steps: 8 });
  await page.mouse.up();
}

test.describe('split-pane resize handle (#810)', () => {
  test.use({ viewport: DESKTOP });

  test('drags the divider, persists the width, and restores it on reload', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const divider = page.locator('.pane-divider');
    await expect(divider).toHaveAttribute('role', 'separator');
    await expect(divider).toHaveAttribute('aria-orientation', 'vertical');

    await expectPercentNear(page, 42);

    // Drag right to widen the list, then confirm the CSS var moved with it and
    // the choice was written to localStorage under its own key.
    await dragDivider(page, 160);
    await expect.poll(() => listPercent(page)).toBeGreaterThan(42);
    const after = Math.round(await listPercent(page));

    const stored = await page.evaluate(() => localStorage.getItem('sfr.paneSplit'));
    expect(Math.round(parseFloat(stored ?? ''))).toBe(after);

    // The width survives a reload, from localStorage alone.
    await page.reload();
    await expectPercentNear(page, after);
  });

  test('double-click resets to the default and arrow keys nudge the split', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const divider = page.locator('.pane-divider');
    await dragDivider(page, 160);
    await expect.poll(() => listPercent(page)).toBeGreaterThan(42);

    await divider.dblclick();
    await expectPercentNear(page, 42);
    await expect.poll(() => page.evaluate(() => localStorage.getItem('sfr.paneSplit'))).toBe('42');

    // Keyboard parity: the handle is focusable and the arrows step the split.
    await divider.focus();
    await page.keyboard.press('ArrowLeft');
    await expectPercentNear(page, 40);
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await expectPercentNear(page, 44);
  });
});
