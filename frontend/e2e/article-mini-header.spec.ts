// e2e/article-mini-header.spec.ts
import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const PHONE = { width: 375, height: 667 };
/** Past the 900px `isWide` boundary, so the article renders in the split pane. */
const DESKTOP = { width: 1280, height: 800 };

/** Far wider than a phone, so the one-line rule is what has to cut it. */
const LONG_TITLE =
  'A headline of the sort a broadsheet writes when it wants every qualifier in the first line';

const BODY = Array.from(
  { length: 25 },
  (_, i) =>
    `<p>Paragraph ${i + 1}. ${'Long enough to take a few lines on a phone. '.repeat(3)}</p>`,
).join('');

const entry = (title: string) => ({
  id: 1,
  title,
  url: 'https://example.invalid/1',
  author: null,
  summary: 'summary',
  contentHtml: BODY,
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
  isRead: false,
  isFavorite: false,
  isKept: false,
});

async function signInAsAdmin(page: Page): Promise<boolean> {
  // Pin the flat list: the default magazine layout groups same-source entries
  // and would not give this stub a plain row to click.
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

/** One article, extraction failing so the stubbed body is what renders. */
async function stubArticle(page: Page, title: string): Promise<void> {
  await page.route('**/api/entries/*/reader', async (route) =>
    route.fulfill({ status: 200, json: { status: 'failed', reason: 'fetch', url: null } }),
  );
  // Echo the patch back, the way the API does: the store takes the response as
  // the new truth, so a stub with fixed flags would swallow every toggle.
  await page.route('**/api/entries/*/state', async (route) => {
    const patch = (route.request().postDataJSON() ?? {}) as Record<string, boolean>;
    await route.fulfill({
      status: 200,
      json: {
        state: {
          entryId: 1,
          isRead: true,
          isFavorite: false,
          isKept: false,
          readAt: 'x',
          ...patch,
        },
      },
    });
  });
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: [entry(title)], nextCursor: null } });
  });
}

/** Open the stubbed article and wait for its body to render. */
async function openArticle(page: Page, title: string) {
  await page.getByText(title.slice(0, 30), { exact: false }).first().click();
  const pane = page.locator('app-reader-view');
  await expect(pane.locator('.content p').first()).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300); // slide-in animation + first focus pass
  return pane;
}

test.describe('Article mini header on a phone', () => {
  test.use({ viewport: PHONE });

  // #270: the toolbar retracts and the headline scrolls away with the body, so
  // a scrolled-down article named nothing at all. This strip has to survive
  // exactly the scroll that takes the toolbar away.
  test('the strip holds the top edge while the toolbar retracts behind it', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, 'Entry number one');
    await page.reload();
    const pane = await openArticle(page, 'Entry number one');

    const mini = pane.locator('.mini');
    const bar = pane.locator('.bar');
    const paneTop = (await pane.boundingBox())!.y;
    const before = (await mini.boundingBox())!;
    expect(before.y).toBeCloseTo(paneTop, 0);
    await expect(bar).not.toHaveClass(/hidden/);

    // Scroll well past HEADER_NEAR_TOP, then let the 0.2s transform settle.
    await pane.evaluate((el) => el.scrollTo({ top: 600, behavior: 'instant' }));
    await page.waitForTimeout(400);

    // The toolbar really did retract — otherwise this proves nothing.
    await expect(bar).toHaveClass(/hidden/);
    const barBox = (await bar.boundingBox())!;
    const after = (await mini.boundingBox())!;

    // The strip has not moved a pixel, and still names the article.
    expect(after.y).toBeCloseTo(paneTop, 0);
    expect(after.height).toBeCloseTo(before.height, 0);
    await expect(mini.locator('.mini-title')).toHaveText('Entry number one');

    // And the toolbar went behind it rather than beside it: its lower edge is
    // no further down the screen than the strip's own.
    expect(barBox.y + barBox.height).toBeLessThanOrEqual(after.y + after.height + 1);
  });

  test('a long title is cut to one line instead of widening the pane', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, LONG_TITLE);
    await page.reload();
    const pane = await openArticle(page, LONG_TITLE);

    const mini = pane.locator('.mini');
    const title = mini.locator('.mini-title');

    // Truncated: there is more text than the box shows.
    const overflow = await title.evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(overflow).toBeGreaterThan(0);

    // One line, and the strip stays inside the phone. A flex item whose floor is
    // its content width would instead push the strip wide and scroll the pane
    // sideways, which is the failure this pins down.
    const box = (await mini.boundingBox())!;
    expect(box.height).toBeLessThanOrEqual(32);
    expect(box.width).toBeLessThanOrEqual(PHONE.width);
    expect(await pane.evaluate((el) => el.scrollWidth - el.clientWidth)).toBe(0);
  });
});

test.describe('Article mini header on the split pane', () => {
  test.use({ viewport: DESKTOP });

  // The wide layout never retracts its toolbar, so the name rides inside that
  // toolbar rather than in a strip of its own. The headline still scrolls away
  // with the body, which is what the name is there to replace.
  test('the toolbar carries the name, and keeps carrying it down the article', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, 'Entry number one');
    await page.reload();
    const pane = await openArticle(page, 'Entry number one');

    const appBar = page.locator('app-reader-header');
    const bar = pane.locator('.bar');

    await expect(pane.locator('.mini')).toHaveCount(0);
    await expect(bar.locator('.bar-title')).toHaveText('Entry number one');

    await pane.evaluate((el) => el.scrollTo({ top: 600, behavior: 'instant' }));
    await page.waitForTimeout(300);

    // Flush under the app bar, with no band of article showing between them,
    // and still there once the headline itself has scrolled away.
    const appBarBox = (await appBar.boundingBox())!;
    const barBox = (await bar.boundingBox())!;
    expect(barBox.y).toBeCloseTo(appBarBox.y + appBarBox.height, 0);
    await expect(bar.locator('.bar-title')).toBeVisible();
    await expect(bar).not.toHaveClass(/hidden/);
  });

  test('the toolbar offers favourite and keep', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
    await stubArticle(page, 'Entry number one');
    await page.reload();
    const pane = await openArticle(page, 'Entry number one');

    const favourite = pane.locator('.bar [aria-label="Favorite"]');
    await expect(favourite).toBeVisible();
    await expect(pane.locator('.bar [aria-label="Keep"]')).toBeVisible();

    // Marking from the toolbar reaches the same state the article's row shows.
    await favourite.click();
    await expect(favourite).toHaveClass(/on/);
    await expect(pane.locator('.actions [aria-label="Favorite"]')).toHaveClass(/on/);
  });
});
