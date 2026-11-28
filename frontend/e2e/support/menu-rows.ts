import { expect, Locator, Page } from '@playwright/test';
import { aSubscription } from '../../src/testing/subscription-testing';
import { DESKTOP, PHONE, Surface } from './first-line';
import { openArticle, SCORED_POST, stubArticle } from './icon-rows';
import { subscriptionsJson } from './reader';

const SOCIAL_ACCOUNTS = [
  {
    id: 1,
    network: 'bluesky' as const,
    handle: '@lead.bsky.social',
    status: 'connected' as const,
    serviceUrl: 'https://bsky.social',
  },
];

/** The article reader, with a connected Bluesky account (a hinted share item) and unseen notifications. */
export async function stubMenus(page: Page): Promise<void> {
  await stubArticle(page, { post: SCORED_POST });
  const body = subscriptionsJson(
    [aSubscription({ title: 'Fixture feed', unreadCount: 1, entryCount: 1 })],
    { socialAccounts: SOCIAL_ACCOUNTS, notifications: [{ network: 'bluesky', unseenCount: 4 }] },
  );
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) => route.fulfill({ json: body }));
}

export async function openAccountMenu(page: Page): Promise<Locator> {
  await page.locator('.account > button').click();
  const menu = page.locator('.account .menu');
  await expect(menu).toBeVisible();
  return menu;
}

const POPOVER_MENU = '.cdk-overlay-container .menu[role="menu"]';

async function openArticleShare(page: Page): Promise<void> {
  await openArticle(page);
  await page.locator('app-reader-view app-share-button').getByRole('button').first().click();
}

async function expectPopover(page: Page): Promise<Locator> {
  const menu = page.locator(POPOVER_MENU);
  await expect(menu).toBeVisible();
  return menu;
}

/** The share popover of an open article, on a fine pointer. */
export async function openShareMenu(page: Page): Promise<Locator> {
  await openArticleShare(page);
  return expectPopover(page);
}

/** The share action sheet of an open article, on a coarse pointer. */
export async function openShareSheet(page: Page): Promise<Locator> {
  await openArticleShare(page);
  const sheet = page.locator('app-action-sheet nav');
  await expect(sheet).toBeVisible();
  return sheet;
}

/** The repost popover of the article's first comment, on a fine pointer. */
export async function openRepostMenu(page: Page): Promise<Locator> {
  await openArticle(page);
  await page.locator('app-reader-view button.repost').first().click();
  return expectPopover(page);
}

export const MENU_FINE: Surface = { viewport: DESKTOP, stub: stubMenus, path: '/?subscription=1' };
export const MENU_COARSE: Surface = { ...MENU_FINE, viewport: PHONE, touch: true };
