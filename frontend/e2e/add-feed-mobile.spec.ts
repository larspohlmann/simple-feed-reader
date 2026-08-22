// e2e/add-feed-mobile.spec.ts
import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/** A phone in portrait — the viewport where #85 was reported. */
const PHONE = { width: 375, height: 667 };

async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

const SITE = 'https://many-feeds.sfr-e2e.example/';
const CANDIDATES = [
  { url: 'https://many-feeds.sfr-e2e.example/rss', title: 'Main feed', format: 'rss' },
  { url: 'https://many-feeds.sfr-e2e.example/atom', title: 'Main feed (Atom)', format: 'atom' },
  { url: 'https://many-feeds.sfr-e2e.example/comments', title: 'Comments', format: 'rss' },
  { url: 'https://many-feeds.sfr-e2e.example/news', title: 'News only', format: 'rss' },
];

/**
 * Discovery with several candidates cannot be produced against a live host —
 * UrlGuard (SSRF protection) refuses locally served fixtures and real sites are
 * non-deterministic — so the two feed endpoints are stubbed. Everything under
 * test here is client-side layout, so nothing of value is mocked away: the real
 * component, the real stylesheet and a real browser doing real layout.
 */
async function stubDiscovery(page: Page): Promise<void> {
  await page.route('**/api/subscriptions', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    const url = (route.request().postDataJSON() as { url: string }).url;
    // The site URL discovers candidates; a candidate's own URL subscribes.
    const body =
      url === SITE
        ? { candidates: CANDIDATES }
        : { subscription: { id: 4242, title: 'News only' } };
    await route.fulfill({ status: url === SITE ? 200 : 201, json: body });
  });

  await page.route('**/api/feeds/preview', async (route) => {
    const url = (route.request().postDataJSON() as { url: string }).url;
    await route.fulfill({
      status: 200,
      json: {
        feed: {
          title: `Preview of ${url}`,
          itemCount: 12,
          content: 'full',
          hasImages: true,
          // Eight sample rows per card is what makes the expanded card's
          // list outgrow the screen, so the dialog body actually scrolls.
          items: Array.from({ length: 8 }, (_unused, index) => ({
            title: `Sample headline ${index + 1}`,
            url: `https://example.com/a${index + 1}`,
            author: null,
            summary: 'A short snippet of the article body.',
            imageUrl: 'https://img.example/a.jpg',
            imageWidth: 800,
            imageHeight: 600,
            publishedAt: '2026-08-20T10:00:00+00:00',
          })),
        },
      },
    });
  });
}

/**
 * "Add feed" lives in the sidebar, which is a swipe-in drawer at this width, so
 * the drawer has to be opened first.
 */
async function openAddFeedDialog(page: Page) {
  await page.getByRole('button', { name: 'Toggle sidebar' }).click();
  await page.getByRole('button', { name: 'Add feed' }).click();
  const dialog = page.getByRole('dialog', { name: 'Add a feed' });
  await expect(dialog).toBeVisible();
  return dialog;
}

test.describe('Add feed on a phone', () => {
  test.use({ viewport: PHONE });

  test('every candidate can be subscribed, including the last one', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubDiscovery(page);

    const dialog = await openAddFeedDialog(page);
    const field = dialog.getByRole('textbox', { name: 'Feed or site URL' });
    await expect(field).toBeFocused();
    await field.fill(SITE);
    await dialog.getByRole('button', { name: 'Add' }).click();

    const subscribes = dialog.getByRole('button', { name: 'Subscribe' });
    await expect(subscribes).toHaveCount(CANDIDATES.length);

    // Several candidates come back, so none auto-expands: expand the first
    // one and confirm its sample entries render as preview rows.
    await dialog.locator('.card-head').first().click();
    await expect(dialog.locator('app-preview-entry-row').first()).toBeVisible();

    // The dialog itself must fit the screen. Before the fix it was ~1.5x the
    // viewport, and the overflow had nowhere to go.
    const box = (await dialog.boundingBox())!;
    expect(box.height).toBeLessThanOrEqual(PHONE.height);
    expect(box.width).toBeLessThanOrEqual(PHONE.width);
    expect(box.y).toBeGreaterThanOrEqual(0);

    // The dialog's body, not the page, is what scrolls: it must actually be
    // overflowing here, or this test would pass for the wrong reason. The body
    // is app-overlay-panel's — every interrupt surface shares that one frame
    // and its one scroll region (#126), so the candidate list itself no longer
    // scrolls on its own. `.first()`: the expanded candidate's own
    // app-preview-entry-row rows each carry a `.body` class too, and they
    // nest inside the panel's, so the panel's is first in document order.
    const list = dialog.locator('.body').first();
    const { scrollHeight, clientHeight } = await list.evaluate((el) => ({
      scrollHeight: el.scrollHeight,
      clientHeight: el.clientHeight,
    }));
    expect(scrollHeight).toBeGreaterThan(clientHeight);

    // The last card's Subscribe is reachable by scrolling the list, and works.
    const last = subscribes.last();
    await last.scrollIntoViewIfNeeded();
    const lastBox = (await last.boundingBox())!;
    expect(lastBox.y).toBeGreaterThanOrEqual(box.y);
    expect(lastBox.y + lastBox.height).toBeLessThanOrEqual(box.y + box.height);

    await last.click();
    await expect(dialog).toBeHidden();
  });

  test('the heading and Cancel stay put while the dialog body scrolls', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubDiscovery(page);

    const dialog = await openAddFeedDialog(page);
    await dialog.getByRole('textbox', { name: 'Feed or site URL' }).fill(SITE);
    await dialog.getByRole('button', { name: 'Add' }).click();
    await expect(dialog.getByRole('button', { name: 'Subscribe' })).toHaveCount(CANDIDATES.length);

    const cancel = dialog.getByRole('button', { name: 'Cancel' });
    const before = (await cancel.boundingBox())!;
    await dialog.locator('.body').evaluate((el) => el.scrollTo(0, el.scrollHeight));
    const after = (await cancel.boundingBox())!;
    expect(after.y).toBeCloseTo(before.y, 0);

    await cancel.click();
    await expect(dialog).toBeHidden();
  });
});
