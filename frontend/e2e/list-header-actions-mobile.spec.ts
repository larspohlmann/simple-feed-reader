import { expect, Page, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';

const PHONE = { width: 375, height: 667 };

const SUBSCRIPTIONS = {
  subscriptions: [
    {
      id: 1,
      feedId: 1,
      title: 'Design feeds',
      faviconUrl: null,
      imageUrl: null,
      description: null,
      customTitle: null,
      feedUrl: 'https://fixtures.invalid/feed.xml',
      siteUrl: null,
      status: 'active',
      sourceFormat: 'xml',
      createdAt: '2026-08-28T00:00:00+00:00',
      lastFetchedAt: '2026-08-28T00:00:00+00:00',
      position: 0,
      tags: [],
      unreadCount: 1,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
  viewedCount: 0,
};

async function openReader(page: Page, query: string): Promise<void> {
  await stubAuthToken(page);
  const json = (body: unknown) => (route: { fulfill: (response: { json: unknown }) => unknown }) =>
    route.fulfill({ json: body });

  await page.route('**/api/subscriptions**', json(SUBSCRIPTIONS));
  await page.route('**/api/tags**', json({ tags: [] }));
  await page.route('**/api/entries**', json({ entries: [], nextCursor: null }));
  await page.route('**/api/me**', json({ id: 1, email: '', roles: [], preferences: {} }));
  await page.route('**/api/version**', json({ version: 'dev' }));
  await page.route('**/api/recommendations/**', json({ run: null }));
  await page.route('**/api/saved-searches**', json({ savedSearches: [] }));

  await page.goto(`/?${query}`);
  await expect(page.locator('.list-header')).toBeVisible();
}

test.describe('list-header actions on a phone', () => {
  test.use({ viewport: PHONE, hasTouch: true });

  test('icon-only actions use the sidebar chevron border and a full tap target', async ({
    page,
  }) => {
    await openReader(page, 'subscription=1');

    const actions = page.locator(
      '.list-header :is(.list-edit, .unread-switch, .mark-all, .refresh)',
    );
    await expect(actions).toHaveCount(4);

    const geometry = await actions.evaluateAll((elements) =>
      elements.map((element) => {
        const target = element.getBoundingClientRect();
        const icon = element.querySelector('app-icon');
        if (!icon) throw new Error('list action has no icon');
        const style = getComputedStyle(icon);
        return {
          targetWidth: target.width,
          targetHeight: target.height,
          borderWidth: style.borderTopWidth,
          borderStyle: style.borderTopStyle,
          borderRadius: parseFloat(style.borderTopLeftRadius),
        };
      }),
    );

    for (const action of geometry) {
      expect(action.targetWidth).toBeGreaterThanOrEqual(44);
      expect(action.targetHeight).toBeGreaterThanOrEqual(44);
      expect(action.borderWidth).toBe('1px');
      expect(action.borderStyle).toBe('solid');
      expect(action.borderRadius).toBeGreaterThan(0);
    }
  });

  test('actions with a visible mobile label keep the borderless link treatment', async ({
    page,
  }) => {
    await openReader(page, 'q=design');

    const labelledActions = page.locator('.list-header :is(.mark-all, .save-search)');
    await expect(labelledActions).toHaveCount(2);

    for (const action of await labelledActions.all()) {
      await expect(action.locator('.txt-short')).toBeVisible();
      await expect(action.locator('app-icon')).toHaveCSS('border-top-width', '0px');
    }
  });
});

test.describe('list-header actions on desktop', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test('keep their borderless text-link treatment', async ({ page }) => {
    await openReader(page, 'subscription=1');

    const actions = page.locator(
      '.list-header :is(.list-edit, .unread-switch, .mark-all, .refresh)',
    );
    await expect(actions).toHaveCount(4);

    for (const action of await actions.all()) {
      await expect(action.locator('.txt')).toBeVisible();
      await expect(action.locator('app-icon')).toHaveCSS('border-top-width', '0px');
    }
  });
});
