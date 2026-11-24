import { Page, Request, expect, test } from '@playwright/test';
import { presetLocalStorage, STUB_PROFILE } from './support/auth';
import { stubMagazine } from './support/icon-rows';
import { entryWire } from './support/reader';

/**
 * #2524: the account's theme reaches the page from the first frame, Settings offers the choice, and
 * classic's palette and type land on real renders. Every API read is stubbed, so the spec owns the
 * entries it measures.
 */
test.use({ viewport: { width: 1280, height: 900 } });

const IMG =
  'data:image/svg+xml;base64,' +
  Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#8a8a86"/></svg>',
  ).toString('base64');

function imageEntry(id: number, source: string) {
  return { ...entryWire({ id, source }), imageUrl: IMG, imageWidth: 1200, imageHeight: 800 };
}

const POST = entryWire({
  id: 9,
  title: 'A short post',
  excerpt: 'A short post, in its own words.',
  titleDerived: true,
  source: 'Mastodon',
  social: { network: 'mastodon', isLiked: false, canLike: true, isOwn: false },
});

const ENTRIES = [
  imageEntry(1, 'Heise'),
  imageEntry(2, 'Tagesschau'),
  imageEntry(3, 'Heise'),
  imageEntry(4, 'Der Spiegel'),
  imageEntry(5, 'Tagesschau'),
  imageEntry(6, 'Der Spiegel'),
  imageEntry(7, 'Heise'),
  entryWire({ id: 8, title: 'A plain entry', source: 'Golem' }),
  POST,
];

const FULL_PROFILE = {
  ...STUB_PROFILE,
  email: 'theme@example.invalid',
  roles: ['ROLE_USER'],
  status: 'active',
  createdAt: '2026-07-01T00:00:00+00:00',
  locale: 'en',
  trialEndsAt: null,
  ai: { ready: false, model: null, capabilities: null },
  mail: { enabled: false },
  emailVerified: true,
};

const PREFERENCES = {
  scrapeFallbackEnabled: false,
  digest: { enabled: false, cadence: 'daily', sendHour: 8, weekday: 1, timezone: 'UTC' },
  passkeyOfferAnswered: true,
  magazineStyle: 'boxed',
  sidebarStyle: 'boxed',
  handedness: 'right',
  trendingEnabled: true,
};

async function stubAccount(page: Page, theme: string, release?: Promise<void>): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await release;
      await route.fulfill({
        json: { ...FULL_PROFILE, preferences: { ...PREFERENCES, theme } },
      });
    },
  );
}

interface ListView {
  local?: Record<string, string>;
  layout?: 'magazine' | 'pane';
}

async function openList(page: Page, theme: string, view: ListView = {}): Promise<void> {
  const { local = {}, layout = 'magazine' } = view;
  await presetLocalStorage(page, { 'sfr.theme': 'light', 'sfr.layout': layout, ...local });
  await stubMagazine(page, ENTRIES);
  await stubAccount(page, theme);
  await page.goto('/');
  await expect(
    page.locator(layout === 'magazine' ? '.rows.magazine' : 'app-entry-row').first(),
  ).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

function token(page: Page, name: string): Promise<string> {
  return page.evaluate(
    (property) => getComputedStyle(document.documentElement).getPropertyValue(property).trim(),
    name,
  );
}

function bodyBackground(page: Page): Promise<string> {
  return page.evaluate(() => getComputedStyle(document.body).backgroundColor);
}

interface Style {
  weight: string;
  lineHeight: string;
  letterSpacing: string;
  size: string;
}

async function styleOf(page: Page, selector: string): Promise<Style> {
  const element = page.locator(selector).first();
  await expect(element, `${selector} is not on the page`).toBeVisible();
  return element.evaluate((node) => {
    const style = getComputedStyle(node);
    return {
      weight: style.fontWeight,
      lineHeight: style.lineHeight,
      letterSpacing: style.letterSpacing,
      size: style.fontSize,
    };
  });
}

test.describe('palette', () => {
  test('classic paints its light and dark surfaces and accent', async ({ page }) => {
    await openList(page, 'classic');
    await expect(page.locator('html')).toHaveAttribute('data-theme-name', 'classic');
    expect(await token(page, '--surface-0')).toBe('#f2ecdd');
    expect(await token(page, '--accent')).toBe('#2f6f62');

    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    expect(await token(page, '--surface-0')).toBe('#161511');
    expect(await token(page, '--accent')).toBe('#6aa998');
  });

  test('graphite leaves the attribute off and keeps its own surface', async ({ page }) => {
    await openList(page, 'graphite');
    await expect(page.locator('html')).not.toHaveAttribute('data-theme-name', /.*/);
    expect(await token(page, '--surface-0')).toBe('#f5f5f4');
  });

  test("the brightness dial takes classic's own step, not graphite's", async ({ browser }) => {
    const backgrounds: Record<string, string> = {};
    for (const theme of ['classic', 'graphite']) {
      const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const page = await context.newPage();
      await openList(page, theme, { local: { 'sfr.brightness.light': '-2' } });
      backgrounds[theme] = await bodyBackground(page);
      if (theme === 'classic') {
        expect(
          await token(page, '--surface-0'),
          'the step did not move classic off its base',
        ).not.toBe('#f2ecdd');
      }
      await context.close();
    }

    expect(backgrounds['classic'], "classic's light step -2").toBe('rgb(184, 179, 164)');
    expect(backgrounds['graphite'], "graphite's light step -2").toBe('rgb(186, 186, 185)');
  });
});

const TITLES = {
  hero: 'app-magazine-block app-entry-hero .title',
  wide: 'app-magazine-block app-entry-wide .title',
  split: 'app-magazine-block app-entry-split .title',
  thumb: 'app-magazine-block app-entry-thumb .title',
  kicker: 'app-magazine-block app-entry-kicker .title:not(.post)',
};

function fontRequests(page: Page): Request[] {
  const requests: Request[] = [];
  page.on('request', (request) => {
    if (
      request.resourceType() === 'font' ||
      new URL(request.url()).pathname.startsWith('/fonts/')
    ) {
      requests.push(request);
    }
  });
  return requests;
}

test.describe('type under classic', () => {
  test('the whole app is set in Newsreader, titles in 600, and fonts stay on this origin', async ({
    page,
  }) => {
    const requests = fontRequests(page);
    await openList(page, 'classic');

    const family = await page.evaluate(() => getComputedStyle(document.body).fontFamily);
    expect(family).toMatch(/^"?Newsreader Classic"?, /);
    expect(family).toMatch(/georgia, serif$/i);

    for (const [kind, selector] of Object.entries(TITLES)) {
      const title = await styleOf(page, selector);
      expect(title.weight, `${kind} title weight`).toBe('600');
      expect(parseFloat(title.letterSpacing), `${kind} title letter-spacing`).toBeCloseTo(
        -0.006 * parseFloat(title.size),
        2,
      );
    }
    expect((await styleOf(page, 'app-magazine-block .title.post')).weight).toBe('400');

    const symbols = await page.locator('aside.sidebar .material-symbols-outlined').first();
    const symbolFamily = await symbols.evaluate((node) => getComputedStyle(node).fontFamily);
    expect(symbolFamily).toContain('Material Symbols');
    expect(symbolFamily).not.toContain('Newsreader');

    expect(requests.length, 'classic fetched no font file').toBeGreaterThan(0);
    const origin = new URL(page.url()).origin;
    for (const request of requests) {
      expect(new URL(request.url()).origin, `font request ${request.url()}`).toBe(origin);
    }
    expect(
      requests.some((request) =>
        request.url().endsWith('/fonts/newsreader-classic-latin-roman.woff2'),
      ),
    ).toBe(true);
  });
});

test.describe('type under graphite', () => {
  test('requests no font file, links no preload, and keeps every recorded literal', async ({
    page,
  }) => {
    const requests = fontRequests(page);
    await openList(page, 'graphite');

    expect(
      requests
        .map((request) => new URL(request.url()).pathname)
        .filter((path) => path.startsWith('/fonts/')),
    ).toEqual([]);
    await expect(page.locator('link[rel="preload"][as="font"]')).toHaveCount(0);

    const literal = async (selector: string, expected: Partial<Style>) => {
      const style = await styleOf(page, selector);
      expect({ selector, ...pick(style, expected) }).toEqual({ selector, ...expected });
    };
    await literal(TITLES.hero, { weight: '500', lineHeight: '31.2px', letterSpacing: 'normal' });
    await literal(TITLES.split, { weight: '500', lineHeight: '20.25px', letterSpacing: 'normal' });
    await literal(TITLES.thumb, { weight: '500', lineHeight: '20.25px', letterSpacing: 'normal' });
    await literal(TITLES.wide, { weight: '500', lineHeight: '23.4px', letterSpacing: 'normal' });
    await literal(TITLES.kicker, { weight: '500', lineHeight: '27px', letterSpacing: 'normal' });
    await literal('app-magazine-block app-entry-hero .dek', { lineHeight: '22.5px' });
    await literal('app-magazine-block app-entry-split .dek', { lineHeight: '19.5px' });
    await literal('app-magazine-block app-entry-kicker .dek', { lineHeight: '18.2px' });
    await literal('app-reader-header .brand .name', { weight: '700' });
    expect(
      await page
        .locator('aside.sidebar .count')
        .first()
        .evaluate((node) => getComputedStyle(node).fontVariantNumeric),
    ).toBe('tabular-nums');
  });

  test('keeps the list row literals', async ({ page }) => {
    await openList(page, 'graphite', { layout: 'pane' });

    const title = await styleOf(page, 'app-entry-row .title');
    expect(pick(title, { weight: '', lineHeight: '', letterSpacing: '' })).toEqual({
      weight: '500',
      lineHeight: '18.75px',
      letterSpacing: 'normal',
    });
    expect((await styleOf(page, 'app-entry-row .snippet')).lineHeight).toBe('19.5px');
  });
});

function pick(style: Style, keys: Partial<Style>): Partial<Style> {
  return Object.fromEntries(Object.keys(keys).map((key) => [key, style[key as keyof Style]]));
}

test.describe('first paint', () => {
  test('the attribute and the font preload are there before the account answers', async ({
    page,
  }) => {
    let release!: () => void;
    const held = new Promise<void>((resolve) => (release = resolve));
    await presetLocalStorage(page, { 'sfr.theme': 'light', 'sfr.themeName': 'classic' });
    await page.addInitScript(() => {
      const seen = { atAppRoot: null as string | null, atDomContentLoaded: null as string | null };
      Object.assign(window, { seenThemeName: seen });
      const attribute = () => document.documentElement.getAttribute('data-theme-name') ?? 'absent';
      new MutationObserver((_records, observer) => {
        if (!document.querySelector('app-root')) return;
        seen.atAppRoot = attribute();
        observer.disconnect();
      }).observe(document, { childList: true, subtree: true });
      document.addEventListener('DOMContentLoaded', () => {
        seen.atDomContentLoaded = attribute();
      });
    });
    await stubMagazine(page, ENTRIES);
    await stubAccount(page, 'classic', held);

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const seen = await page.evaluate(
      () => (window as unknown as { seenThemeName: Record<string, string | null> }).seenThemeName,
    );
    expect(seen['atAppRoot'], 'the attribute was missing when <app-root> was parsed').toBe(
      'classic',
    );
    expect(seen['atDomContentLoaded'], 'the attribute was missing at DOMContentLoaded').toBe(
      'classic',
    );
    await expect(page.locator('link[rel="preload"][as="font"][type="font/woff2"]')).toHaveAttribute(
      'href',
      '/fonts/newsreader-classic-latin-roman.woff2',
    );
    release();
  });

  test('a cached name that is not a theme paints graphite', async ({ page }) => {
    await presetLocalStorage(page, { 'sfr.themeName': 'sepia' });
    await page.goto('/login');

    await expect(page.locator('html')).toHaveAttribute('data-theme', /light|dark/);
    await expect(page.locator('html')).not.toHaveAttribute('data-theme-name', /.*/);
    await expect(page.locator('link[rel="preload"][as="font"]')).toHaveCount(0);
  });
});

test.describe('switching and signing out', () => {
  test('picking Classic in Settings paints it without a reload, and signing out clears it', async ({
    page,
  }) => {
    const patches: unknown[] = [];
    await presetLocalStorage(page, { 'sfr.theme': 'light', 'sfr.layout': 'magazine' });
    await stubMagazine(page, ENTRIES);
    await stubAccount(page, 'graphite');
    await page.route(
      (url) => url.pathname === '/api/me/preferences',
      async (route) => {
        if (route.request().method() !== 'PATCH') return route.fallback();
        patches.push(route.request().postDataJSON());
        await route.fulfill({ json: { ...PREFERENCES, theme: 'classic' } });
      },
    );
    await page.goto('/settings/preferences');

    await expect(page.locator('html')).not.toHaveAttribute('data-theme-name', /.*/);
    await page
      .getByRole('group', { name: 'Theme' })
      .getByRole('button', { name: 'Classic' })
      .click();

    await expect(page.locator('html')).toHaveAttribute('data-theme-name', 'classic');
    expect(patches).toEqual([{ theme: 'classic' }]);

    await page.getByRole('link', { name: 'Account' }).click();
    await page.getByRole('button', { name: 'Sign out' }).click();

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('html')).not.toHaveAttribute('data-theme-name', /.*/);
    expect(await page.evaluate(() => localStorage.getItem('sfr.themeName'))).toBeNull();
  });
});
