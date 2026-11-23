import { test, expect, Page } from '@playwright/test';
import type { SocialAccountRefDto } from '../src/app/reader/social-feed';
import { aSubscription } from '../src/testing/subscription-testing';
import { makeTag } from '../src/app/reader/testing/tag.factory';
import { presetLocalStorage, signInAsAdmin } from './support/auth';
import { entryWire, savedSearchesJson, savedSearchWire, subscriptionsJson } from './support/reader';

type SidebarStyle = 'boxed' | 'airy';

const NEWS = makeTag(1, 'News', { position: 0 });

/** Four-digit counts on every head, so the Mastodon head carries its widest row: '+', bell, count, ⋯, chevron. */
const SUBSCRIPTIONS = [
  aSubscription({ id: 1, title: 'The Verge', unreadCount: 1204, entryCount: 1204, tags: [NEWS] }),
  aSubscription({ id: 2, title: 'Daring Fireball', position: 1, unreadCount: 3, entryCount: 3 }),
  aSubscription({
    id: 3,
    title: 'Mastodon Home',
    unreadCount: 1873,
    entryCount: 1873,
    social: { network: 'mastodon', kind: 'home', accountId: 7 },
  }),
];

const SOCIAL_ACCOUNTS: SocialAccountRefDto[] = [
  {
    id: 7,
    network: 'mastodon',
    handle: '@reader@social.example',
    status: 'connected',
    serviceUrl: 'https://social.example',
  },
];

const TOTALS = {
  socialAccounts: SOCIAL_ACCOUNTS,
  notifications: [{ network: 'mastodon' as const, unseenCount: 3 }],
  unreadTotals: {
    ...subscriptionsJson(SUBSCRIPTIONS).unreadTotals,
    networks: [{ network: 'mastodon' as const, unreadCount: 1873, profilesUnreadCount: 0 }],
  },
};

/**
 * Everything the sidebar draws is stubbed, so the spec owns the rows it measures. `/api/me` is
 * rewritten, not replaced (the shell reads roles and mail state from it), and follows a stubbed
 * preferences `PATCH`, so a later `loadMe()` does not hand the old style back.
 */
async function stubSidebar(page: Page, initialStyle: SidebarStyle): Promise<void> {
  let sidebarStyle = initialStyle;
  const getOnly = (body: () => unknown) => (route: import('@playwright/test').Route) =>
    route.request().method() === 'GET' ? route.fulfill({ json: body() }) : route.fallback();

  await page.route(
    (url) => url.pathname === '/api/me',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const response = await route.fetch();
      const profile = await response.json();
      await route.fulfill({
        response,
        json: { ...profile, preferences: { ...profile.preferences, sidebarStyle } },
      });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/me/preferences',
    async (route) => {
      if (route.request().method() !== 'PATCH') return route.fallback();
      const change = route.request().postDataJSON() as { sidebarStyle?: SidebarStyle };
      sidebarStyle = change.sidebarStyle ?? sidebarStyle;
      await route.fulfill({ status: 204 });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/subscriptions',
    getOnly(() => subscriptionsJson(SUBSCRIPTIONS, TOTALS)),
  );
  await page.route(
    (url) => url.pathname === '/api/subscriptions/counts',
    getOnly(() => ({
      ...subscriptionsJson(SUBSCRIPTIONS, TOTALS),
      subscriptions: SUBSCRIPTIONS.map(({ id, unreadCount, entryCount }) => ({
        id,
        unreadCount,
        entryCount,
      })),
    })),
  );
  await page.route(
    (url) => url.pathname === '/api/tags',
    getOnly(() => ({ tags: [NEWS] })),
  );
  await page.route(
    (url) => url.pathname === '/api/saved-searches',
    getOnly(() => savedSearchesJson(savedSearchWire({ id: 1, term: 'climate' }))),
  );
  await page.route(
    (url) => url.pathname === '/api/trending/followed',
    getOnly(() => ({ stories: [], nextBuildDueAt: null })),
  );
  await page.route(
    (url) => url.pathname === '/api/entries',
    getOnly(() => ({
      entries: [entryWire({ id: 1 }), entryWire({ id: 2 }), entryWire({ id: 3 })],
      nextCursor: null,
    })),
  );
}

async function openReader(page: Page, style: SidebarStyle): Promise<boolean> {
  await stubSidebar(page, style);
  await presetLocalStorage(page, { 'sfr.theme': 'light', 'sfr.layout': 'magazine' });
  const signedIn = await signInAsAdmin(page);
  if (signedIn) {
    await expect(page.getByTestId('sidebar-social-mastodon')).toBeVisible();
    await page.evaluate(() => document.fonts.ready);
  }
  return signedIn;
}

/** The colour a token resolves to, read through a probe so it compares like for like. */
async function tokenColor(page: Page, token: string): Promise<string> {
  return page.evaluate((name) => {
    const probe = document.createElement('div');
    probe.style.backgroundColor = `var(${name})`;
    document.querySelector('aside.sidebar')!.appendChild(probe);
    const color = getComputedStyle(probe).backgroundColor;
    probe.remove();
    return color;
  }, token);
}

async function bottomBorderEdge(page: Page, selector: string): Promise<number> {
  return page
    .locator(selector)
    .first()
    .evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return rect.bottom - Number.parseFloat(getComputedStyle(element).borderBottomWidth);
    });
}

const SKIP = 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)';

test.describe('the airy sidebar on a desktop', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('draws one sheet: the search band meets the list header rule, no card is boxed', async ({
    page,
  }) => {
    test.skip(!(await openReader(page, 'airy')), SKIP);

    const aside = page.locator('aside.sidebar');
    await expect(aside).toHaveClass(/\bairy\b/);
    expect(await aside.evaluate((element) => getComputedStyle(element).backgroundColor)).toBe(
      await tokenColor(page, '--surface-2'),
    );

    const search = page.locator('.search-card');
    expect((await search.boundingBox())!.height).toBe(56);
    const searchRule = await bottomBorderEdge(page, '.search-card');
    const headerRule = await bottomBorderEdge(page, '.list-header');
    expect(
      Math.abs(searchRule - headerRule),
      'the search rule runs into the header rule',
    ).toBeLessThanOrEqual(0.5);

    const boxed = await page
      .locator('aside.sidebar .card')
      .evaluateAll(
        (cards) =>
          cards
            .map((card) => getComputedStyle(card))
            .filter(
              (style) =>
                style.backgroundImage !== 'none' ||
                style.backgroundColor !== 'rgba(0, 0, 0, 0)' ||
                style.borderTopLeftRadius !== '0px',
            ).length,
      );
    expect(boxed, 'a card still draws a fill or a rounded box').toBe(0);

    const mastodon = page.getByTestId('sidebar-social-mastodon').locator('.label');
    await expect(mastodon).toHaveText('Mastodon');
    const fits = await mastodon.evaluate((label) => label.scrollWidth <= label.clientWidth);
    expect(fits, '"Mastodon" is truncated').toBe(true);
  });

  test('boxed keeps its filled cards', async ({ page }) => {
    test.skip(!(await openReader(page, 'boxed')), SKIP);

    await expect(page.locator('aside.sidebar')).not.toHaveClass(/\bairy\b/);
    const background = await page
      .locator('aside.sidebar .card')
      .first()
      .evaluate((card) => getComputedStyle(card).backgroundImage);
    expect(background, 'a boxed card lost its fill').not.toBe('none');
  });

  test('picking Airy in Settings redraws the sidebar without a reload', async ({ page }) => {
    test.skip(!(await openReader(page, 'boxed')), SKIP);
    await expect(page.locator('aside.sidebar')).not.toHaveClass(/\bairy\b/);
    await page.evaluate(() => ((window as unknown as { unreloaded: boolean }).unreloaded = true));

    await page.locator('.account > button[aria-haspopup="menu"]').click();
    await page.getByRole('menuitem', { name: 'Settings' }).click();
    await page.getByRole('link', { name: 'Preferences' }).first().click();
    await page
      .getByRole('group', { name: 'Sidebar style' })
      .getByRole('button', { name: 'Airy' })
      .click();
    await page.locator('app-settings-shell a.back').click();

    await expect(page.locator('aside.sidebar')).toHaveClass(/\bairy\b/);
    expect(
      await page.evaluate(() => (window as unknown as { unreloaded?: boolean }).unreloaded),
      'the page reloaded',
    ).toBe(true);
  });
});

test.describe('the airy sidebar on a phone', () => {
  test.use({ viewport: { width: 390, height: 760 }, isMobile: true, hasTouch: true });

  test('keeps every section head a tap target tall', async ({ page }) => {
    test.skip(!(await openReader(page, 'airy')), SKIP);

    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    const aside = page.locator('aside.sidebar');
    await expect(aside).toHaveClass(/\bairy\b/);
    await expect(page.getByRole('navigation', { name: 'Feeds' })).toBeVisible();

    const tapTarget = await aside.evaluate((element) =>
      Number.parseFloat(getComputedStyle(element).getPropertyValue('--tap-target')),
    );
    const heights = await aside
      .locator('.section-head')
      .evaluateAll((heads) => heads.map((head) => head.getBoundingClientRect().height));
    expect(heights.length).toBeGreaterThan(0);
    for (const height of heights) expect(height).toBeGreaterThanOrEqual(tapTarget);
  });
});
