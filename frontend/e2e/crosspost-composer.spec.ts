import { expect, Page, test } from '@playwright/test';
import type { ComposerContextDto, PublishedPostDto } from '../src/app/reader/entry-social';
import type { SocialAccountRefDto } from '../src/app/reader/social-feed';
import { presetLocalStorage } from './support/auth';
import {
  entryDetailJson,
  entryWire,
  oneFeedJson,
  readerFailedJson,
  stubOneFeedReader,
} from './support/reader';

/**
 * #2287: the share menu's composer crossposts. Bluesky goes out once, Mastodon
 * fails, and its retry sends to Mastodon alone. Every API read is stubbed, so
 * the spec owns the accounts and the composer it asserts on.
 */
const ENTRY = entryWire({ id: 1, title: 'A story worth sharing' });

const ACCOUNTS: SocialAccountRefDto[] = [
  {
    id: 11,
    network: 'bluesky',
    handle: 'ada.bsky.invalid',
    status: 'connected',
    serviceUrl: 'https://bsky.invalid',
  },
  {
    id: 12,
    network: 'mastodon',
    handle: 'ada@social.invalid',
    status: 'connected',
    serviceUrl: 'https://social.invalid',
  },
];

function composerContext(network: 'bluesky' | 'mastodon'): ComposerContextDto {
  const account = ACCOUNTS.find((each) => each.network === network)!;
  return {
    kind: 'link',
    accounts: [{ id: account.id, handle: account.handle }],
    accountId: account.id,
    canSend: true,
    maxCharacters: network === 'bluesky' ? 300 : 500,
    visibility: network === 'mastodon' ? 'public' : null,
    text: '',
    target: {
      kind: 'link',
      card: {
        url: ENTRY.url,
        title: ENTRY.title,
        description: null,
        imageUrl: null,
        siteName: 'fixtures.invalid',
      },
    },
  };
}

function published(network: string): PublishedPostDto {
  return { address: `${network}-post`, url: `https://${network}.invalid/post/1` };
}

async function stubShareableEntry(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: { ...oneFeedJson('Fixture feed'), socialAccounts: ACCOUNTS } }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [ENTRY], nextCursor: null } }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1',
    (route) =>
      route.request().method() === 'GET'
        ? route.fulfill({ json: entryDetailJson(ENTRY, '<p>The story body.</p>') })
        : route.fallback(),
  );
  await page.route('**/api/entries/*/reader', (route) =>
    route.fulfill({ json: readerFailedJson() }),
  );
  await page.route('**/api/client-errors', (route) => route.fulfill({ status: 204 }));
}

test('a crosspost sends to Bluesky once and retries only Mastodon', async ({ page }) => {
  await stubShareableEntry(page);
  await page.route(
    (url) => url.pathname === '/api/social/link-composer',
    (route) => {
      const network = new URL(route.request().url()).searchParams.get('network');
      return route.fulfill({
        json: composerContext(network === 'mastodon' ? 'mastodon' : 'bluesky'),
      });
    },
  );
  const sends: string[] = [];
  let mastodonAttempts = 0;
  await page.route(
    (url) => url.pathname === '/api/social/links',
    (route) => {
      const network = new URL(route.request().url()).searchParams.get('network') ?? '';
      sends.push(network);
      if (network === 'mastodon' && ++mastodonAttempts === 1) {
        return route.fulfill({ status: 503, json: { title: 'Mastodon is unavailable' } });
      }
      return route.fulfill({ status: 201, json: published(network) });
    },
  );

  await page.setViewportSize({ width: 1280, height: 800 });
  await presetLocalStorage(page, { 'sfr.layout': 'pane' });
  await page.goto('/?subscription=1');
  await page.locator('app-entry-list').getByText(ENTRY.title, { exact: true }).click();
  await page.getByRole('button', { name: 'Share', exact: true }).first().click();
  await page.getByRole('menuitem', { name: 'Post on Bluesky' }).click();

  const dialog = page.locator('app-composer-dialog');
  await dialog.getByRole('button', { name: 'Mastodon' }).click();
  await expect(dialog.getByRole('button', { name: 'Mastodon' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await dialog
    .getByRole('button', { name: /^Post on both|^Post/ })
    .last()
    .click();

  await expect(dialog.getByRole('button', { name: 'Retry Mastodon' })).toBeVisible();
  expect(sends).toEqual(['bluesky', 'mastodon']);

  await dialog.getByRole('button', { name: 'Retry Mastodon' }).click();
  await expect(page.getByRole('button', { name: 'Open post' })).toHaveCount(2);
  expect(sends.filter((network) => network === 'bluesky')).toHaveLength(1);
  expect(sends).toEqual(['bluesky', 'mastodon', 'mastodon']);
});
