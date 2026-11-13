import { test, expect, Page } from '@playwright/test';
import { signInAsAdmin } from './support/auth';
import type { SubscriptionDto, SubscriptionsResponse } from '../src/app/reader/models';
import type {
  FeedDirectoryCountryFile,
  FeedDirectoryIndex,
} from '../src/app/settings/feed-directory/feed-directory.models';

const ALPHA_FEED = 'https://alpha.fixtures.invalid/feed.xml';
const GAMMA_FEED = 'https://gamma.fixtures.invalid/feed.xml';

const SUBSCRIBED_GAMMA: SubscriptionDto = {
  id: 990001,
  feedId: 990001,
  title: 'Gamma fixture feed',
  faviconUrl: null,
  customTitle: null,
  feedUrl: GAMMA_FEED,
  siteUrl: null,
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: '2026-11-01T10:00:00+00:00',
  lastFetchedAt: null,
  lastSuccessfulFetchAt: null,
  lastNewContentAt: null,
  nextFetchAt: null,
  consecutiveFailures: 0,
  lastErrorMessage: null,
  position: 0,
  tags: [],
  unreadCount: 0,
  entryCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
  includeInSavedSearches: true,
  includeInTrending: true,
  social: null,
};

const INDEX: FeedDirectoryIndex = {
  countries: [
    { code: 'DE', name: 'Germany', sites: 3, languages: { de: 3 } },
    { code: 'FR', name: 'France', sites: 1, languages: { fr: 1 } },
  ],
  languages: { de: 3, fr: 1 },
};

const GERMANY: FeedDirectoryCountryFile = {
  country: 'DE',
  categories: ['News', 'Tech'],
  languages: ['de'],
  domains: [
    ['alpha.fixtures.invalid', 1, 800, [0], [[0, 0, [ALPHA_FEED]]], 'Alpha fixture site'],
    [
      'beta.fixtures.invalid',
      2,
      800,
      [0],
      [[0, 0, ['https://beta.fixtures.invalid/a.xml', 'https://beta.fixtures.invalid/b.xml']]],
      'Beta fixture site',
    ],
    ['gamma.fixtures.invalid', 3, 800, [0], [[1, 0, [GAMMA_FEED]]], 'Gamma fixture site'],
  ],
};

const ALPHA_PREVIEW = {
  title: 'Alpha fixture feed',
  itemCount: 1,
  content: 'summary',
  hasImages: false,
  items: [
    {
      title: 'Alpha headline',
      url: 'https://alpha.fixtures.invalid/1',
      author: null,
      summary: 'A short snippet.',
      imageUrl: null,
      imageWidth: null,
      imageHeight: null,
      publishedAt: '2026-11-01T10:00:00+00:00',
    },
  ],
};

/**
 * The spec owns everything the section reads or writes: the catalog files, the
 * subscribed set, the account's scope (read and saved) and the discover and
 * subscription POSTs the add-feed dialog sends. Nothing reaches the real account.
 */
async function stubFeedDirectory(page: Page): Promise<{
  discoverPosts: string[];
  previewPosts: string[];
  subscribePosts: string[];
}> {
  const discoverPosts: string[] = [];
  const previewPosts: string[] = [];
  const subscribePosts: string[] = [];
  let account: Record<string, unknown> | null = null;

  const withScope = (scope: unknown): Record<string, unknown> => {
    const real = account ?? {};
    const preferences = {
      ...(real['preferences'] as object | undefined),
      feedDirectoryScope: scope,
    };
    return { ...real, preferences };
  };

  await page.route('**/api/feed-directory/index', (route) => route.fulfill({ json: INDEX }));
  await page.route('**/api/feed-directory/countries/DE', (route) =>
    route.fulfill({ json: GERMANY }),
  );
  await page.route(
    (url) => url.pathname === '/api/me',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const response = await route.fetch();
      account = (await response.json()) as Record<string, unknown>;
      await route.fulfill({ json: withScope({ countries: [], languages: [] }) });
    },
  );
  await page.route('**/api/me/feed-directory-scope', (route) => {
    const scope = route.request().postDataJSON() as unknown;
    return route.fulfill({ json: withScope(scope) });
  });
  await page.route('**/api/feeds/discover', (route) => {
    discoverPosts.push((route.request().postDataJSON() as { url: string }).url);
    return route.fulfill({
      json: {
        candidates: [
          {
            url: ALPHA_FEED,
            title: 'Alpha fixture feed',
            format: 'rss',
            label: 'RSS',
            preview: ALPHA_PREVIEW,
          },
        ],
      },
    });
  });
  await page.route('**/api/feeds/preview', (route) => {
    previewPosts.push((route.request().postDataJSON() as { url: string }).url);
    return route.fulfill({ status: 500 });
  });
  await page.route(
    (url) => url.pathname === '/api/subscriptions',
    async (route) => {
      if (route.request().method() === 'GET') {
        const response = await route.fetch();
        const real = (await response.json()) as SubscriptionsResponse;
        return route.fulfill({ json: { ...real, subscriptions: [SUBSCRIBED_GAMMA] } });
      }
      if (route.request().method() !== 'POST') return route.fallback();
      subscribePosts.push((route.request().postDataJSON() as { url: string }).url);
      await route.fulfill({
        status: 422,
        contentType: 'application/problem+json',
        json: { title: 'Stubbed', detail: 'Stubbed by the spec', status: 422 },
      });
    },
  );
  await page.route(
    (url) => url.pathname.startsWith('/api/subscriptions/'),
    (route) => {
      const method = route.request().method();
      if (method !== 'PATCH' && method !== 'DELETE') return route.fallback();
      return route.fulfill({
        status: 422,
        contentType: 'application/problem+json',
        json: { title: 'Stubbed', detail: 'Stubbed by the spec', status: 422 },
      });
    },
  );
  return { discoverPosts, previewPosts, subscribePosts };
}

async function openDirectory(page: Page): Promise<void> {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/settings/feed-directory');
  await page
    .locator('[data-test=countries] label.opt')
    .filter({ hasText: /Germany|Deutschland/ })
    .getByRole('checkbox')
    .check();
  await page
    .locator('[data-test=languages] label.opt')
    .filter({ hasText: /German|Deutsch/ })
    .getByRole('checkbox')
    .check();
  await page.locator('[data-test=save]').click();
}

test('pick a scope, find a site and start subscribing from the feed directory', async ({
  page,
}) => {
  const { discoverPosts, previewPosts, subscribePosts } = await stubFeedDirectory(page);
  await openDirectory(page);

  const rows = page.locator('app-feed-directory-row');
  await expect(rows).toHaveCount(3);

  await page.locator('[data-test=search]').fill('alpha.fixtures');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('alpha.fixtures.invalid');

  await rows.first().locator('[data-test=subscribe]').click();

  const dialog = page.getByRole('dialog');
  await expect(dialog.locator('input[type=url]')).toHaveValue(ALPHA_FEED);
  expect(subscribePosts).toEqual([]);

  await dialog.locator('button[type=submit]').click();
  await expect.poll(() => discoverPosts).toEqual([ALPHA_FEED]);
  await expect(dialog.locator('app-preview-entry-row')).toHaveCount(1);
  expect(subscribePosts).toEqual([]);

  await dialog.locator('.card .subscribe').click();
  await expect.poll(() => subscribePosts).toEqual([ALPHA_FEED]);
  expect(previewPosts).toEqual([]);
});

test('a subscribed feed opens its edit dialog', async ({ page }) => {
  await stubFeedDirectory(page);
  await openDirectory(page);

  const row = page.locator('app-feed-directory-row').filter({ hasText: 'gamma.fixtures.invalid' });
  await row.getByRole('button', { name: 'Edit subscription' }).click();

  const dialog = page.getByRole('dialog', { name: 'Edit feed' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: /^Cancel$/ }).click();
  await expect(dialog).toBeHidden();
});

test("a row's category pill narrows the filter to that category", async ({ page }) => {
  await stubFeedDirectory(page);
  await openDirectory(page);

  const rows = page.locator('app-feed-directory-row');
  await expect(rows).toHaveCount(3);

  const pill = rows.first().locator('button.chip', { hasText: 'News' });
  await pill.click();
  await expect(rows).toHaveCount(2);
  await expect(rows.locator('button.chip', { hasText: 'News' })).toHaveCount(2);
  await expect(pill).toHaveAttribute('aria-pressed', 'true');

  await pill.click();
  await expect(rows).toHaveCount(3);
});

test('the list adds a batch at its end and shows every description in full', async ({ page }) => {
  const longSummary = 'A site whose description runs long. '.repeat(12).trim();
  const manySites: FeedDirectoryCountryFile = {
    ...GERMANY,
    domains: Array.from({ length: 150 }, (_unused, index) => [
      `site${index}.fixtures.invalid`,
      index + 1,
      800,
      [0],
      [[0, 0, [`https://site${index}.fixtures.invalid/feed.xml`]]],
      index === 0 ? longSummary : `Site ${index}`,
    ]),
  };
  await stubFeedDirectory(page);
  await page.route('**/api/feed-directory/countries/DE', (route) =>
    route.fulfill({ json: manySites }),
  );
  await openDirectory(page);

  const rows = page.locator('app-feed-directory-row');
  await expect(rows).toHaveCount(100);

  const summary = rows.first().locator('.summary');
  await expect(summary).toHaveText(longSummary);
  expect(await summary.evaluate((node) => node.scrollHeight <= node.clientHeight)).toBe(true);
  expect((await summary.boundingBox())!.height).toBeGreaterThan(
    (await rows.nth(1).locator('.summary').boundingBox())!.height * 2,
  );

  await rows.last().scrollIntoViewIfNeeded();
  await expect(rows).toHaveCount(150);
});
