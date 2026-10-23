// The demo account follows no podcasts, so the Listening shots borrow some: this gives it a
// temporary "Podcasts" tag with a few catalog podcasts and prints the tag id for shots.mjs.
//
//   node --env-file=.env.screenshots podcast-setup.mjs            # subscribe, prints PODCAST_TAG=<id>
//   node --env-file=.env.screenshots podcast-setup.mjs teardown   # unsubscribe and delete the tag
import { chromium } from '@playwright/test';

const BASE = process.env.SHOT_BASE_URL ?? 'http://localhost:4200';
const TAG_NAME = 'Podcasts';
const FEEDS = [
  'https://feeds.npr.org/510289/podcast.xml',
  'https://www.thisamericanlife.org/podcast/rss.xml',
  'https://feeds.npr.org/510318/podcast.xml',
];
const teardown = process.argv[2] === 'teardown';

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

const { json: tagList } = await api('GET', '/api/tags');
let tag = tagList.tags.find((candidate) => candidate.name === TAG_NAME);

if (teardown) {
  const { json: subscriptions } = await api('GET', '/api/subscriptions');
  const subscriptionIds = subscriptions.subscriptions
    .filter((subscription) => subscription.tags?.some((candidate) => candidate.name === TAG_NAME))
    .map((subscription) => subscription.id);
  if (subscriptionIds.length > 0) {
    const removed = await api('POST', '/api/subscriptions/bulk-unsubscribe', { subscriptionIds });
    console.log(`unsubscribed ${removed.json?.removed ?? 0}`);
  }
  if (tag) console.log(`tag deleted: ${(await api('DELETE', `/api/tags/${tag.id}`)).status}`);
} else {
  if (!tag) {
    const created = await api('POST', '/api/tags', {
      name: TAG_NAME,
      icon: 'podcasts',
      color: '#db2777',
    });
    tag = created.json.tag;
  }
  for (const url of FEEDS) {
    const result = await api('POST', '/api/subscriptions', { url, tagIds: [tag.id] });
    console.log(`${result.status} ${url}`);
  }
  console.log(`PODCAST_TAG=${tag.id}`);
}
await browser.close();
