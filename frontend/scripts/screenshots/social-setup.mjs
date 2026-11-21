// Connects a Bluesky, Mastodon or Lemmy account to the demo account for the social shots and
// imports a few of its follows, communities and lists, or takes it all away again. Credentials
// come from the env file and are never printed.
//
//   node --env-file=.env.screenshots social-setup.mjs bluesky              # connect + import
//     BLUESKY_IMPORT_HANDLES=heise.de,ct.de BLUESKY_IMPORT_COLLECTIONS=Discover picks what to import
//   node --env-file=.env.screenshots social-setup.mjs lemmy candidates     # connect, list what could be imported
//   node --env-file=.env.screenshots social-setup.mjs mastodon teardown    # unsubscribe, disconnect
//
// Lemmy with two-factor sign-in: pass the current code as LEMMY_TOTP=123456 on the command line.
import { chromium } from '@playwright/test';
import { openApiSession } from './lib.mjs';

// The connect body of each network: field name to the env variable that fills it.
const NETWORKS = {
  bluesky: { required: { handle: 'BLUESKY_HANDLE', appPassword: 'BLUESKY_APP_PASSWORD' } },
  mastodon: {
    required: { instanceUrl: 'MASTODON_INSTANCE_URL', accessToken: 'MASTODON_ACCESS_TOKEN' },
  },
  lemmy: {
    required: {
      instanceUrl: 'LEMMY_INSTANCE_URL',
      username: 'LEMMY_USERNAME',
      password: 'LEMMY_PASSWORD',
    },
    optional: { totpToken: 'LEMMY_TOTP' },
  },
};
// A home timeline or a discovery feed is the account owner's own reading: it shows in its
// network's section only, never in All items, For you, a saved search or Trending.
const ONE_ACCOUNT = 'profile';
const SECTION_ONLY = {
  includeInAllItems: false,
  includeInForYou: false,
  includeInSavedSearches: false,
  includeInTrending: false,
};
const UNPICKED_FEEDS = 8;
const UNPICKED_COLLECTIONS = 2;

const [network, mode = 'connect'] = process.argv.slice(2);
if (!(network in NETWORKS)) {
  throw new Error(`Name the network first: ${Object.keys(NETWORKS).join(', ')}`);
}
if (!['connect', 'candidates', 'teardown'].includes(mode))
  throw new Error(`Unknown mode "${mode}"`);

const picks = (suffix) =>
  (process.env[`${network.toUpperCase()}_IMPORT_${suffix}`] ?? '')
    .split(',')
    .map((pick) => pick.trim().replace(/^@/, ''))
    .filter(Boolean);
const HANDLES = picks('HANDLES');
const COLLECTIONS = picks('COLLECTIONS');

function connectBody() {
  const { required, optional = {} } = NETWORKS[network];
  const body = {};
  for (const [field, variable] of Object.entries(required)) {
    if (!process.env[variable]) throw new Error(`${variable} is not set in the env file`);
    body[field] = process.env[variable];
  }
  for (const [field, variable] of Object.entries(optional)) {
    if (process.env[variable]) body[field] = process.env[variable];
  }
  return body;
}

const browser = await chromium.launch();
const api = await openApiSession(browser);

const connectedAccount = async () =>
  ((await api('GET', '/api/me/social-accounts')).json.accounts ?? []).find(
    (account) => account.network === network,
  ) ?? null;

async function connect() {
  const existing = await connectedAccount();
  if (existing) return existing;
  const result = await api('POST', `/api/me/social-accounts/${network}`, connectBody());
  if (result.status !== 201 && result.status !== 200) {
    throw new Error(`connect failed: ${result.status} ${JSON.stringify(result.json)}`);
  }
  console.log(`connected ${result.json.handle ?? result.json.displayName ?? ''}`);
  return result.json;
}

// A candidate that follows one account or community carries an identity; a list, timeline or
// custom feed carries a kind.
const candidateGroups = (candidates) =>
  Object.entries(candidates).filter(([, items]) => Array.isArray(items));
const nameOf = (candidate) =>
  (candidate.handle ?? candidate.name ?? candidate.displayName ?? '').trim().replace(/^@/, '');

async function teardown() {
  const { json: subscriptions } = await api('GET', '/api/subscriptions');
  const subscriptionIds = subscriptions.subscriptions
    .filter((subscription) => subscription.social?.network === network)
    .map((subscription) => subscription.id);
  if (subscriptionIds.length > 0) {
    const removed = await api('POST', '/api/subscriptions/bulk-unsubscribe', { subscriptionIds });
    console.log(`unsubscribed ${removed.json?.removed ?? 0}`);
  }
  const account = await connectedAccount();
  if (!account) return console.log(`no ${network} account connected`);
  const disconnected = await api('DELETE', `/api/me/social-accounts/${account.id}`);
  console.log(`disconnected: ${disconnected.status}`);
}

function listCandidates(candidates) {
  for (const [group, items] of candidateGroups(candidates)) {
    console.log(`${group} (${items.length}):`);
    for (const item of items) {
      console.log(`  ${item.state ?? ''} ${nameOf(item)} ${item.displayName ?? item.kind ?? ''}`);
    }
  }
}

async function importPicked(account, candidates) {
  const fresh = candidateGroups(candidates)
    .flatMap(([, items]) => items)
    .filter((candidate) => candidate.state === 'new');
  const subscribe = fresh
    .filter((candidate) => candidate.identity !== undefined)
    .filter((candidate) => HANDLES.length === 0 || HANDLES.includes(nameOf(candidate)))
    .slice(0, HANDLES.length === 0 ? UNPICKED_FEEDS : HANDLES.length)
    .map((candidate) => ({
      identity: candidate.identity,
      title: candidate.displayName ?? candidate.name ?? null,
    }));
  const collections = fresh
    .filter((candidate) => candidate.identity === undefined && candidate.kind !== undefined)
    .filter((candidate) => COLLECTIONS.length === 0 || COLLECTIONS.includes(nameOf(candidate)))
    .slice(0, COLLECTIONS.length === 0 ? UNPICKED_COLLECTIONS : COLLECTIONS.length)
    .map((candidate) => ({ kind: candidate.kind, id: candidate.id, name: candidate.name }));
  const result = await api('POST', `/api/me/social-accounts/${account.id}/import`, {
    subscribe,
    unsubscribe: [],
    collections,
  });
  console.log(
    `import ${result.status}: ${subscribe.length} feeds, ${collections.length} collections`,
  );
}

async function keepTimelinesInTheirSection() {
  const { json: subscriptions } = await api('GET', '/api/subscriptions');
  const subscriptionIds = subscriptions.subscriptions
    .filter((subscription) => subscription.social?.network === network)
    .filter((subscription) => subscription.social.kind !== ONE_ACCOUNT)
    .map((subscription) => subscription.id);
  if (subscriptionIds.length === 0) return;
  const kept = await api('PATCH', '/api/subscriptions/bulk', { subscriptionIds, ...SECTION_ONLY });
  console.log(`${subscriptionIds.length} timelines kept to the ${network} section: ${kept.status}`);
}

if (mode === 'teardown') {
  await teardown();
} else {
  const account = await connect();
  const { json: candidates } = await api(
    'GET',
    `/api/me/social-accounts/${account.id}/import-candidates`,
  );
  if (mode === 'candidates') listCandidates(candidates);
  else {
    await importPicked(account, candidates);
    await keepTimelinesInTheirSection();
  }
}
await browser.close();
