import { expect, Page, test } from '@playwright/test';
import type {
  ComposerContextDto,
  PublishedPostDto,
} from '../src/app/reader/social/composer/composer-dto';
import type { SocialAccountRefDto } from '../src/app/reader/social-feed';
import { oneFeedJson, stubOneFeedReader } from './support/reader';

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

function postContext(network: 'bluesky' | 'mastodon'): ComposerContextDto {
  const account = ACCOUNTS.find((each) => each.network === network)!;
  return {
    kind: 'post',
    accounts: [{ id: account.id, handle: account.handle }],
    accountId: account.id,
    canSend: true,
    maxCharacters: network === 'mastodon' ? 500 : 300,
    visibility: network === 'mastodon' ? 'public' : null,
    text: '',
    subject: null,
    lemmy: null,
  };
}

async function stubSidebarWithAccounts(page: Page): Promise<{ network: string; text: string }[]> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: { ...oneFeedJson('Fixture feed'), socialAccounts: ACCOUNTS } }),
  );
  await page.route('**/api/client-errors', (route) => route.fulfill({ status: 204 }));
  await page.route(
    (url) => url.pathname === '/api/social/post-composer',
    (route) => {
      const network = new URL(route.request().url()).searchParams.get('network');
      return route.fulfill({ json: postContext(network === 'mastodon' ? 'mastodon' : 'bluesky') });
    },
  );
  const sends: { network: string; text: string }[] = [];
  await page.route(
    (url) => url.pathname === '/api/social/posts',
    (route) => {
      const { network, text } = route.request().postDataJSON();
      sends.push({ network, text });
      const published: PublishedPostDto = {
        address: `${network}-post`,
        url: `https://${network}.invalid/post/1`,
      };
      return route.fulfill({ status: 201, json: published });
    },
  );
  return sends;
}

test("a text post from the sidebar's '+' goes out, and its draft waits until it does", async ({
  page,
}) => {
  const sends = await stubSidebarWithAccounts(page);
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/');

  await page.getByRole('button', { name: 'New post on Mastodon' }).click();
  const dialog = page.locator('app-composer-dialog');
  await expect(dialog.getByRole('heading', { name: 'New post on Mastodon' })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Mastodon' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await expect(dialog.getByRole('button', { name: 'Bluesky' })).toHaveAttribute(
    'aria-pressed',
    'false',
  );
  await dialog.getByLabel('Your post').fill('Half a thought');
  await dialog.getByRole('button', { name: 'Cancel' }).click();

  await page.getByRole('button', { name: 'New post on Bluesky' }).click();
  await expect(dialog.getByLabel('Your post')).toHaveValue('Half a thought');
  await dialog.getByLabel('Your post').fill('A whole thought');
  await dialog.getByRole('button', { name: 'Post', exact: true }).click();

  const sent = page.locator('app-post-sent-dialog');
  await expect(sent.getByRole('heading', { name: 'Posted on Bluesky' })).toBeVisible();
  expect(sends).toEqual([{ network: 'bluesky', text: 'A whole thought' }]);
  await sent.getByRole('button', { name: 'Close' }).click();

  await page.getByRole('button', { name: 'New post on Mastodon' }).click();
  await expect(dialog.getByLabel('Your post')).toHaveValue('');
});
