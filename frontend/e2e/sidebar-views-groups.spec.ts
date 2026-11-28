import { expect, Locator, Page, test } from '@playwright/test';
import type { SubscriptionDto } from '../src/app/reader/models';
import type { LabelTalliesResponse } from '../src/app/reader/labels/labels.models';
import type { FollowedStoriesResponseDto } from '../src/app/reader/trending/trending.models';
import { makeTag } from '../src/app/reader/testing/tag.factory';
import { aSubscription } from '../src/testing/subscription-testing';
import { presetLocalStorage, stubAccountProfile } from './support/auth';
import { expectNear, measureToken } from './support/first-line';
import { savedSearchWire, stubOneFeedReader, subscriptionsJson } from './support/reader';

/**
 * #2593: the sidebar's views stand in two groups whose rows share one regular-weight look, one counter
 * column and indented sublists, boxed and airy alike. Every read is stubbed.
 */
type SidebarStyle = 'boxed' | 'airy';

const NEWS = makeTag(1, 'News', { position: 0 });
const SUBSCRIPTIONS: SubscriptionDto[] = [
  aSubscription({ id: 1, title: 'The Verge', unreadCount: 1204, entryCount: 1204, tags: [NEWS] }),
];

const LABELS: LabelTalliesResponse = {
  labels: [{ id: 1, name: 'Read later', favoriteCount: 1, keptCount: 0, articleCount: 1 }],
  unlabeled: { favoriteCount: 52, keptCount: 33 },
};

const FOLLOWED: FollowedStoriesResponseDto = {
  stories: [
    {
      id: 9,
      headline: 'A followed story',
      newCount: 2,
      quiet: false,
      lastJoinedAt: '2026-11-18T07:00:00Z',
    },
  ],
  nextBuildDueAt: null,
};

/** Every view the groups can show, or none of the optional ones. */
interface Views {
  everyView: boolean;
}

async function stubViews(page: Page, style: SidebarStyle, { everyView }: Views): Promise<void> {
  await stubOneFeedReader(
    page,
    'Fixture feed',
    everyView ? [savedSearchWire({ id: 1, term: 'climate' })] : [],
  );
  const base = subscriptionsJson(SUBSCRIPTIONS);
  const subscriptions = subscriptionsJson(SUBSCRIPTIONS, {
    favoritesCount: 53,
    keptCount: 33,
    sharedCount: 2,
    likedCount: 3,
    viewedCount: 788,
    trending: { window: 'day', unopened: everyView ? 8 : 0 },
    yourStream: { available: everyView, unreadCount: 18, entryCount: 18 },
    unreadTotals: { ...base.unreadTotals, forYou: 46 },
  });
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: subscriptions }),
  );
  await page.route(/\/api\/tags(?:[?#]|$)/, (route) => route.fulfill({ json: { tags: [NEWS] } }));
  await page.route(/\/api\/labels(?:[?#]|$)/, (route) => route.fulfill({ json: LABELS }));
  await page.route(
    (url) => url.pathname === '/api/trending/followed',
    (route) =>
      route.fulfill({ json: everyView ? FOLLOWED : { stories: [], nextBuildDueAt: null } }),
  );
  await stubAccountProfile(
    page,
    { sidebarStyle: style, trendingEnabled: everyView },
    { profile: { ai: { ready: everyView, model: everyView ? 'stub' : null, capabilities: null } } },
  );
  await presetLocalStorage(page, { 'sfr.theme': 'light', 'sfr.layout': 'magazine' });
}

/** Opens the sidebar (the drawer on a phone) and waits for the given views to stand. */
async function openSidebar(page: Page, style: SidebarStyle, views: Views): Promise<Locator> {
  await stubViews(page, style, views);
  await page.goto('/');
  if (page.viewportSize()!.width < 768) {
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
  }
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar.getByRole('link', { name: /^All items/ })).toBeVisible();
  const aside = page.locator('aside.sidebar');
  if (style === 'airy') await expect(aside).toHaveClass(/\bsidebar-airy\b/);
  else await expect(aside).not.toHaveClass(/\bsidebar-airy\b/);
  if (views.everyView) {
    await expect(sidebar.getByRole('link', { name: /Trending/ })).toBeVisible();
    await expect(sidebar.getByRole('link', { name: /Your stream/ })).toBeVisible();
    await expect(sidebar.locator('.followed-head')).toBeVisible();
  }
  await page.evaluate(() => document.fonts.ready);
  return sidebar;
}

/** Opens All items, Favorites, Saved searches and Followed stories through their chevrons. */
async function expandEveryHead(sidebar: Locator): Promise<void> {
  for (const name of [
    'Toggle All items',
    'Show or hide the labels in Favorites',
    'Toggle saved searches',
    'Show or hide followed stories',
  ]) {
    await sidebar.getByRole('button', { name, exact: true }).click();
    await expect(sidebar.getByRole('button', { name, exact: true })).toHaveAttribute(
      'aria-expanded',
      'true',
    );
  }
  await expect(sidebar.locator('.savedsearch-item')).toBeVisible();
}

/** A view head with a sublist under it: its host, so the head and the sublist are the host's. */
const HOSTS = {
  'All items': 'app-sidebar-views[section="all-items"]',
  Favorites: 'app-sidebar-collection[collection="favorites"]',
  'Saved searches': 'app-sidebar-saved-searches',
  'Followed stories': 'app-sidebar-followed-stories',
};

const VIEWPORTS = {
  desktop: { viewport: { width: 1280, height: 1100 } },
  phone: { viewport: { width: 390, height: 1100 }, isMobile: true, hasTouch: true },
};

for (const [device, use] of Object.entries(VIEWPORTS)) {
  for (const style of ['boxed', 'airy'] as const) {
    test.describe(`the ${style} views on a ${device}`, () => {
      test.use(use);

      test('every counter in both groups ends at the same x, a tag row’s', async ({ page }) => {
        const sidebar = await openSidebar(page, style, { everyView: true });
        await expandEveryHead(sidebar);
        const rights = await sidebar
          .locator('.views-group :is(.count, .section-count)')
          .evaluateAll((counts) =>
            counts.map((count) => ({
              text: count.closest('.folder-head, .nav, .label-row, .followed-row')!.textContent,
              right: count.getBoundingClientRect().right,
            })),
          );
        expect(rights.length, 'counters to compare').toBeGreaterThanOrEqual(12);
        const tagRow = await sidebar
          .locator('.taghead .count')
          .evaluate((count) => count.getBoundingClientRect().right);
        for (const { text, right } of rights) expectNear(right, tagRow, `${text?.trim()}`);
      });

      test('no view row is bold but the current one', async ({ page }) => {
        const sidebar = await openSidebar(page, style, { everyView: true });
        await expandEveryHead(sidebar);
        const weights = await sidebar
          .locator('.views-group :is(.label, .name)')
          .evaluateAll((names) =>
            names.map((name) => ({
              text: name.textContent!.trim(),
              current: name.closest('.current, .active') !== null,
              weight: getComputedStyle(name).fontWeight,
            })),
          );
        expect(weights.filter(({ current }) => !current).length).toBeGreaterThanOrEqual(12);
        for (const { text, current, weight } of weights) {
          expect(weight, text).toBe(current ? '600' : '400');
        }
        const allItems = weights.find(({ text }) => text === 'All items');
        expect(allItems?.current, 'All items is the current view').toBe(true);
      });

      test('the groups are two cards with nothing between Kept and All items', async ({ page }) => {
        const sidebar = await openSidebar(page, style, { everyView: true });
        await expandEveryHead(sidebar);
        const groups = sidebar.locator('.views-group');
        await expect(groups).toHaveCount(2);
        const first = await groups.nth(0).evaluate((group) => {
          const box = group.getBoundingClientRect();
          const rows = [...group.querySelectorAll('.folder-head, .nav, .label-row')];
          return {
            box: { top: box.top, bottom: box.bottom, left: box.left, right: box.right },
            heads: [...group.querySelectorAll('.collection-head')].map((head) => {
              const rect = head.getBoundingClientRect();
              return { top: rect.top, bottom: rect.bottom, left: rect.left, right: rect.right };
            }),
            borders: rows.flatMap((row) => {
              const rowStyle = getComputedStyle(row);
              return ['Top', 'Bottom'].map((side) => ({
                row: row.textContent!.trim(),
                width: parseFloat(rowStyle.getPropertyValue(`border-${side.toLowerCase()}-width`)),
                color: rowStyle.getPropertyValue(`border-${side.toLowerCase()}-color`),
              }));
            }),
            ruleColor: getComputedStyle(group).borderTopColor,
          };
        });
        const secondRule = await groups
          .nth(1)
          .evaluate((group) => getComputedStyle(group).borderTopColor);

        expect(first.heads).toHaveLength(2);
        for (const head of first.heads) {
          expect(head.top).toBeGreaterThanOrEqual(first.box.top);
          expect(head.bottom).toBeLessThanOrEqual(first.box.bottom);
          expect(head.left).toBeGreaterThanOrEqual(first.box.left);
          expect(head.right).toBeLessThanOrEqual(first.box.right);
        }
        for (const { row, width, color } of first.borders) {
          expect(width === 0 || color === 'rgba(0, 0, 0, 0)', `${row} draws a border`).toBe(true);
        }
        if (style === 'airy') {
          expect(secondRule, 'the second group’s rule').not.toBe('rgba(0, 0, 0, 0)');
          expect(first.ruleColor, 'a rule under the search band').toBe('rgba(0, 0, 0, 0)');
        }
      });

      test('an expanded head keeps the row inset', async ({ page }) => {
        const sidebar = await openSidebar(page, style, { everyView: true });
        await expandEveryHead(sidebar);
        await page.mouse.move(page.viewportSize()!.width - 1, page.viewportSize()!.height - 1);
        const [favorites, kept] = await sidebar.locator('.collection-head').evaluateAll((heads) =>
          heads.map((head) => ({
            left: head.getBoundingClientRect().left,
            height: head.getBoundingClientRect().height,
            background: getComputedStyle(head).backgroundColor,
          })),
        );
        expectNear(favorites!.left, kept!.left, 'Favorites against Kept');
        expectNear(favorites!.height, kept!.height, 'Favorites’ height against Kept’s');
        expect(favorites!.background).toBe('rgba(0, 0, 0, 0)');
      });

      test('every view sublist hangs indented on a hairline', async ({ page }) => {
        const sidebar = await openSidebar(page, style, { everyView: true });
        await expandEveryHead(sidebar);
        const indent = await measureToken(sidebar, 'var(--space-5)');
        for (const [name, host] of Object.entries(HOSTS)) {
          const hang = await page.locator(host).evaluate((element) => {
            const head = element.querySelector('.folder-head')!;
            const sublist = element.querySelector('.sublist')!;
            const firstRow = sublist.firstElementChild!;
            const rail = getComputedStyle(sublist, '::before');
            return {
              rowLeft: firstRow.getBoundingClientRect().left,
              headLeft: head.getBoundingClientRect().left,
              headIcon: head.querySelector('app-icon')!.getBoundingClientRect().left,
              rowIcon: firstRow.querySelector('app-icon')!.getBoundingClientRect().left,
              railWidth: parseFloat(rail.borderLeftWidth),
              railColor: rail.borderLeftColor,
            };
          });
          expectNear(hang.rowLeft - hang.headLeft, indent, `${name}: the sublist's indent`);
          expect(hang.rowIcon - hang.headIcon, `${name}: icon steps in`).toBeGreaterThan(
            indent / 2,
          );
          expect(hang.railWidth, `${name}: rail`).toBeGreaterThan(0);
          expect(hang.railColor, `${name}: rail colour`).not.toBe('rgba(0, 0, 0, 0)');
        }
      });

      test('an empty second group draws nothing', async ({ page }) => {
        const sidebar = await openSidebar(page, style, { everyView: false });
        const groups = sidebar.locator('.views-group');
        await expect(groups).toHaveCount(2);
        await expect(groups.nth(1)).toBeHidden();
      });
    });
  }
}
