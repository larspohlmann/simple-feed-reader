// Helpers shared by the screenshot and tour scripts.

export const BASE = process.env.SHOT_BASE_URL ?? 'http://localhost:4200';
export const PHONE = {
  viewport: { width: 390, height: 760 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
};

export const articles = (page) => page.locator('app-entry-list article');

// An init script: the same reading settings on every page, whatever an earlier run left behind.
export function seedSettings({ theme, layout }) {
  localStorage.setItem('sfr.theme', theme);
  localStorage.setItem('sfr.lang', 'en');
  localStorage.setItem('sfr.readingFocus', 'false');
  localStorage.removeItem('sfr.sidebarHidden');
  localStorage.removeItem('sfr.textSize');
  localStorage.removeItem('sfr.unread-only');
  if (layout) localStorage.setItem('sfr.layout', layout);
}

export async function settle(page, milliseconds = 1500) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(milliseconds);
}

// Answers the profile with other preferences instead of saving them on the demo account.
export async function stubPreferences(pageOrContext, preferences) {
  await pageOrContext.route(/\/api\/me$/, async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    const response = await route.fetch();
    const profile = await response.json();
    await route.fulfill({
      response,
      json: { ...profile, preferences: { ...profile.preferences, ...preferences } },
    });
  });
}
export const stubAiryStyle = (pageOrContext) =>
  stubPreferences(pageOrContext, { magazineStyle: 'airy' });

// A shop window shows no violence: lists and Trending leave out the entries and stories whose
// title or summary is about killing, war, abuse or disaster, so no shot and no tour scene leads
// with one. The demo account itself is untouched.
const DISTRESSING = new RegExp(
  '\\b(' +
    [
      'shot|shoot\\w*|gun\\w*|kill\\w*|dead|deadly|death\\w*|dies|died|fatal\\w*|murder\\w*',
      'massacre\\w*|violen\\w*|attack\\w*|assault\\w*|stabb\\w*|bomb\\w*|missile\\w*|airstrike\\w*|war|wars',
      'terror\\w*|hostage\\w*|kidnap\\w*|rape\\w*|abus\\w*|suicid\\w*|genocid\\w*|executed|execution\\w*',
      'victim\\w*|crash\\w*|arson|cartel\\w*|trafficking|overdose\\w*|famine|corpse\\w*|bod(y|ies) found',
      'own li(fe|ves)|rifle\\w*|weapon\\w*',
    ].join('|') +
    ')\\b',
  'i',
);
// SHOT_SKIP adds a pattern for one run: a headline that passes the list but does not suit the page.
const SKIPPED = process.env.SHOT_SKIP ? new RegExp(process.env.SHOT_SKIP, 'i') : null;
export const isCalmEntry = (item) => {
  const text = [item.title, item.summary, item.synopsis].filter(Boolean).join(' ');
  return !DISTRESSING.test(text) && !SKIPPED?.test(text);
};
const isCalmArticle = (article) =>
  isCalmEntry(article) && (article.shares ?? []).every(isCalmEntry);
export const isCalmStory = (story) =>
  isCalmEntry(story) && (story.articles ?? []).every(isCalmArticle);

// `startAt` drops that many entries from the top of a list's first page, so two shots of the
// same list do not lead with the same article. `arrange` reorders a first page: it gets the
// entries and the request's address and returns the entries to show.
export async function leaveOutDistressingNews(pageOrContext, { startAt = 0, arrange } = {}) {
  await pageOrContext.route(/\/api\/(entries|trending)(\/[^?]*)?(\?.*)?$/, async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    const response = await route.fetch();
    const isJson = response.headers()['content-type']?.includes('json');
    if (!response.ok() || !isJson) return route.fulfill({ response });
    const body = await response.json();
    const address = new URL(route.request().url());
    const isFirstPage = !address.searchParams.has('cursor');
    if (Array.isArray(body.entries)) {
      body.entries = body.entries.filter(isCalmEntry).slice(isFirstPage ? startAt : 0);
      if (isFirstPage && arrange) body.entries = arrange(body.entries, address);
    }
    if (Array.isArray(body.stories)) body.stories = body.stories.filter(isCalmStory);
    await route.fulfill({ response, json: body });
  });
}

async function dismissPasskeyOffer(page) {
  const offer = page.getByRole('dialog', { name: 'Set up a passkey?' });
  if (await offer.isVisible().catch(() => false)) {
    await offer.getByRole('button', { name: 'Not now', exact: true }).click();
  }
}

export async function signIn(page) {
  await page.goto(`${BASE}/login`);
  await page.locator('input[type=email]').fill(process.env.SHOT_EMAIL);
  await page.locator('input[type=password]').fill(process.env.SHOT_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  await dismissPasskeyOffer(page);
}

// A GET as the signed-in account of a page, parsed.
export const apiGet = (page, path) =>
  page.evaluate(async (url) => {
    const token = localStorage.getItem('sfr.jwt');
    const response = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
    return response.json();
  }, path);

// A signed-in page for the setup scripts, with a helper that calls the API as the demo account.
export async function openApiSession(browser) {
  const page = await browser.newPage();
  await page.goto(`${BASE}/login`);
  await page.locator('input[type=email]').fill(process.env.SHOT_EMAIL);
  await page.locator('input[type=password]').fill(process.env.SHOT_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
  return (method, path, body) =>
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
}
