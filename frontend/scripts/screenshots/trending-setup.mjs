// The demo account's 19 feeds rarely cover the same story, so Trending stays nearly empty. This
// borrows a few news feeds into its News tag, whose stories overlap with what it follows.
// Their backlog older than a day is marked read, so the sidebar counts stay the demo's own. Shoot
// only the Trending shots with them borrowed; they crowd every other list.
//
//   node --env-file=.env.screenshots trending-setup.mjs            # subscribe
//   node --env-file=.env.screenshots trending-setup.mjs teardown   # unsubscribe them again
import { chromium } from '@playwright/test';
import { openApiSession } from './lib.mjs';

const TAG_NAME = 'News';
const FEEDS = [
  'https://www.theguardian.com/europe/rss',
  'https://www.aljazeera.com/xml/rss/all.xml',
  'https://www.cbc.ca/webfeed/rss/rss-topstories',
  'https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml',
  'https://rss.nytimes.com/services/xml/rss/nyt/World.xml',
  'https://techcrunch.com/feed/',
  'https://www.techmeme.com/feed.xml',
];
const teardown = process.argv[2] === 'teardown';

const browser = await chromium.launch();
const api = await openApiSession(browser);

if (teardown) {
  const { json: subscriptions } = await api('GET', '/api/subscriptions');
  const subscriptionIds = subscriptions.subscriptions
    .filter((subscription) => FEEDS.includes(subscription.feedUrl))
    .map((subscription) => subscription.id);
  if (subscriptionIds.length > 0) {
    const removed = await api('POST', '/api/subscriptions/bulk-unsubscribe', { subscriptionIds });
    console.log(`unsubscribed ${removed.json?.removed ?? 0}`);
  }
} else {
  const { json: tagList } = await api('GET', '/api/tags');
  const tag = tagList.tags.find((candidate) => candidate.name === TAG_NAME);
  if (!tag) throw new Error(`The demo account has no ${TAG_NAME} tag`);
  const dayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
  for (const url of FEEDS) {
    const result = await api('POST', '/api/subscriptions', { url, tagIds: [tag.id] });
    console.log(`${result.status} ${url}`);
    await api('POST', '/api/entries/mark-read', {
      scope: 'feed',
      id: result.json.subscription.id,
      until: dayAgo,
    });
  }
  for (const window of ['day', 'week']) {
    const { json } = await api('GET', `/api/trending?window=${window}`);
    console.log(`trending ${window}: ${json.stories.length} stories`);
  }
}
await browser.close();
