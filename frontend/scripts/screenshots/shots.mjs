// Captures the docs/screenshots set against the dev stack. See README.md next to this file.
//
//   node --env-file=.env.screenshots shots.mjs [set|shot ...]   sets: public, admin, library, listening, all
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.SHOT_BASE_URL ?? 'http://localhost:4200';
const HERE = dirname(fileURLToPath(import.meta.url));
const DOCS = join(HERE, '..', '..', '..', 'docs', 'screenshots');
const RAW = join(tmpdir(), 'sfr-screenshots');
mkdirSync(RAW, { recursive: true });

const DESKTOP = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 4 / 3 };
const PHONE = {
  viewport: { width: 390, height: 760 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
};

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
// The magazine style is a server preference; answer the profile with airy instead of saving it.
async function stubAiryStyle(page) {
  await page.route(/\/api\/me$/, async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    const response = await route.fetch();
    const profile = await response.json();
    await route.fulfill({
      response,
      json: { ...profile, preferences: { ...profile.preferences, magazineStyle: 'airy' } },
    });
  });
}

async function settle(page, milliseconds = 1500) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(milliseconds);
}
async function scrollEverythingToTop(page) {
  await page.evaluate(() => {
    for (const element of document.querySelectorAll('*')) {
      if (element.scrollTop > 0) element.scrollTop = 0;
    }
    window.scrollTo(0, 0);
  });
  await settle(page, 1200);
}

const articles = (page) => page.locator('app-entry-list article');
// A Guardian article: its reader extract starts with the text, not a share toast or an author bio.
const guardianArticle = (page) => articles(page).filter({ hasText: 'The Guardian' }).first();

async function openArticle(page) {
  await guardianArticle(page).click();
  await page.locator('app-reader-view').waitFor();
  await settle(page, 2500);
}
async function openArticleInPane(page) {
  await openArticle(page);
  await scrollEverythingToTop(page);
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
  const picks = [
    rows.nth(1),
    rows.filter({ hasText: 'Planet Money' }).first(),
    rows.filter({ hasText: 'This American Life' }).first(),
  ];
  for (const row of picks) {
    await row.scrollIntoViewIfNeeded();
    await row.getByRole('button', { name: 'Add to playlist', exact: true }).click();
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
async function dismissPasskeyOffer(page) {
  const offer = page.getByRole('dialog', { name: 'Set up a passkey?' });
  if (await offer.isVisible().catch(() => false)) {
    await offer.getByRole('button', { name: 'Not now', exact: true }).click();
  }
}

const podcastTag = () => {
  if (!process.env.PODCAST_TAG)
    throw new Error('The listening set needs PODCAST_TAG (see podcast-setup.mjs)');
  return `/?tag=${process.env.PODCAST_TAG}`;
};
const magazine = { layout: 'magazine' };
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
    desktop('admin-settings-dark', '/settings/admin/settings', { theme: 'dark' }),
    desktop('admin-mail-light', '/settings/admin/mail'),
    desktop('admin-proxy-light', '/settings/admin/proxy', { stub: stubProxy }),
  ],
  library: [
    desktop('magazine-light', '/', magazine),
    desktop('magazine-dark', '/', { ...magazine, theme: 'dark' }),
    desktop('magazine-airy-dark', '/', { ...magazine, theme: 'dark', stub: stubAiryStyle }),
    desktop('pane-light', '/', { layout: 'pane', before: openArticleInPane }),
    desktop('pane-dark', '/', { layout: 'pane', theme: 'dark', before: openArticleInPane }),
    desktop('list-light', '/', { layout: 'list' }),
    desktop('reader-dark', '/', { ...magazine, theme: 'dark', before: openArticle }),
    desktop('search-light', '/?q=climate', magazine),
    desktop('kept-light', '/?view=kept', magazine),
    desktop('for-you-light', '/?view=for-you', magazine),
    desktop('onboarding-light', '/discover'),
    phone('mobile-onboarding-light', '/discover'),
    desktop('organise-light', '/settings/organise', { before: expandAll }),
    desktop('settings-preferences-light', '/settings/preferences'),
    desktop('settings-email-light', '/settings/email'),
    desktop('settings-account-light', '/settings/account'),
    desktop('settings-import-light', '/settings/import'),
    desktop('settings-ai-dark', '/settings/ai', { theme: 'dark' }),
    phone('mobile-settings-light', '/settings'),
    phone('mobile-magazine-light', '/', magazine),
    phone('mobile-magazine-dark', '/', { ...magazine, theme: 'dark' }),
    phone('mobile-list-light', '/', { layout: 'list' }),
    phone('mobile-sidebar-light', '/', { ...magazine, before: openSidebarDrawer }),
    phone('mobile-reader-light', '/', { ...magazine, before: openArticle }),
    phone('mobile-reader-dark', '/', { ...magazine, theme: 'dark', before: openArticle }),
    phone('mobile-for-you-light', '/?view=for-you', magazine),
    phone('mobile-search-dark', '/?q=climate', { ...magazine, theme: 'dark' }),
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
    ];
  },
};

function selectShots(arguments_) {
  if (arguments_.length === 0 || arguments_.includes('all')) {
    return [...SETS.public, ...SETS.admin, ...SETS.library, ...SETS.listening];
  }
  const known = [...SETS.public, ...SETS.admin, ...SETS.library];
  return arguments_.flatMap((key) => {
    if (key in SETS) return SETS[key];
    const shot = known.find((candidate) => candidate.name === key);
    if (!shot) throw new Error(`No set or shot named ${key}`);
    return [shot];
  });
}

async function signIn(page) {
  await page.goto(`${BASE}/login`);
  await page.locator('input[type=email]').fill(process.env.SHOT_EMAIL);
  await page.locator('input[type=password]').fill(process.env.SHOT_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  await dismissPasskeyOffer(page);
}

async function capture(browser, shot) {
  const isPhone = shot.device === 'phone';
  const context = await browser.newContext(isPhone ? PHONE : DESKTOP);
  const page = await context.newPage();
  await page.addInitScript(
    ({ theme, layout }) => {
      localStorage.setItem('sfr.theme', theme);
      localStorage.setItem('sfr.lang', 'en');
      localStorage.setItem('sfr.readingFocus', 'false');
      localStorage.removeItem('sfr.sidebarHidden');
      localStorage.removeItem('sfr.textSize');
      localStorage.removeItem('sfr.unread-only');
      if (layout) localStorage.setItem('sfr.layout', layout);
    },
    { theme: shot.theme, layout: shot.layout },
  );
  if (shot.stub) await shot.stub(page);
  if (shot.auth !== false) await signIn(page);
  await page.goto(`${BASE}${shot.path}`);
  await settle(page, 2500);
  if (shot.before) await shot.before(page);
  await page.mouse.move(0, 0);
  await page.waitForTimeout(300);
  const raw = join(RAW, `${shot.name}.png`);
  await page.screenshot({ path: raw });
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
