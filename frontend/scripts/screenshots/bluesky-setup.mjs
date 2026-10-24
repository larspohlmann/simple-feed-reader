// Connects a Bluesky account to the demo account for the social shots and imports a few of
// its follows and lists, or takes it all away again. Credentials come from the env file.
//
//   node --env-file=.env.screenshots bluesky-setup.mjs              # connect + import
//     BLUESKY_IMPORT_HANDLES=heise.de,ct.de BLUESKY_IMPORT_COLLECTIONS=Discover picks what to import
//   node --env-file=.env.screenshots bluesky-setup.mjs candidates   # connect, list what could be imported
//   node --env-file=.env.screenshots bluesky-setup.mjs teardown     # unsubscribe, untag, disconnect
import { chromium } from '@playwright/test';

const BASE = process.env.SHOT_BASE_URL ?? 'http://localhost:4200';
// Which follows to import: a comma-separated list of handles, or the first eight when unset.
const HANDLES = (process.env.BLUESKY_IMPORT_HANDLES ?? '')
  .split(',')
  .map((handle) => handle.trim().replace(/^@/, ''))
  .filter(Boolean);
const mode = process.argv[2] ?? 'connect';

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${BASE}/login`);
await page.locator('input[type=email]').fill(process.env.SHOT_EMAIL);
await page.locator('input[type=password]').fill(process.env.SHOT_PASSWORD);
await page.getByRole('button', { name: 'Sign in', exact: true }).click();
await page.waitForURL((url) => !url.pathname.startsWith('/login'));

const api = (method, path, body) =>
  page.evaluate(
    async ({ method, path, body }) => {
      const token = localStorage.getItem('sfr.jwt');
      const response = await fetch(path, {
        method,
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      const text = await response.text();
      return { status: response.status, json: text ? JSON.parse(text) : null };
    },
    { method, path, body },
  );

const accounts = async () => (await api('GET', '/api/me/social-accounts')).json.accounts ?? [];
const blueskyAccount = async () =>
  (await accounts()).find((account) => account.network === 'bluesky') ?? null;

async function connect() {
  const existing = await blueskyAccount();
  if (existing) return existing;
  const result = await api('POST', '/api/me/social-accounts/bluesky', {
    handle: process.env.BLUESKY_HANDLE,
    appPassword: process.env.BLUESKY_APP_PASSWORD,
  });
  if (result.status !== 201 && result.status !== 200) {
    throw new Error(`connect failed: ${result.status} ${JSON.stringify(result.json)}`);
  }
  console.log(`connected ${result.json.handle ?? result.json.displayName ?? ''}`);
  return result.json;
}

const candidatesOf = async (account) =>
  (await api('GET', `/api/me/social-accounts/${account.id}/import-candidates`)).json;

if (mode === 'teardown') {
  const account = await blueskyAccount();
  const { json: subscriptions } = await api('GET', '/api/subscriptions');
  const ids = subscriptions.subscriptions
    .filter(
      (subscription) =>
        subscription.feedUrl.startsWith('social:bluesky:') ||
        subscription.siteUrl?.includes('bsky.app'),
    )
    .map((subscription) => subscription.id);
  if (ids.length > 0) {
    const removed = await api('POST', '/api/subscriptions/bulk-unsubscribe', {
      subscriptionIds: ids,
    });
    console.log(`unsubscribed ${removed.json?.removed ?? 0}`);
  }
  const { json: tagList } = await api('GET', '/api/tags');
  const tag = tagList.tags.find((candidate) => candidate.name === 'Bluesky');
  if (tag) console.log(`tag deleted: ${(await api('DELETE', `/api/tags/${tag.id}`)).status}`);
  if (account) {
    console.log(
      `disconnected: ${(await api('DELETE', `/api/me/social-accounts/${account.id}`)).status}`,
    );
  }
} else {
  const account = await connect();
  const candidates = await candidatesOf(account);
  if (mode === 'candidates') {
    const show = (items) =>
      items.map(
        (item) =>
          `  ${item.state} ${item.handle ?? item.kind} ${item.displayName ?? item.name ?? ''}`,
      );
    console.log(`follows (${candidates.follows.length}):`);
    console.log(show(candidates.follows).join('\n'));
    console.log('lists:');
    console.log(show(candidates.lists).join('\n'));
    console.log('custom feeds:');
    console.log(show(candidates.customFeeds).join('\n'));
  } else {
    const wanted = (candidate) =>
      HANDLES.length === 0 || HANDLES.includes(candidate.handle.replace(/^@/, ''));
    const follows = candidates.follows
      .filter((candidate) => candidate.state === 'new' && wanted(candidate))
      .slice(0, HANDLES.length === 0 ? 8 : HANDLES.length)
      .map((candidate) => ({ identity: candidate.identity, title: candidate.displayName }));
    const collectionNames = (process.env.BLUESKY_IMPORT_COLLECTIONS ?? '')
      .split(',')
      .map((name) => name.trim())
      .filter(Boolean);
    const collections = [...candidates.lists, ...candidates.customFeeds]
      .filter((candidate) => candidate.state === 'new')
      .filter(
        (candidate) =>
          collectionNames.length === 0 || collectionNames.includes(candidate.name.trim()),
      )
      .slice(0, collectionNames.length === 0 ? 2 : collectionNames.length)
      .map((candidate) => ({ kind: candidate.kind, id: candidate.id, name: candidate.name }));
    const result = await api('POST', `/api/me/social-accounts/${account.id}/import`, {
      subscribe: follows,
      unsubscribe: [],
      collections,
    });
    console.log(`import ${result.status} ${JSON.stringify(result.json)}`);
  }
}
await browser.close();
