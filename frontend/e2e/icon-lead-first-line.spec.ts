import { Browser, expect, Page, test } from '@playwright/test';
import { aStory } from '../src/testing/entry-testing';
import { settleAnimations } from './support/animations';
import { presetLocalStorage } from './support/auth';
import { LeadParts, measureFirstLine, stubAccountTheme, ThemeName } from './support/first-line';
import {
  NOTIFICATION,
  POST,
  stubArticle,
  stubMagazine,
  stubNotifications,
  stubTrending,
} from './support/icon-rows';
import { entryWire } from './support/reader';

/**
 * #2558: an icon in front of text that can wrap hangs its centre on the centre of the text's first
 * line, whatever the theme or the text size. Every API read is stubbed, so the spec owns what it
 * measures. A case is real only when its text spans two lines or more.
 */
const PHONE = { width: 375, height: 812 };
const TOLERANCE = 0.5;
const MIN_LINES = 1.9;

interface Setup {
  theme: ThemeName;
  step: 100 | 150;
}

const LONG_STORY = aStory({
  id: 601,
  title: 'A story five outlets ran under headlines that each had to be long enough to wrap twice',
});

const PAYWALLED_BODY = {
  status: 'ok',
  url: 'https://news.invalid/story',
  title: 'The story',
  byline: null,
  siteName: 'news.invalid',
  contentHtml: '<p>The free preview.</p>',
  excerpt: null,
  paywalled: true,
  originalHero: null,
  extractedAt: '2026-11-24T10:00:00+00:00',
};

async function openPhone(browser: Browser, { step }: Setup): Promise<Page> {
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    viewport: PHONE,
  });
  const page = await context.newPage();
  await page.clock.setFixedTime(new Date('2026-11-24T12:00:00Z'));
  await presetLocalStorage(page, {
    'sfr.textSize': String(step),
    'sfr.layout': 'pane',
    'sfr.theme': 'light',
  });
  return page;
}

async function expectTheme(page: Page, theme: ThemeName): Promise<void> {
  const html = page.locator('html');
  if (theme === 'classic') await expect(html).toHaveAttribute('data-theme-name', 'classic');
  else await expect(html).not.toHaveAttribute('data-theme-name', /.*/);
}

async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await settleAnimations(page.locator('body'));
}

/** The icon, the element whose first line it hangs on, and the whole text block, per surface. */
interface Case {
  name: string;
  stub: (page: Page) => Promise<void>;
  path: string;
  parts: (page: Page) => Promise<LeadParts>;
}

const CASES: Case[] = [
  {
    name: 'a warning box',
    stub: async (page) => {
      await stubArticle(page, { post: { ...POST, comments: null } });
      await page.route(
        (url) => url.pathname === '/api/entries/1/reader',
        (route) => route.fulfill({ json: PAYWALLED_BODY }),
      );
    },
    path: '/?subscription=1&entry=1',
    parts: async (page) => {
      const box = page.locator('app-paywall-notice app-warning-box');
      await expect(box.locator('.warning-glyph')).toBeVisible();
      return {
        icon: box.locator('.warning-glyph'),
        firstLine: box.locator('.warning-text'),
        block: box.locator('.warning-text'),
      };
    },
  },
  {
    name: 'a settings group head',
    stub: (page) => stubMagazine(page, [entryWire({ id: 1, title: 'One', source: 'Heise' })]),
    path: '/settings/visibility',
    parts: async (page) => {
      const head = page.locator('app-settings-group .g-head').first();
      await expect(head.locator('.g-caption')).toBeVisible();
      return {
        icon: head.locator('.g-icon'),
        firstLine: head.locator('.g-title'),
        block: head.locator('.g-text'),
      };
    },
  },
  {
    name: 'a notification row',
    stub: async (page) => {
      await stubNotifications(page);
      await page.route(/\/api\/me\/notifications(?:[?#]|$)/, (route) =>
        route.fulfill({
          json: {
            groups: [{ ...NOTIFICATION, actors: [], actorCount: 0 }],
            nextCursor: null,
            scopeMissing: [],
            needsReconnect: [],
          },
        }),
      );
    },
    path: '/?view=notifications&network=bluesky',
    parts: async (page) => {
      const row = page.locator('app-notification-row');
      await expect(row.locator('p.line')).toBeVisible();
      await expect(row.locator('.avatars')).toHaveCount(0);
      return {
        icon: row.locator('.kind app-icon'),
        firstLine: row.locator('p.line'),
        block: row.locator('p.line'),
      };
    },
  },
  {
    name: 'a trending headline',
    stub: async (page) => {
      await stubTrending(page);
      await page.route(
        (url) => url.pathname === '/api/trending',
        (route) =>
          route.fulfill({
            json: { window: 'day', builtAt: '2026-11-18T08:00:00Z', stories: [LONG_STORY] },
          }),
      );
    },
    path: '/?view=trending&window=day',
    parts: async (page) => {
      const headline = page.locator('app-story-headline').first();
      await expect(headline.locator('.title')).toBeVisible();
      return {
        icon: headline.locator('app-icon.icon'),
        firstLine: headline.locator('.title'),
        block: headline.locator('.title'),
      };
    },
  },
];

const SETUPS: Setup[] = [
  { theme: 'graphite', step: 100 },
  { theme: 'classic', step: 100 },
  { theme: 'graphite', step: 150 },
  { theme: 'classic', step: 150 },
];

for (const setup of SETUPS) {
  test.describe(`${setup.theme} at ${setup.step}%`, () => {
    for (const { name, stub, path, parts: locate } of CASES) {
      test(`${name} hangs its icon on the first line`, async ({ browser }) => {
        const page = await openPhone(browser, setup);
        await stub(page);
        await stubAccountTheme(page, setup.theme);
        await page.goto(path);
        const parts = await locate(page);
        await settle(page);
        await expectTheme(page, setup.theme);

        const measured = await measureFirstLine(parts);

        expect(measured.lines, 'the text spans two lines or more').toBeGreaterThanOrEqual(
          MIN_LINES,
        );
        expect(
          Math.abs(measured.offset),
          `icon centre ${measured.iconCentre} against line centre ${measured.lineCentre}`,
        ).toBeLessThanOrEqual(TOLERANCE);
        await page.context().close();
      });
    }
  });
}
