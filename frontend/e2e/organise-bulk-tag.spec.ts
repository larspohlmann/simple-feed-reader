// e2e/organise-bulk-tag.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-smoke.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const TAGS = [
  { id: 1, name: 'Nachrichten', color: null, icon: null, position: 0 },
  { id: 2, name: 'Tech', color: null, icon: null, position: 1 },
];

function feed(id: number, title: string, tagIds: number[]) {
  return {
    id,
    feedId: id,
    title,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://fixtures.invalid/${id}/rss`,
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-01-01T00:00:00Z',
    lastFetchedAt: null,
    position: id,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: TAGS.find((t) => t.id === tagId)!.name,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  };
}

const SUBSCRIPTIONS = [
  feed(10, 'Fixture taz', [1]),
  feed(11, 'Fixture heise', []),
  feed(12, 'Fixture Golem', []),
];

/**
 * Stub the Organise page's own data so this spec owns what it asserts on: it
 * must pass on a fresh database and leave nothing behind, per the project's
 * e2e standing rule (see `magazine-kicker-one-line.spec.ts`).
 */
async function stubFixture(page: Page): Promise<void> {
  await page.route('**/api/tags', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { tags: TAGS } });
  });
  await page.route('**/api/subscriptions', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({
      status: 200,
      json: {
        subscriptions: SUBSCRIPTIONS,
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
      },
    });
  });
}

/**
 * Sign in through the real login form with the seeded admin credentials.
 * Returns `false` (rather than failing) when the credentials are rejected, so
 * a stack without the seeded admin — or a rate-limited login — skips cleanly
 * instead of flaking. Mirrors `magazine-smoke.spec.ts`'s helper.
 */
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

/**
 * Check a feed row's selection box, retrying the click if it doesn't stick.
 *
 * A row freshly revealed by "Expand all" sits inside a `cdkDrag` host whose
 * pointer listeners `@angular/cdk/drag-drop` attaches via `afterNextRender`
 * (see `CdkDrag.ngAfterViewInit`) — one render pass after the checkbox itself
 * is already visible and Playwright considers it actionable. A click that
 * lands in that gap is silently swallowed: the box never toggles. A real
 * pointer always arrives at least a frame later (mouse travel alone takes
 * longer), so this is a test-speed artifact, not a user-facing bug — but it
 * makes a same-tick click after "Expand all" flaky. Retry instead of a fixed
 * sleep: it succeeds on whichever attempt lands after that render pass.
 */
async function checkFeed(page: Page, title: string): Promise<void> {
  const box = page.getByLabel(`Select ${title}`);
  await expect(async () => {
    await box.click();
    // Short inner timeout: a swallowed click never becomes checked on its
    // own, so this must fail fast and hand control back to `toPass` for
    // another click rather than exhausting the outer budget on one wait.
    await expect(box).toBeChecked({ timeout: 200 });
  }).toPass({ timeout: 2000 });
}

test('adds a tag to two selected feeds in one request', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await stubFixture(page);

  let body: unknown = null;
  await page.route('**/api/subscriptions/bulk', async (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback();
    body = route.request().postDataJSON();
    await route.fulfill({ status: 200, json: { subscriptions: [] } });
  });

  await page.goto('/settings/organise');
  await page.getByRole('button', { name: 'Expand all' }).click();

  // Order matters: the bulk write threads the selection through in the order
  // the checkboxes were checked (insertion order of a `Set`), not sorted by
  // id or by list position.
  await checkFeed(page, 'Fixture heise');
  await checkFeed(page, 'Fixture Golem');
  await expect(page.locator('[data-test="bulk-count"]')).toContainText('2');

  await page.getByRole('button', { name: 'Add tag…' }).click();

  // Scoped to the dialog, not `page.getByRole('button', { name: /Tech/ })`:
  // the tree's own "Show or hide Tech" toggle behind the dialog matches that
  // name too, and a locator that resolves before the dialog has rendered its
  // pill can latch onto the toggle instead — it then sits blocked by the
  // dialog's backdrop for the rest of the action's timeout.
  const dialog = page.getByRole('dialog', { name: 'Add a tag to 2 feeds' });
  await dialog.getByRole('button', { name: 'Tech', exact: false }).click();
  await dialog.locator('[data-test="apply"]').click();

  await expect.poll(() => body).toEqual({ subscriptionIds: [11, 12], addTagIds: [2] });
});
