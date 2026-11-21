// Captures the docs/screenshots set against the dev stack. See README.md next to this file.
//
//   node --env-file=.env.screenshots shots.mjs [set|shot ...]
//   sets: public, admin, library, trending, social, listening, all
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  articles,
  BASE,
  leaveOutDistressingNews,
  PHONE,
  seedSettings,
  settle,
  signIn,
  stubAiryStyle,
  stubPreferences,
} from './lib.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const DOCS = join(HERE, '..', '..', '..', 'docs', 'screenshots');
const RAW = join(tmpdir(), 'sfr-screenshots');
mkdirSync(RAW, { recursive: true });

const DESKTOP = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 4 / 3 };
// A close-up of one control: the desktop page at twice the pixels, cut to the shot's clip.
const DETAIL = { ...DESKTOP, deviceScaleFactor: 2 };
const CLIP_PADDING = 16;

const day = (daysAgo) => {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() - daysAgo);
  date.setUTCHours(9, 12, 0, 0);
  return date.toISOString();
};
const fakeUser = (id, email, status, feedsCount, tagsCount, lastLogin, created) => ({
  id,
  email,
  status,
  roles: ['ROLE_USER'],
  createdAt: day(created),
  approvedAt: status === 'pending_approval' ? null : day(created),
  identities: [],
  feedsCount,
  tagsCount,
  lastLoginAt: lastLogin === null ? null : day(lastLogin),
  trialEndsAt: null,
  maxSubscriptions: null,
});
const FAKE_USERS = [
  fakeUser(2, 'morgan.lee@example.com', 'active', 23, 5, 0, 140),
  fakeUser(3, 'sam.rivera@example.com', 'active', 11, 2, 3, 140),
  fakeUser(4, 'priya.nair@example.com', 'active', 41, 7, 0, 92),
  fakeUser(5, 'chris.weber@example.com', 'pending_approval', 0, 0, null, 1),
  fakeUser(6, 'alex.kim@example.com', 'active', 8, 1, 0, 30),
];
const NEWS = { id: 1, name: 'News', color: '#2563eb', icon: 'newspaper' };
const TECH = { id: 2, name: 'Tech', color: '#7c3aed', icon: 'devices' };
const fakeSubscription = (id, title, url, position, tag) => ({
  id,
  title,
  customTitle: null,
  url,
  position,
  createdAt: day(140),
  lastFetchedAt: day(0),
  tags: [tag],
});
const FAKE_DETAIL = {
  user: {
    id: 3,
    email: 'sam.rivera@example.com',
    status: 'active',
    roles: ['ROLE_USER'],
    locale: 'en',
    createdAt: day(140),
    approvedAt: day(140),
    lastLoginAt: day(3),
    identities: [],
  },
  footprint: {
    feedsCount: 11,
    tagsCount: 2,
    feedsLimit: 500,
    staleFeedsCount: 1,
    lastRefreshAt: day(0),
    dormant: false,
  },
  tags: [
    { ...NEWS, position: 0, feedsCount: 6 },
    { ...TECH, position: 1, feedsCount: 5 },
  ],
  subscriptions: [
    fakeSubscription(1, 'The Guardian', 'https://www.theguardian.com/world/rss', 0, NEWS),
    fakeSubscription(2, 'BBC News', 'https://feeds.bbci.co.uk/news/rss.xml', 1, NEWS),
    fakeSubscription(3, 'The Verge', 'https://www.theverge.com/rss/index.xml', 2, TECH),
    fakeSubscription(4, 'Ars Technica', 'https://feeds.arstechnica.com/arstechnica/index', 3, TECH),
  ],
  limits: { trialEndsAt: null, maxSubscriptions: null },
};

async function stubAdminUsers(page) {
  await page.route(/\/api\/admin\/users(\?.*)?$/, (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({ json: { users: FAKE_USERS } })
      : route.fallback(),
  );
  await page.route(/\/api\/admin\/users\/3$/, (route) => route.fulfill({ json: FAKE_DETAIL }));
}
// The real page shows the address and passkey domain of the machine the dev stack runs on.
const EXAMPLE_HOST = 'reader.example.com';
async function stubInstanceSettings(page) {
  await page.route(/\/api\/admin\/settings$/, async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    const response = await route.fetch();
    await route.fulfill({
      response,
      json: {
        ...(await response.json()),
        publicBaseUrl: `https://${EXAMPLE_HOST}`,
        publicBaseUrlDefault: `https://${EXAMPLE_HOST}`,
        passkeyRpId: null,
        passkeyRpIdEffective: EXAMPLE_HOST,
      },
    });
  });
}
// The real page shows this instance's proxy host and username.
async function stubProxy(page) {
  await page.route(/\/api\/admin\/proxy$/, (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({
          json: {
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example.net',
            port: 1080,
            username: 'reader',
            remoteDns: false,
            hasPassword: true,
          },
        })
      : route.fallback(),
  );
}

// Invented people: a connected account's real notifications name real ones.
const fakeActor = (displayName, handle) => ({
  handle,
  displayName,
  avatarUrl: null,
  profileUrl: `https://bsky.app/profile/${handle}`,
});
const fakeNotification = (id, kind, hoursAgo, extra = {}) => ({
  id,
  kind,
  account: `@${process.env.BLUESKY_HANDLE ?? 'jamie.example'}`,
  actors: [fakeActor('Morgan Lee', 'morgan.example')],
  actorCount: 1,
  excerpt: null,
  answeredExcerpt: null,
  threadTitle: null,
  threadPlace: null,
  threadBy: null,
  onComment: false,
  linkUrl: `https://bsky.app/post/${id}`,
  entryId: null,
  latestAt: new Date(Date.now() - hoursAgo * 60 * 60 * 1000).toISOString(),
  seen: true,
  ...extra,
});
const FAKE_NOTIFICATIONS = [
  fakeNotification(60, 'like', 1, {
    actors: [
      fakeActor('Priya Nair', 'priya.example'),
      fakeActor('Sam Rivera', 'sam.example'),
      fakeActor('Alex Kim', 'alex.example'),
    ],
    actorCount: 12,
    answeredExcerpt: 'Three feeds, one story: how Trending groups what the papers ran today.',
    seen: false,
  }),
  fakeNotification(59, 'reply', 2, {
    actors: [fakeActor('Chris Weber', 'chris.example')],
    excerpt: 'Does it count a wire story once when five outlets reprint it?',
    answeredExcerpt: 'Three feeds, one story: how Trending groups what the papers ran today.',
    seen: false,
  }),
  fakeNotification(58, 'repost', 5, {
    actors: [fakeActor('Morgan Lee', 'morgan.example'), fakeActor('Sam Rivera', 'sam.example')],
    actorCount: 4,
    answeredExcerpt: 'A reader that lays feeds out like a magazine.',
  }),
  fakeNotification(57, 'mention', 9, {
    actors: [fakeActor('Priya Nair', 'priya.example')],
    excerpt: 'Worth a look if you still read feeds: @jamie wrote up a week with the two-pane view.',
  }),
  fakeNotification(56, 'quote', 20, {
    actors: [fakeActor('Alex Kim', 'alex.example')],
    excerpt: 'This is the part most readers skip.',
    answeredExcerpt: 'Podcast chapters now sit under the player; a tap jumps to one.',
  }),
  fakeNotification(55, 'follow', 26, {
    actors: [fakeActor('Chris Weber', 'chris.example'), fakeActor('Dana Okafor', 'dana.example')],
    actorCount: 3,
  }),
];
const UNSEEN_NOTIFICATIONS = 2;

// The list and the bell's count are invented; nothing is marked seen on the real account.
async function stubNotifications(page) {
  await page.route(/\/api\/me\/notifications(?:[?#]|$)/, (route) =>
    route.fulfill({
      json: { groups: FAKE_NOTIFICATIONS, nextCursor: null, scopeMissing: [], needsReconnect: [] },
    }),
  );
  await page.route('**/api/me/notifications/seen', (route) => route.fulfill({ status: 204 }));
  await page.route(/\/api\/subscriptions\/counts(?:[?#]|$)/, async (route) => {
    const response = await route.fetch();
    const counts = await response.json();
    const notifications = [{ network: 'bluesky', unseenCount: UNSEEN_NOTIFICATIONS }];
    await route.fulfill({ response, json: { ...counts, notifications } });
  });
}

// The demo account has picked no countries; without a pick the page shows only the picker.
const stubDirectoryScope = (page) =>
  stubPreferences(page, { feedDirectoryScope: { countries: ['US', 'GB'], languages: ['en'] } });

async function scrollEverythingToTop(page) {
  await page.evaluate(() => {
    for (const element of document.querySelectorAll('*')) {
      if (element.scrollTop > 0) element.scrollTop = 0;
    }
    window.scrollTo(0, 0);
  });
  await settle(page, 1200);
}

// Sources whose reader extract starts with the text, not a share toast or an author bio. Each
// reader shot opens another one, so no two shots show the same article.
const openArticleFrom = (source) => async (page) => {
  await articles(page).filter({ hasText: source }).first().locator('.title, .dek').first().click();
  await page.locator('app-reader-view').waitFor();
  await settle(page, 2500);
};
const openArticleInPaneFrom = (source) => async (page) => {
  await openArticleFrom(source)(page);
  await scrollEverythingToTop(page);
};
// Opens the newest post of one followed account (BLUESKY_POST_ACCOUNT, a title substring) by its
// title: a click on a post's middle lands on the embedded link card instead.
async function openPost(page) {
  const wanted = process.env.BLUESKY_POST_ACCOUNT ?? 'Lily Hay Newman';
  await openNewestPostFrom(page, wanted);
}
async function openNewestPostFrom(page, wanted) {
  const subscriptionId = await page.evaluate(async (title) => {
    const token = localStorage.getItem('sfr.jwt');
    const response = await fetch('/api/subscriptions', {
      headers: { Authorization: `Bearer ${token}` },
    });
    const { subscriptions } = await response.json();
    return subscriptions.find((subscription) => subscription.title?.includes(title))?.id ?? null;
  }, wanted);
  if (subscriptionId === null) throw new Error(`No subscription titled like "${wanted}"`);
  await page.goto(`${BASE}/?subscription=${subscriptionId}`);
  await settle(page, 2500);
  await articles(page).first().locator('.title, .dek').first().click();
  await page.locator('app-reader-view').waitFor();
  await settle(page, 3000);
}
// The most discussed Lemmy post near the top of the list, open beside it. LEMMY_POST (a title
// substring) names one instead, when the most discussed thread does not suit a public page.
const ROWS_WITHOUT_SCROLLING = 8;
async function discussedLemmyPost(page) {
  if (process.env.LEMMY_POST)
    return articles(page).filter({ hasText: process.env.LEMMY_POST }).first();
  const commentCounts = await articles(page).evaluateAll(
    (rows, limit) =>
      rows.slice(0, limit).map((row) => {
        const bubble = row.querySelector('.discussion .comments');
        return bubble ? Number.parseInt(bubble.textContent.replace(/\D/g, ''), 10) || 0 : 0;
      }),
    ROWS_WITHOUT_SCROLLING,
  );
  const mostDiscussed = commentCounts.indexOf(Math.max(...commentCounts));
  if (commentCounts[mostDiscussed] === 0)
    throw new Error('No Lemmy post near the top has comments');
  return articles(page).nth(mostDiscussed);
}
async function openDiscussedLemmyPost(page) {
  const post = await discussedLemmyPost(page);
  await post.locator('.title, .dek').first().click();
  await page.locator('app-reader-view').waitFor();
  await page.locator('app-entry-comments app-vote-pill').first().waitFor({ timeout: 20000 });
  await scrollEverythingToTop(page);
}
// Leaves the Comments heading clear of the reader's sticky bar.
const ROOM_ABOVE_COMMENTS = 96;
// The end of the post's text, kept above its votes in the close-up.
const POST_TEXT_ABOVE_VOTES = 190;
const openDiscussedLemmyPostWithRoomAboveComments = (room) => async (page) => {
  await openDiscussedLemmyPost(page);
  await page.locator('app-entry-comments .head').evaluate((head, roomAbove) => {
    head.scrollIntoView({ block: 'start' });
    let scroller = head.parentElement;
    while (scroller && scroller.scrollHeight <= scroller.clientHeight)
      scroller = scroller.parentElement;
    (scroller ?? document.scrollingElement).scrollBy(0, -roomAbove);
  }, room);
  await settle(page, 800);
};
// With an account connected the post's votes sit under its text, beside Add comment; every
// comment carries its own.
async function lemmyPostVotes(page) {
  const clip = await clipAround([
    page.locator('app-reader-view app-social-counts-row').first(),
    page.locator('app-entry-comments .head'),
  ]);
  const textAbove = Math.min(POST_TEXT_ABOVE_VOTES, clip.y);
  return { ...clip, y: clip.y - textAbove, height: clip.height + textAbove };
}
const COMMENT_VOTES_HEIGHT = 420;
// Starts at the first comment's own edge: the padding above it would show the heading's foot.
async function lemmyCommentVotes(page) {
  const comments = page.locator('app-entry-comments .list > li');
  const clip = await clipAround([comments.first(), comments.nth(2)], COMMENT_VOTES_HEIGHT);
  return { ...clip, y: clip.y + CLIP_PADDING, height: clip.height - CLIP_PADDING };
}
async function openLeadStory(page) {
  const storyId = await page.evaluate(async () => {
    const token = localStorage.getItem('sfr.jwt');
    const response = await fetch('/api/trending?window=day', {
      headers: { Authorization: `Bearer ${token}` },
    });
    const { stories } = await response.json();
    return stories[0]?.id ?? null;
  });
  if (storyId === null) throw new Error('Trending has no story to open');
  await page.goto(`${BASE}/trending/stories/${storyId}`);
  await settle(page, 2500);
}
// A touch scroll, as a finger does it: the app bar, the tag strip and the list header fold into
// one title line once the list moves, and a wheel or scrollTo would not show that on a phone.
const SCROLLED_BY = 900;
async function scrollListByTouch(page) {
  await articles(page).first().waitFor();
  const session = await page.context().newCDPSession(page);
  await session.send('Input.synthesizeScrollGesture', {
    x: PHONE.viewport.width / 2,
    y: 500,
    yDistance: -SCROLLED_BY,
    speed: 900,
    gestureSourceType: 'touch',
  });
  await settle(page, 1200);
}
async function openSidebarDrawer(page) {
  await page.getByRole('button', { name: 'Toggle sidebar' }).click();
  await page.getByRole('navigation', { name: 'Feeds' }).waitFor();
  await settle(page, 800);
}
async function expandAll(page) {
  await page.getByRole('button', { name: 'Expand all' }).click();
  await settle(page, 1000);
}
async function queueEpisodes(page) {
  const rows = articles(page);
  const addToPlaylist = page.getByRole('button', { name: 'Add to playlist', exact: true });
  // The second row may itself be one of the named shows: each pick is a row not yet queued.
  const notYetQueued = (source) => rows.filter({ hasText: source, has: addToPlaylist }).first();
  const picks = [rows.nth(1), notYetQueued('Planet Money'), notYetQueued('This American Life')];
  for (const row of picks) {
    await row.scrollIntoViewIfNeeded();
    await row.locator(addToPlaylist).click();
    await page.waitForTimeout(300);
  }
  await rows.nth(0).scrollIntoViewIfNeeded();
  await rows.nth(0).getByRole('button', { name: 'Play', exact: true }).click();
  await page.locator('app-audio-player-bar').waitFor();
  await settle(page, 4000);
  await scrollEverythingToTop(page);
}
async function queueEpisodesAndOpenPlaylist(page) {
  await queueEpisodes(page);
  await page
    .locator('app-audio-player-bar')
    .getByRole('button', { name: /Playlist \(\d+\)/ })
    .click();
  await settle(page, 1500);
}
async function queueEpisodesAndOpenPlayer(page) {
  await queueEpisodes(page);
  await page.getByRole('button', { name: 'Open player', exact: true }).click();
  await page.locator('app-big-player').waitFor();
  await settle(page, 2000);
}
const podcastTag = () => {
  if (!process.env.PODCAST_TAG)
    throw new Error('The listening set needs PODCAST_TAG (see podcast-setup.mjs)');
  return `/?tag=${process.env.PODCAST_TAG}`;
};
const magazine = { layout: 'magazine' };
const LEMMY_COMMUNITIES = '/?network=lemmy&feedKind=profile';
const detail = (name, path, extra = {}) => ({
  name,
  device: 'detail',
  theme: 'light',
  path,
  ...extra,
});
const desktop = (name, path, extra = {}) => ({
  name,
  device: 'desktop',
  theme: 'light',
  path,
  ...extra,
});
const phone = (name, path, extra = {}) => ({
  name,
  device: 'phone',
  theme: 'light',
  path,
  ...extra,
});

const SETS = {
  public: [
    desktop('login-light', '/login', { auth: false }),
    phone('mobile-login-dark', '/login', { auth: false, theme: 'dark' }),
  ],
  admin: [
    desktop('admin-users-light', '/settings/admin/users', { stub: stubAdminUsers }),
    desktop('admin-user-detail-light', '/settings/admin/users/3', { stub: stubAdminUsers }),
    phone('mobile-admin-users-dark', '/settings/admin/users', {
      stub: stubAdminUsers,
      theme: 'dark',
    }),
    desktop('admin-catalog-light', '/settings/admin/catalog'),
    desktop('admin-settings-dark', '/settings/admin/settings', {
      theme: 'dark',
      stub: stubInstanceSettings,
    }),
    desktop('admin-mail-light', '/settings/admin/mail'),
    desktop('admin-proxy-light', '/settings/admin/proxy', { stub: stubProxy }),
  ],
  library: [
    desktop('magazine-light', '/', { ...magazine, tag: 'Science' }),
    desktop('magazine-dark', '/', { ...magazine, theme: 'dark', tag: 'Tech' }),
    desktop('magazine-airy-dark', '/', {
      ...magazine,
      theme: 'dark',
      stub: stubAiryStyle,
      tag: 'Food',
    }),
    desktop('pane-light', '/', {
      layout: 'pane',
      tag: 'Tech',
      startAt: 4,
      before: openArticleInPaneFrom('Ars Technica'),
    }),
    desktop('pane-dark', '/', {
      layout: 'pane',
      theme: 'dark',
      tag: 'Science',
      startAt: 3,
      before: openArticleInPaneFrom('Quanta Magazine'),
    }),
    desktop('list-light', '/', { layout: 'list', startAt: 9 }),
    desktop('reader-dark', '/', {
      ...magazine,
      theme: 'dark',
      tag: 'Culture',
      before: openArticleFrom('Dezeen'),
    }),
    desktop('search-light', '/?q=climate', magazine),
    desktop('kept-light', '/?view=kept', magazine),
    desktop('for-you-light', '/?view=for-you', magazine),
    desktop('onboarding-light', '/discover'),
    phone('mobile-onboarding-light', '/discover'),
    desktop('organise-light', '/settings/organise', { before: expandAll }),
    desktop('settings-visibility-light', '/settings/visibility', { before: expandAll }),
    desktop('settings-directory-light', '/settings/feed-directory', { stub: stubDirectoryScope }),
    desktop('settings-preferences-light', '/settings/preferences'),
    desktop('settings-email-light', '/settings/email'),
    desktop('settings-account-light', '/settings/account'),
    desktop('settings-import-light', '/settings/import'),
    desktop('settings-ai-dark', '/settings/ai', { theme: 'dark' }),
    phone('mobile-settings-light', '/settings'),
    // A phone's list header leaves a tag's name three letters, so the phone lists stay on All items.
    phone('mobile-magazine-light', '/', { ...magazine, startAt: 3 }),
    // The pair to mobile-magazine-light: the same list once it has been scrolled.
    phone('mobile-scrolled-light', '/', { ...magazine, startAt: 3, before: scrollListByTouch }),
    phone('mobile-magazine-dark', '/', { ...magazine, theme: 'dark', startAt: 6 }),
    phone('mobile-list-light', '/', { layout: 'list', startAt: 14 }),
    phone('mobile-sidebar-light', '/', { ...magazine, before: openSidebarDrawer }),
    phone('mobile-reader-light', '/', {
      ...magazine,
      tag: 'Food',
      before: openArticleFrom('Budget Bytes'),
    }),
    phone('mobile-reader-dark', '/', {
      ...magazine,
      theme: 'dark',
      tag: 'Science',
      before: openArticleFrom('Science News'),
    }),
    phone('mobile-for-you-light', '/?view=for-you', magazine),
    phone('mobile-search-dark', '/?q=ocean', { ...magazine, theme: 'dark' }),
  ],
  // Shot with the Trending feeds borrowed (trending-setup.mjs); they crowd every other list.
  trending: [
    desktop('trending-light', '/?view=trending&window=day', magazine),
    desktop('story-light', '/?view=trending&window=day', { ...magazine, before: openLeadStory }),
    phone('mobile-trending-light', '/?view=trending&window=day', magazine),
  ],
  social: [
    desktop('settings-social-light', '/settings/social'),
    desktop('mastodon-light', '/?network=mastodon&feedKind=profile', magazine),
    // The followed communities only: the home timeline is the account owner's own reading.
    desktop('lemmy-light', LEMMY_COMMUNITIES, { layout: 'pane', before: openDiscussedLemmyPost }),
    detail('lemmy-votes-light', LEMMY_COMMUNITIES, {
      layout: 'pane',
      before: openDiscussedLemmyPostWithRoomAboveComments(
        ROOM_ABOVE_COMMENTS + POST_TEXT_ABOVE_VOTES + ROOM_ABOVE_COMMENTS,
      ),
      clip: lemmyPostVotes,
    }),
    detail('lemmy-comment-votes-light', LEMMY_COMMUNITIES, {
      layout: 'pane',
      before: openDiscussedLemmyPostWithRoomAboveComments(ROOM_ABOVE_COMMENTS),
      clip: lemmyCommentVotes,
    }),
    desktop('notifications-light', '/?view=notifications&network=bluesky', {
      ...magazine,
      stub: stubNotifications,
    }),
    desktop('bluesky-light', '/?network=bluesky&feedKind=profile', magazine),
    desktop('bluesky-post-dark', '/?network=bluesky&feedKind=profile', {
      ...magazine,
      theme: 'dark',
      before: openPost,
    }),
    phone('mobile-bluesky-light', '/?network=bluesky&feedKind=profile', magazine),
    phone('mobile-bluesky-post-dark', '/?network=bluesky&feedKind=profile', {
      ...magazine,
      theme: 'dark',
      before: openPost,
    }),
  ],
  get listening() {
    return [
      desktop('podcast-playlist-light', podcastTag(), {
        ...magazine,
        before: queueEpisodesAndOpenPlaylist,
      }),
      phone('mobile-podcast-light', podcastTag(), { ...magazine, before: queueEpisodes }),
      phone('mobile-podcast-playlist-light', podcastTag(), {
        ...magazine,
        before: queueEpisodesAndOpenPlaylist,
      }),
      phone('mobile-podcast-player-light', podcastTag(), {
        ...magazine,
        before: queueEpisodesAndOpenPlayer,
      }),
    ];
  },
};

function selectShots(arguments_) {
  if (arguments_.length === 0 || arguments_.includes('all')) {
    return Object.keys(SETS).flatMap((set) => SETS[set]);
  }
  const known = [...SETS.public, ...SETS.admin, ...SETS.library, ...SETS.trending, ...SETS.social];
  if (process.env.PODCAST_TAG) known.push(...SETS.listening);
  return arguments_.flatMap((key) => {
    if (key in SETS) return SETS[key];
    const shot = known.find((candidate) => candidate.name === key);
    if (!shot) throw new Error(`No set or shot named ${key}`);
    return [shot];
  });
}

// Shots differ by what they show: a shot with a tag opens that tag of the demo account.
async function tagPath(page, name) {
  const tagId = await page.evaluate(async (wanted) => {
    const token = localStorage.getItem('sfr.jwt');
    const response = await fetch('/api/tags', { headers: { Authorization: `Bearer ${token}` } });
    const { tags } = await response.json();
    return tags.find((tag) => tag.name === wanted)?.id ?? null;
  }, name);
  if (tagId === null) throw new Error(`The demo account has no ${name} tag`);
  return `/?tag=${tagId}`;
}

// One box around every locator, with room around it, kept inside the viewport and under a height.
async function clipAround(locators, maxHeight = Number.POSITIVE_INFINITY) {
  const boxes = await Promise.all(locators.map((locator) => locator.boundingBox()));
  if (boxes.some((box) => box === null)) throw new Error('A clipped element is not on the page');
  const viewport = locators[0].page().viewportSize();
  const left = Math.max(0, Math.min(...boxes.map((box) => box.x)) - CLIP_PADDING);
  const top = Math.max(0, Math.min(...boxes.map((box) => box.y)) - CLIP_PADDING);
  const right = Math.max(...boxes.map((box) => box.x + box.width)) + CLIP_PADDING;
  const bottom = Math.max(...boxes.map((box) => box.y + box.height)) + CLIP_PADDING;
  return {
    x: left,
    y: top,
    width: Math.min(viewport.width, right) - left,
    height: Math.min(viewport.height, bottom, top + maxHeight) - top,
  };
}

async function capture(browser, shot) {
  const isPhone = shot.device === 'phone';
  const context = await browser.newContext(
    isPhone ? PHONE : shot.device === 'detail' ? DETAIL : DESKTOP,
  );
  const page = await context.newPage();
  await page.addInitScript(seedSettings, { theme: shot.theme, layout: shot.layout });
  await leaveOutDistressingNews(page, { startAt: shot.startAt });
  if (shot.stub) await shot.stub(page);
  if (shot.auth !== false) await signIn(page);
  await page.goto(`${BASE}${shot.tag ? await tagPath(page, shot.tag) : shot.path}`);
  await settle(page, 2500);
  if (shot.before) await shot.before(page);
  await page.mouse.move(0, 0);
  await page.waitForTimeout(300);
  const raw = join(RAW, `${shot.name}.png`);
  await page.screenshot({ path: raw, clip: shot.clip ? await shot.clip(page) : undefined });
  await context.close();

  let source = raw;
  if (isPhone) {
    source = join(RAW, `${shot.name}.framed.png`);
    execFileSync('node', [join(HERE, 'frame.mjs'), raw, source], { stdio: 'inherit' });
  }
  execFileSync('cwebp', ['-quiet', '-q', '90', source, '-o', join(DOCS, `${shot.name}.webp`)]);
  console.log(`✓ ${shot.name}`);
}

const shots = selectShots(process.argv.slice(2));
const browser = await chromium.launch();
for (const shot of shots) await capture(browser, shot);
await browser.close();
