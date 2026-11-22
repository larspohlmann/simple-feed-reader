import { expect, Page, test } from '@playwright/test';
import type {
  ComposerContextDto,
  PublishedPostDto,
} from '../src/app/reader/social/composer/composer-dto';
import type { SocialAccountRefDto } from '../src/app/reader/social-feed';
import { presetLocalStorage } from './support/auth';
import {
  entryDetailJson,
  entryWire,
  oneFeedJson,
  readerFailedJson,
  stubOneFeedReader,
} from './support/reader';

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

const LEMMY_ACCOUNT: SocialAccountRefDto = {
  id: 13,
  network: 'lemmy',
  handle: 'ada@lemmy.invalid',
  status: 'connected',
  serviceUrl: 'https://lemmy.invalid',
};

const COMMUNITY_ID = 42;

function composerContext(network: 'bluesky' | 'mastodon' | 'lemmy'): ComposerContextDto {
  const account = [...ACCOUNTS, LEMMY_ACCOUNT].find((each) => each.network === network)!;
  return {
    kind: 'link',
    accounts: [{ id: account.id, handle: account.handle }],
    accountId: account.id,
    canSend: true,
    maxCharacters: { bluesky: 300, mastodon: 500, lemmy: 10000 }[network],
    visibility: network === 'mastodon' ? 'public' : null,
    text: '',
    subject: {
      kind: 'link',
      card: {
        url: `https://fixtures.invalid/${ENTRY.id}`,
        title: ENTRY.title,
        description: null,
        imageUrl: null,
        siteName: 'fixtures.invalid',
      },
    },
    lemmy:
      network === 'lemmy'
        ? {
            communities: [{ id: COMMUNITY_ID, handle: 'rust@lemmy.invalid' }],
            title: ENTRY.title,
            minTitleCharacters: 3,
            maxTitleCharacters: 200,
          }
        : null,
  };
}

function published(network: string): PublishedPostDto {
  return { address: `${network}-post`, url: `https://${network}.invalid/post/1` };
}

async function stubShareableEntry(
  page: Page,
  accounts: SocialAccountRefDto[] = ACCOUNTS,
): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: { ...oneFeedJson('Fixture feed'), socialAccounts: accounts } }),
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
      const requested = route.request().postDataJSON().network;
      const network = requested === 'mastodon' ? 'mastodon' : 'bluesky';
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
  await dialog.getByRole('button', { name: 'Post to both' }).click();

  await expect(dialog.getByRole('button', { name: 'Retry Mastodon' })).toBeVisible();
  expect(sends).toEqual(['bluesky', 'mastodon']);

  await dialog.getByRole('button', { name: 'Retry Mastodon' }).click();
  await expect(
    page.locator('app-post-sent-dialog').getByRole('button', { name: 'Open post' }),
  ).toHaveCount(2);
  expect(sends.filter((network) => network === 'bluesky')).toHaveLength(1);
  expect(sends).toEqual(['bluesky', 'mastodon', 'mastodon']);
});

test('a crosspost to all three networks sends one Lemmy post with its title and community', async ({
  page,
}) => {
  await stubShareableEntry(page, [...ACCOUNTS, LEMMY_ACCOUNT]);
  await page.route(
    (url) => url.pathname === '/api/social/link-composer',
    (route) => {
      const network = new URL(route.request().url()).searchParams.get('network');
      return route.fulfill({
        json: composerContext(network === 'mastodon' || network === 'lemmy' ? network : 'bluesky'),
      });
    },
  );
  const sends: { network: string; body: Record<string, unknown> }[] = [];
  await page.route(
    (url) => url.pathname === '/api/social/links',
    (route) => {
      const requested: string = route.request().postDataJSON().network ?? 'bluesky';
      sends.push({ network: requested, body: route.request().postDataJSON() });
      return route.fulfill({ status: 201, json: published(requested) });
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
  await dialog.getByRole('button', { name: 'Lemmy' }).click();
  await expect(dialog.getByRole('button', { name: 'Lemmy' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await dialog.getByLabel('Community').selectOption({ label: 'rust@lemmy.invalid' });
  await dialog.getByRole('button', { name: 'Post', exact: true }).click();

  await expect(
    page.locator('app-post-sent-dialog').getByRole('button', { name: 'Open post' }),
  ).toHaveCount(3);
  expect(sends.map(({ network }) => network).sort()).toEqual(['bluesky', 'lemmy', 'mastodon']);
  const lemmySends = sends.filter(({ network }) => network === 'lemmy');
  expect(lemmySends).toHaveLength(1);
  expect(lemmySends[0].body).toEqual(
    expect.objectContaining({ title: ENTRY.title, communityId: COMMUNITY_ID }),
  );
});
