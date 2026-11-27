import { expect, Page, test } from '@playwright/test';
import type {
  NotificationGroupDto,
  NotificationsPageDto,
} from '../src/app/reader/notifications/notification.models';
import type { SocialAccountRefDto } from '../src/app/reader/social-feed';
import { aSubscription } from '../src/testing/subscription-testing';
import { presetLocalStorage } from './support/auth';
import { stubOneFeedReader, subscriptionsJson } from './support/reader';

/**
 * #2240: each network's sidebar card head carries a notifications bell with the
 * unseen count, the list groups what happened, and the account menu and avatar
 * carry the unseen count.
 * Every API read is stubbed, so the spec owns the counts it asserts on.
 */
test.use({ viewport: { width: 1440, height: 860 } });

const SUBSCRIPTIONS = [
  aSubscription({
    id: 1,
    title: 'Bluesky Home',
    unreadCount: 41,
    entryCount: 41,
    social: { network: 'bluesky', kind: 'home', accountId: 1 },
  }),
  aSubscription({
    id: 2,
    title: 'Mastodon Home',
    social: { network: 'mastodon', kind: 'home', accountId: 2 },
  }),
  aSubscription({
    id: 3,
    title: 'Lemmy Subscribed',
    social: { network: 'lemmy', kind: 'all', accountId: 3 },
  }),
];

const SOCIAL_ACCOUNTS: SocialAccountRefDto[] = [
  {
    id: 1,
    network: 'bluesky',
    handle: '@lars.bsky.social',
    status: 'connected',
    serviceUrl: 'https://bsky.social',
  },
  {
    id: 2,
    network: 'mastodon',
    handle: '@lars@social.example',
    status: 'connected',
    serviceUrl: 'https://social.example',
  },
  {
    id: 3,
    network: 'lemmy',
    handle: '@lars@lemmy.example',
    status: 'connected',
    serviceUrl: 'https://lemmy.example',
  },
];

const UNSEEN = [
  { network: 'bluesky' as const, unseenCount: 4 },
  { network: 'mastodon' as const, unseenCount: 2 },
];

const NONE_UNSEEN = [
  { network: 'bluesky' as const, unseenCount: 0 },
  { network: 'mastodon' as const, unseenCount: 0 },
];

function actor(displayName: string, handle: string) {
  return {
    handle,
    displayName,
    avatarUrl: null,
    profileUrl: `https://bsky.app/profile/${handle}`,
  };
}

function group(overrides: Partial<NotificationGroupDto> & Pick<NotificationGroupDto, 'id'>) {
  return {
    kind: 'like',
    account: '@lars.bsky.social',
    actors: [actor('Anna Example', 'anna.bsky.social')],
    actorCount: 1,
    excerpt: null,
    answeredExcerpt: null,
    threadTitle: null,
    threadPlace: null,
    threadBy: null,
    onComment: false,
    linkUrl: `https://bsky.app/post/${overrides.id}`,
    entryId: null,
    latestAt: '2026-11-16T10:00:00+00:00',
    seen: true,
    ...overrides,
  } satisfies NotificationGroupDto;
}

const BLUESKY_PAGE: NotificationsPageDto = {
  groups: [
    group({
      id: 40,
      kind: 'like',
      actors: [
        actor('Hank Green', 'hank.bsky.social'),
        actor('Jay Graber', 'jay.bsky.social'),
        actor('Third Person', 'third.bsky.social'),
      ],
      actorCount: 7,
      seen: false,
    }),
    group({ id: 31, kind: 'follow', seen: false }),
    group({ id: 22, kind: 'reply', excerpt: 'A fixture reply.' }),
    group({ id: 13, kind: 'repost' }),
  ],
  nextCursor: null,
  scopeMissing: [],
  needsReconnect: [],
};

const MASTODON_PAGE: NotificationsPageDto = {
  groups: [group({ id: 8, kind: 'mention', account: '@lars@social.example', seen: false })],
  nextCursor: null,
  scopeMissing: [],
  needsReconnect: [],
};

const UNREAD_TOTALS = {
  allItems: 41,
  forYou: 0,
  tags: [],
  networks: [{ network: 'bluesky', unreadCount: 51, profilesUnreadCount: 0 }],
  groups: { tags: 0, feeds: 0 },
};

async function stubNotifications(page: Page, unseen = UNSEEN) {
  const seenBodies: unknown[] = [];
  const shown = () => (seenBodies.length > 0 ? NONE_UNSEEN : unseen);
  const totals = () => ({
    favoritesCount: 0,
    keptCount: 0,
    sharedCount: 0,
    likedCount: 0,
    viewedCount: 0,
    trending: { window: 'day', unopened: 0 },
    unreadTotals: UNREAD_TOTALS,
    notifications: shown(),
  });

  await stubOneFeedReader(page, 'Fixture feed');
  await page.route('**/api/client-errors', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/*/excluded-networks', (route) =>
    route.fulfill({ json: { excludedNetworks: [] } }),
  );
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({
      json: { ...subscriptionsJson(SUBSCRIPTIONS), ...totals(), socialAccounts: SOCIAL_ACCOUNTS },
    }),
  );
  await page.route(/\/api\/subscriptions\/counts(?:[?#]|$)/, (route) =>
    route.fulfill({
      json: {
        ...totals(),
        subscriptions: SUBSCRIPTIONS.map(({ id, unreadCount, entryCount }) => ({
          id,
          unreadCount,
          entryCount,
        })),
      },
    }),
  );
  await page.route(/\/api\/me\/notifications(?:[?#]|$)/, (route) => {
    const network = new URL(route.request().url()).searchParams.get('network');
    return route.fulfill({
      json: network === 'mastodon' ? MASTODON_PAGE : BLUESKY_PAGE,
    });
  });
  await page.route('**/api/me/notifications/seen', (route) => {
    seenBodies.push(route.request().postDataJSON());
    return route.fulfill({ status: 204 });
  });
  return seenBodies;
}

async function openReader(page: Page, path = '/', layout = 'pane') {
  await presetLocalStorage(page, { 'sfr.layout': layout, 'sfr.lang': 'en' });
  await page.goto(path);
}

function networkCard(page: Page, network: string) {
  return page.locator('app-sidebar-social-section', {
    has: page.getByTestId(`sidebar-social-${network}`),
  });
}

async function box(locator: ReturnType<Page['locator']>) {
  const rect = await locator.boundingBox();
  if (rect === null) {
    throw new Error('element has no bounding box');
  }
  return rect;
}

test('the network head carries a bell with the unseen count', async ({ page }) => {
  await stubNotifications(page);
  await openReader(page);

  const bluesky = networkCard(page, 'bluesky');
  const bell = bluesky.locator('app-sidebar-notifications-bell');
  await expect(bell.locator('.badge')).toHaveText('4');
  const lemmyBell = networkCard(page, 'lemmy').locator('app-sidebar-notifications-bell');
  await expect(lemmyBell).toBeVisible();
  await expect(lemmyBell.locator('.badge')).toHaveCount(0);
  await expect(bluesky.locator('.section-count')).toHaveText('51');

  const bellBox = await box(bell);
  const labelBox = await box(bluesky.locator('.label'));
  const countBox = await box(bluesky.locator('.section-count'));
  expect(bellBox.x).toBeGreaterThanOrEqual(labelBox.x + labelBox.width);
  expect(bellBox.x + bellBox.width).toBeLessThanOrEqual(countBox.x);
});

test('the list shows the grouped notifications', async ({ page }) => {
  await stubNotifications(page);
  await openReader(page, '/', 'list');

  await networkCard(page, 'bluesky').locator('app-sidebar-notifications-bell a').click();

  await expect(page).toHaveURL(/view=notifications/);
  await expect(page).toHaveURL(/network=bluesky/);
  const list = page.locator('app-notification-list');
  const header = list.locator('app-list-header');
  await expect(header).toContainText('Notifications');
  await expect(header.locator('app-network-logo')).toBeVisible();
  await expect(header.locator('.refresh')).toBeVisible();
  await expect(header.locator('.mark-seen')).toBeVisible();
  await expect(header.locator('.list-order')).toHaveCount(0);
  await expect(header.locator('.unread-switch')).toHaveCount(0);

  const rows = list.locator('app-notification-row');
  await expect(rows).toHaveCount(4);
  await expect(rows.first().locator('.line')).toHaveText(
    'Hank Green, Jay Graber and 5 others liked your post',
  );
  await expect(list.locator('.dot')).toHaveCount(2);
  await expect(rows.first().locator('a.row')).toHaveAttribute('target', '_blank');
  await expect(list.locator('.end')).toHaveText("That's everything from the last 30 days.");

  const rowBox = await box(rows.first());
  const columnBox = await box(list.locator('.rows'));
  expect(rowBox.width).toBeGreaterThanOrEqual(600);
  expect(rowBox.width).toBeLessThanOrEqual(680);
  expect(
    Math.abs(rowBox.x + rowBox.width / 2 - (columnBox.x + columnBox.width / 2)),
  ).toBeLessThanOrEqual(2);
});

test('Mark all seen posts the newest shown position and clears the dots', async ({ page }) => {
  const seenBodies = await stubNotifications(page);
  await openReader(page, '/?view=notifications&network=bluesky');

  const list = page.locator('app-notification-list');
  await expect(list.locator('.dot')).toHaveCount(2);
  const bluesky = networkCard(page, 'bluesky');
  await expect(bluesky.locator('app-sidebar-notifications-bell .badge')).toHaveText('4');

  await list.locator('.mark-seen').click();

  await expect(list.locator('.dot')).toHaveCount(0);
  expect(seenBodies).toEqual([{ network: 'bluesky', upTo: 40 }]);
  await expect(bluesky.locator('app-sidebar-notifications-bell .badge')).toHaveCount(0);
});

test('the account menu lists only networks with unseen notifications', async ({ page }) => {
  await stubNotifications(page);
  await openReader(page);

  await page.locator('.account > button').click();
  const menu = page.locator('.account .menu');
  const notifications = menu.getByRole('group', { name: 'Notifications' });
  await expect(notifications.getByRole('menuitem')).toHaveCount(2);
  await expect(notifications.getByRole('menuitem', { name: /Bluesky/ })).toContainText('4');
  await expect(notifications.getByRole('menuitem', { name: /Mastodon/ })).toContainText('2');
  await expect(notifications.getByRole('menuitem', { name: /Lemmy/ })).toHaveCount(0);

  await notifications.getByRole('menuitem', { name: /Mastodon/ }).click();

  await expect(page).toHaveURL(/network=mastodon/);
  await expect(page).toHaveURL(/view=notifications/);
  await expect(menu).toHaveCount(0);
});

test('the account menu has no Notifications group when nothing is unseen', async ({ page }) => {
  await stubNotifications(page, NONE_UNSEEN);
  await openReader(page);

  await page.locator('.account > button').click();

  await expect(page.locator('.account .menu')).toBeVisible();
  await expect(page.locator('.account .menu').getByRole('group')).toHaveCount(0);
});

test('the avatar carries the unseen total', async ({ page }) => {
  await stubNotifications(page);
  await openReader(page);

  await expect(page.locator('.account .badge')).toHaveText('6');
});

test('the avatar carries no badge when nothing is unseen', async ({ page }) => {
  await stubNotifications(page, NONE_UNSEEN);
  await openReader(page);

  await expect(page.locator('.account button')).toBeVisible();
  await expect(page.locator('.account .badge')).toHaveCount(0);
});
