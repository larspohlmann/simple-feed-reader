// e2e/list-header-count-one-line.spec.ts
import { test, expect, Page } from '@playwright/test';
import { stubAuthToken } from './support/auth';

/**
 * The list heading carries a count pill beside the list's name (#709). The bar
 * it sits in must stay exactly as tall as it was without it: the scroller
 * reserves this bar's height, so a wrapped pill moves every row under it (#87,
 * #419) — the same rule that keeps the whole-word badge beside the title rather
 * than under it.
 *
 * A long feed name is the case that breaks it: the row wraps before the h2
 * gives up its width, which strands the pill on a second line. Asserted as a
 * height comparison rather than a screenshot, so it holds at any font.
 *
 * Hermetic: every reader boot request is stubbed, so this spec owns the names
 * and counts it asserts on and needs no seeded account (#96).
 */

const SHORT_NAME = 'Heise';
const LONG_NAME = 'NDR.de - Das Beste am Norden - Radio - Fernsehen - Nachrichten - Sport - Wetter';

function subscription(id: number, title: string, unreadCount: number) {
  return {
    id,
    feedId: id * 10,
    title,
    customTitle: null,
    lastFetchedAt: '2026-08-29T09:00:00Z',
    feedUrl: `https://fixtures.invalid/${id}`,
    siteUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-08-01T00:00:00Z',
    tags: [],
    unreadCount,
    includeInAllItems: true,
    includeInForYou: true,
  };
}

function entry(id: number) {
  return {
    id,
    title: `Fixture entry ${id}`,
    url: `https://fixtures.invalid/e/${id}`,
    author: null,
    summary: 'A short fixture summary.',
    contentHtml: '<p>Fixture body.</p>',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: '2026-08-29T08:00:00Z',
    createdAt: '2026-08-29T08:00:00Z',
    subscriptionId: 1,
    source: SHORT_NAME,
    faviconUrl: null,
    isHidden: false,
    isFavorite: false,
    isKept: false,
    isViewed: false,
  };
}

async function stubReader(page: Page): Promise<void> {
  await stubAuthToken(page);
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const json = (body: unknown) => route.fulfill({ status: 200, json: body });

    if (path.endsWith('/api/subscriptions')) {
      return json({
        subscriptions: [subscription(1, SHORT_NAME, 123), subscription(2, LONG_NAME, 7)],
        favoritesCount: 9,
        keptCount: 4,
        viewedCount: 42,
      });
    }
    // The shell asks who is signed in and reads `roles` off the answer; an
    // empty body throws inside a computed and stops the render mid-pass, which
    // leaves the heading on its fallback name.
    if (path.endsWith('/api/me')) {
      return json({ email: 'fixture@example.invalid', roles: ['ROLE_USER'] });
    }
    if (path.endsWith('/api/tags')) return json({ tags: [] });
    if (path.endsWith('/api/saved-searches')) return json({ savedSearches: [] });
    if (path.endsWith('/api/entries')) {
      return json({ entries: [entry(1), entry(2), entry(3)], nextCursor: null });
    }
    if (path.includes('/api/recommendations/runs/current')) {
      return json({
        status: 'none',
        batchesTotal: null,
        batchesDone: 0,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
      });
    }
    if (path.endsWith('/api/version')) {
      return json({
        version: 'dev',
        commit: 'local',
        builtAt: '',
        latest: null,
        updateAvailable: false,
      });
    }
    return json({});
  });
}

/** The heading bar's height for one feed, once its name and count are on screen.
 *  A cold deep link is enough here: the count and the name both come from the
 *  subscription list, which the shell loads on boot whichever list is on
 *  screen. */
async function headerHeight(page: Page, subscriptionId: number, name: string): Promise<number> {
  await page.goto(`/reader?subscription=${subscriptionId}`);
  const header = page.locator('.list-header');
  await expect(header.locator('h2')).toContainText(name.slice(0, 12));
  await expect(header.locator('.title-count')).toBeVisible();

  const box = await header.boundingBox();
  return box?.height ?? 0;
}

for (const viewport of [
  { label: 'desktop', size: { width: 1280, height: 900 } },
  { label: 'a phone', size: { width: 375, height: 812 } },
]) {
  test(`the count keeps the heading bar one row on ${viewport.label}`, async ({ page }) => {
    await page.setViewportSize(viewport.size);
    await stubReader(page);

    const short = await headerHeight(page, 1, SHORT_NAME);
    const long = await headerHeight(page, 2, LONG_NAME);

    expect(long).toBe(short);
  });
}
