// The demo account's 19 feeds rarely cover the same story, so Trending stays nearly empty. This
// borrows news feeds into its News tag, whose stories overlap with what it follows.
// Their backlog older than a day is marked read, so the sidebar counts stay the demo's own, and
// they show in Trending only: All items, For you and the saved searches stay the demo's.
// The demo's active AI connection is a scorer, which writes no story titles: while the feeds are
// borrowed, its first connection that can write is picked for them, and unpicked on teardown.
//
//   node --env-file=.env.screenshots trending-setup.mjs            # subscribe
//   node --env-file=.env.screenshots trending-setup.mjs status     # exits 2 until Trending is worth showing
//   node --env-file=.env.screenshots trending-setup.mjs teardown   # unsubscribe them again
import { chromium } from '@playwright/test';
import { isCalmStory, openApiSession } from './lib.mjs';
import { BELOW_FLOOR, TRENDING_FEEDS, TRENDING_FLOOR } from './trending-feeds.mjs';

const TAG_NAME = 'News';
const TRENDING_ONLY = {
  includeInAllItems: false,
  includeInForYou: false,
  includeInSavedSearches: false,
  includeInTrending: true,
};
const mode = process.argv[2] ?? 'subscribe';
if (!['subscribe', 'status', 'teardown'].includes(mode)) throw new Error(`Unknown mode "${mode}"`);

const browser = await chromium.launch();
const api = await openApiSession(browser);

async function borrowedSubscriptionIds() {
  const { json: subscriptions } = await api('GET', '/api/subscriptions');
  return subscriptions.subscriptions
    .filter((subscription) => TRENDING_FEEDS.includes(subscription.feedUrl))
    .map((subscription) => subscription.id);
}

async function subscribe() {
  const { json: tagList } = await api('GET', '/api/tags');
  const tag = tagList.tags.find((candidate) => candidate.name === TAG_NAME);
  if (!tag) throw new Error(`The demo account has no ${TAG_NAME} tag`);
  const dayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
  for (const url of TRENDING_FEEDS) {
    const result = await api('POST', '/api/subscriptions', { url, tagIds: [tag.id] });
    console.log(`${result.status} ${url}`);
    const subscriptionId = result.json?.subscription?.id;
    if (subscriptionId === undefined) continue;
    // Teardown finds the borrowed feeds by their stored address.
    const storedUrl = result.json.subscription.feedUrl;
    if (storedUrl !== url) {
      await api('POST', '/api/subscriptions/bulk-unsubscribe', {
        subscriptionIds: [subscriptionId],
      });
      throw new Error(`${url} is stored as ${storedUrl}: list that address in trending-feeds.mjs`);
    }
    await api('POST', '/api/entries/mark-read', {
      scope: 'feed',
      id: subscriptionId,
      until: dayAgo,
    });
  }
  const subscriptionIds = await borrowedSubscriptionIds();
  const kept = await api('PATCH', '/api/subscriptions/bulk', { subscriptionIds, ...TRENDING_ONLY });
  console.log(`${subscriptionIds.length} feeds borrowed, shown in Trending only: ${kept.status}`);
}

const STORY_SETTINGS = '/api/me/ai/stories';
async function pickStoryWriter() {
  const { json: settings } = await api('GET', STORY_SETTINGS);
  if (settings.writingConnection !== null) return;
  const writer = settings.writingCandidates[0];
  if (!writer) return console.log('no connection can write story titles; cards keep a headline');
  await api('PUT', STORY_SETTINGS, { writingConnectionId: writer.id, titlesEnabled: true });
  console.log(`story titles written by ${writer.name}`);
}
async function unpickStoryWriter() {
  const { json: settings } = await api('GET', STORY_SETTINGS);
  await api('PUT', STORY_SETTINGS, {
    writingConnectionId: null,
    titlesEnabled: settings.titlesEnabled,
  });
}

// The writer was picked with the feeds, so it goes only when feeds went.
async function giveBack() {
  const subscriptionIds = await borrowedSubscriptionIds();
  if (subscriptionIds.length === 0) return console.log('nothing borrowed');
  const removed = await api('POST', '/api/subscriptions/bulk-unsubscribe', { subscriptionIds });
  console.log(`unsubscribed ${removed.json?.removed ?? 0}`);
  await unpickStoryWriter();
}

async function reportWindow(window) {
  const { json } = await api('GET', `/api/trending?window=${window}`);
  const stories = (json.stories ?? []).filter(isCalmStory);
  const leadSources = Math.max(0, ...stories.map((story) => story.outletCount));
  const firstScreen = stories.slice(0, TRENDING_FLOOR.titledOnFirstScreen);
  const titled = firstScreen.filter((story) => story.synopsis !== null).length;
  console.log(
    `trending ${window}: ${stories.length} stories, lead has ${leadSources} sources, ` +
      `${titled}/${firstScreen.length} of the first screen carry a synopsis`,
  );
  return { stories: stories.length, leadSources, titled };
}

// Whether the day window clears the floor; prints both windows either way.
async function isWorthShowing() {
  const day = await reportWindow('day');
  await reportWindow('week');
  return (
    day.stories >= TRENDING_FLOOR.stories &&
    day.leadSources >= TRENDING_FLOOR.leadSources &&
    day.titled >= TRENDING_FLOOR.titledOnFirstScreen
  );
}

if (mode === 'teardown') {
  await giveBack();
} else {
  if (mode === 'subscribe') {
    await subscribe();
    await pickStoryWriter();
  }
  if (!(await isWorthShowing())) {
    console.log(
      `below the floor: ${TRENDING_FLOOR.stories} stories, ${TRENDING_FLOOR.leadSources} sources ` +
        'on the lead, a synopsis on every first-screen story',
    );
    process.exitCode = BELOW_FLOOR;
  }
}
await browser.close();
