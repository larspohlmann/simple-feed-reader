import { Browser, expect, Locator, Page, test } from '@playwright/test';
import { makeTag } from '../src/app/reader/testing/tag.factory';
import { aStory } from '../src/testing/entry-testing';
import { settleAnimations } from './support/animations';
import { presetLocalStorage } from './support/auth';
import {
  expectTheme,
  LeadParts,
  measureFirstLine,
  stubAccountTheme,
  ThemeName,
} from './support/first-line';
import {
  NOTIFICATION,
  POST,
  stubArticle,
  stubMagazine,
  stubNotifications,
  stubTrending,
} from './support/icon-rows';
import {
  openAccountMenu,
  openRepostMenu,
  openShareMenu,
  openShareSheet,
  stubMenus,
} from './support/menu-rows';
import { entryDetailJson, entryWire } from './support/reader';

/**
 * #2558: an icon in front of text that can wrap hangs its centre on the cap middle of the text's
 * first line, whatever the theme or the text size. Every API read is stubbed, so the spec owns what it
 * measures. A case is real only when its text spans two lines or more.
 */
const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1280, height: 900 };
const TOLERANCE = 0.5;
const LINE_TOLERANCE = 1;
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

async function openPage(browser: Browser, { step }: Setup, viewport = PHONE): Promise<Page> {
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    viewport,
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

async function settle(page: Page, theme: ThemeName): Promise<void> {
  await expectTheme(page, theme);
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
      await expect(box.locator('.warning-text')).toBeVisible();
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
        const page = await openPage(browser, setup);
        try {
          await stub(page);
          // After the surface stubs: a later route wins, and they answer /api/me too.
          await stubAccountTheme(page, setup.theme);
          await page.goto(path);
          const parts = await locate(page);
          await settle(page, setup.theme);

          const measured = await measureFirstLine(parts);

          expect(measured.lines, 'the text spans two lines or more').toBeGreaterThanOrEqual(
            MIN_LINES,
          );
          expect(
            Math.abs(measured.offset),
            `icon centre ${measured.iconCentre} against cap middle ${measured.capMiddle}`,
          ).toBeLessThanOrEqual(TOLERANCE);
        } finally {
          await page.context().close();
        }
      });
    }
  });
}

const TAGS = [makeTag(7, 'Politics', { icon: 'home' }), makeTag(8, 'Science', { icon: 'science' })];
const ONE_ENTRY = [entryWire({ id: 1, title: 'One', source: 'Heise' })];
const BLUESKY_FEED = {
  social: { network: 'bluesky' as const, kind: 'home' as const, accountId: 1 },
};
const SCORED_POST = { ...POST, score: 22, myVote: null };

/** A pill or a button: its row, its icon and the element holding its text (null: bare text). */
interface PillCase {
  name: string;
  viewport: { width: number; height: number };
  stub: (page: Page) => Promise<void>;
  path: string;
  row: (page: Page) => Promise<Locator>;
  icon: string;
  text: string | null;
}

async function openArticle(page: Page): Promise<void> {
  await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
  await expect(page.locator('app-entry-comments li.comment')).toHaveCount(3);
}

const LEMMY_COMMENT = 'app-entry-comments li.comment:nth-child(2) app-social-counts-row';
const BLUESKY_COMMENT = 'app-entry-comments li.comment:nth-child(1) app-social-counts-row';

const PILL_CASES: PillCase[] = [
  {
    name: 'an entry tag pill',
    viewport: DESKTOP,
    stub: (page) => stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } }),
    path: '/?subscription=1',
    row: async (page) => {
      await openArticle(page);
      return page.locator('app-reader-view app-entry-pills a.pill:not(.feed)').first();
    },
    icon: 'app-tag-glyph',
    text: '.name',
  },
  {
    name: 'an entry network pill',
    viewport: DESKTOP,
    stub: (page) => stubArticle(page, { post: SCORED_POST, feed: BLUESKY_FEED }),
    path: '/?subscription=1',
    row: async (page) => {
      await openArticle(page);
      return page.locator('app-reader-view app-entry-pills a.pill.feed').first();
    },
    icon: 'app-network-logo',
    text: '.name',
  },
  {
    name: 'a header tag chip',
    viewport: PHONE,
    stub: (page) => stubMagazine(page, ONE_ENTRY, { tags: TAGS }),
    path: '/',
    row: async (page) => page.locator('.tagrow .chip:not(.all)').first(),
    icon: 'app-tag-glyph',
    text: null,
  },
  {
    name: 'a tag-picker chip',
    viewport: PHONE,
    stub: (page) => stubMagazine(page, ONE_ENTRY, { tags: TAGS }),
    path: '/?subscription=1',
    row: async (page) => {
      await page.getByRole('button', { name: 'Edit feed' }).first().click();
      return page.locator('app-tag-chips .chip').first();
    },
    icon: 'app-tag-glyph',
    text: '.label',
  },
  {
    name: 'Reply',
    viewport: DESKTOP,
    stub: (page) => stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } }),
    path: '/?subscription=1',
    row: async (page) => {
      await openArticle(page);
      return page.locator(`${LEMMY_COMMENT} button.reply`);
    },
    icon: 'app-icon',
    text: '.label',
  },
  {
    name: 'a phone Like whose count stands in for its hidden label',
    viewport: PHONE,
    stub: (page) => stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } }),
    path: '/?subscription=1',
    row: async (page) => {
      await openArticle(page);
      return page.locator(`${BLUESKY_COMMENT} button.like`);
    },
    icon: 'app-icon',
    text: '.number',
  },
  {
    name: 'an app-button with a leading icon',
    viewport: DESKTOP,
    stub: (page) => stubMagazine(page, ONE_ENTRY, { tags: TAGS }),
    path: '/settings/organise',
    row: async (page) => page.getByRole('button', { name: 'New tag' }),
    icon: 'app-icon',
    text: null,
  },
];

/** A control and the glyph it paints; `alone` when no text stands beside the glyph. */
interface ControlCase {
  name: string;
  viewport: { width: number; height: number };
  stub: (page: Page) => Promise<void>;
  path: string;
  control: (page: Page) => Promise<Locator>;
  alone: boolean;
}

const CONTROL_CASES: ControlCase[] = [
  {
    name: 'the health Unsubscribe button, a small app-button with only its glyph,',
    viewport: DESKTOP,
    stub: (page) => stubMagazine(page, ONE_ENTRY, { status: 'gone' }),
    path: '/settings/organise',
    control: async (page) => {
      await page.locator('.health-card .health-head').click();
      return page.locator('[data-test=unsubscribe] button');
    },
    alone: true,
  },
  {
    name: 'a small app-button with a leading icon',
    viewport: DESKTOP,
    stub: (page) => stubMagazine(page, ONE_ENTRY, { tags: TAGS }),
    path: '/settings/organise',
    control: async (page) => page.getByRole('button', { name: 'New tag' }),
    alone: false,
  },
  {
    name: 'a phone Reply with neither label nor count',
    viewport: PHONE,
    stub: (page) => stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } }),
    path: '/?subscription=1',
    control: async (page) => {
      await openArticle(page);
      return page.locator(`${LEMMY_COMMENT} button.reply`);
    },
    alone: true,
  },
];

/** The room between the painted glyph (not its slot, which may have no height) and each inner edge. */
async function measureGlyphInset(
  control: Locator,
): Promise<{ top: number; bottom: number; start: number; end: number }> {
  return control.evaluate((element) => {
    const style = getComputedStyle(element);
    const box = element.getBoundingClientRect();
    const glyph = element.querySelector('.material-symbols-outlined')!.getBoundingClientRect();
    return {
      top: glyph.top - box.top - parseFloat(style.borderTopWidth),
      bottom: box.bottom - parseFloat(style.borderBottomWidth) - glyph.bottom,
      start: glyph.left - box.left - parseFloat(style.borderLeftWidth),
      end: box.right - parseFloat(style.borderRightWidth) - glyph.right,
    };
  });
}

/** The glyph's start from the row's padding edge, and the gap from the glyph to the text. */
async function measureSpacing(
  row: Locator,
  icon: string,
  text: string | null,
): Promise<{ side: number; gap: number }> {
  return row.evaluate(
    (element, [iconSelector, textSelector]) => {
      const glyph = element.querySelector(iconSelector!)!;
      const glyphBox = glyph.getBoundingClientRect();
      const holder = textSelector ? element.querySelector(textSelector)! : element;
      const walker = document.createTreeWalker(holder, NodeFilter.SHOW_TEXT);
      let node: Node | null = walker.nextNode();
      while (node && (!node.textContent?.trim() || glyph.contains(node))) node = walker.nextNode();
      const range = document.createRange();
      range.selectNodeContents(node!);
      const edge =
        element.getBoundingClientRect().left +
        parseFloat(getComputedStyle(element).borderLeftWidth);
      return {
        side: glyphBox.left - edge,
        gap: range.getClientRects()[0].left - glyphBox.right,
      };
    },
    [icon, text] as const,
  );
}

async function preparePill(browser: Browser, setup: Setup, pill: PillCase): Promise<Page> {
  const page = await openPage(browser, setup, pill.viewport);
  await pill.stub(page);
  // After the surface stubs: a later route wins, and they answer /api/me too.
  await stubAccountTheme(page, setup.theme);
  await page.goto(pill.path);
  return page;
}

for (const setup of SETUPS) {
  test.describe(`pills and buttons, ${setup.theme} at ${setup.step}%`, () => {
    for (const pill of PILL_CASES) {
      test(`${pill.name} centres its icon on the cap middle, as far from the edge as from the text`, async ({
        browser,
      }) => {
        const page = await preparePill(browser, setup, pill);
        try {
          const row = await pill.row(page);
          await expect(row).toBeVisible();
          await settle(page, setup.theme);
          const textHolder = pill.text === null ? row : row.locator(pill.text);

          const measured = await measureFirstLine({
            icon: row.locator(pill.icon),
            firstLine: textHolder,
            block: textHolder,
          });
          const { side, gap } = await measureSpacing(row, pill.icon, pill.text);

          expect(
            Math.abs(measured.offset),
            `icon centre ${measured.iconCentre} against cap middle ${measured.capMiddle}`,
          ).toBeLessThanOrEqual(TOLERANCE);
          expect(Math.abs(side - gap), `side ${side} against gap ${gap}`).toBeLessThanOrEqual(
            TOLERANCE,
          );
        } finally {
          await page.context().close();
        }
      });
    }

    test('a network logo in a lead slot keeps its token size and stays visible', async ({
      browser,
    }) => {
      const pill = PILL_CASES.find(({ name }) => name === 'an entry network pill')!;
      const page = await preparePill(browser, setup, pill);
      try {
        const logo = (await pill.row(page)).locator('app-network-logo');
        await expect(logo).toBeVisible();
        await settle(page, setup.theme);

        const [width, height, token] = await logo.evaluate((element) => {
          const probe = document.createElement('div');
          probe.style.cssText = 'position: absolute; width: var(--icon-xs)';
          element.parentElement!.append(probe);
          const size = probe.getBoundingClientRect().width;
          probe.remove();
          const box = element.getBoundingClientRect();
          return [box.width, box.height, size];
        });
        expect([width, height], 'the logo box against --icon-xs').toEqual([token, token]);
      } finally {
        await page.context().close();
      }
    });

    for (const control of CONTROL_CASES) {
      test(`${control.name} paints its glyph inside its edges`, async ({ browser }) => {
        const page = await openPage(browser, setup, control.viewport);
        try {
          await control.stub(page);
          await stubAccountTheme(page, setup.theme);
          await page.goto(control.path);
          const element = await control.control(page);
          await expect(element).toBeVisible();
          await settle(page, setup.theme);

          const inset = await measureGlyphInset(element);
          for (const [edge, room] of Object.entries(inset)) {
            expect(room, `${edge} room ${room}`).toBeGreaterThanOrEqual(-TOLERANCE);
          }
          if (control.alone) {
            expect(
              Math.abs(inset.top - inset.bottom),
              `top ${inset.top} | bottom ${inset.bottom}`,
            ).toBeLessThanOrEqual(TOLERANCE);
            expect(
              Math.abs(inset.start - inset.end),
              `start ${inset.start} | end ${inset.end}`,
            ).toBeLessThanOrEqual(TOLERANCE);
          }
        } finally {
          await page.context().close();
        }
      });
    }

    test('a trailing chevron in an app-button keeps the gap token after its label and no lead space after it', async ({
      browser,
    }) => {
      const page = await openPage(browser, setup, DESKTOP);
      try {
        await stubMagazine(page, ONE_ENTRY, { tags: TAGS });
        await stubAccountTheme(page, setup.theme);
        await page.goto('/settings/organise');
        await page.getByRole('checkbox', { name: 'Select all' }).check();
        const button = page.locator('[data-test=visibility] button');
        await expect(button).toBeVisible();
        await settle(page, setup.theme);

        const [gap, token, start, end] = await button.evaluate((element) => {
          const box = element.getBoundingClientRect();
          const border = parseFloat(getComputedStyle(element).borderLeftWidth);
          const glyph = element.querySelector('app-icon')!.getBoundingClientRect();
          const range = document.createRange();
          range.setStartBefore(element.firstChild!);
          range.setEndBefore(element.querySelector('app-icon')!);
          const rects = [...range.getClientRects()].filter((rect) => rect.width > 0);
          return [
            glyph.left - rects.at(-1)!.right,
            parseFloat(getComputedStyle(element).columnGap),
            rects[0].left - box.left - border,
            box.right - border - glyph.right,
          ];
        });
        expect(Math.abs(gap - token), `gap ${gap} against token ${token}`).toBeLessThanOrEqual(
          TOLERANCE,
        );
        expect(
          Math.abs(start - end),
          `label side ${start}, chevron side ${end}`,
        ).toBeLessThanOrEqual(TOLERANCE);
      } finally {
        await page.context().close();
      }
    });

    test('the vote pill centres both arrows on the cap middle and its count between them', async ({
      browser,
    }) => {
      const page = await openPage(browser, setup, DESKTOP);
      try {
        await stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } });
        await stubAccountTheme(page, setup.theme);
        await page.goto('/?subscription=1');
        await openArticle(page);
        const pill = page.locator(`${LEMMY_COMMENT} app-vote-pill .pill`);
        await settle(page, setup.theme);

        for (const arrow of [0, 1]) {
          const measured = await measureFirstLine({
            icon: pill.locator('.arrow app-icon').nth(arrow),
            firstLine: pill.locator('.number'),
            block: pill.locator('.number'),
          });
          expect(Math.abs(measured.offset), `arrow ${arrow}`).toBeLessThanOrEqual(TOLERANCE);
        }
        const [before, after] = await pill.evaluate((element) => {
          const [up, down] = [...element.querySelectorAll('.arrow app-icon')].map((arrow) =>
            arrow.getBoundingClientRect(),
          );
          const range = document.createRange();
          range.selectNodeContents(element.querySelector('.number')!);
          const count = range.getBoundingClientRect();
          return [count.left - up.right, down.left - count.right];
        });
        expect(Math.abs(before - after), `${before} | ${after}`).toBeLessThanOrEqual(TOLERANCE);
      } finally {
        await page.context().close();
      }
    });
  });
}

/** A row of a menu: how to open the menu and find the row, its lead icon and the text it leads. */
interface MenuCase {
  name: string;
  touch: boolean;
  row: (page: Page) => Promise<Locator>;
  icon: string;
  text: string | null;
  /** The text is a single line, so its line stays centred in the row. */
  centred: boolean;
}

const accountRow = (select: (menu: Locator) => Locator) => async (page: Page) =>
  select(await openAccountMenu(page));
const shareRow = (index: number) => async (page: Page) =>
  (await openShareMenu(page)).getByRole('menuitem').nth(index);
const sheetRow = (index: number) => async (page: Page) =>
  (await openShareSheet(page)).getByRole('menuitem').nth(index);

const MENU_CASES: MenuCase[] = [
  {
    name: 'the account menu Settings item',
    touch: false,
    row: accountRow((menu) => menu.locator('a[href="/settings"]')),
    icon: 'app-icon',
    text: '.label',
    centred: true,
  },
  {
    name: 'the account menu notification item',
    touch: false,
    row: accountRow((menu) => menu.locator('a.item').first()),
    icon: 'app-network-logo',
    text: '.label',
    centred: true,
  },
  {
    name: 'the account menu Settings item on a coarse pointer',
    touch: true,
    row: accountRow((menu) => menu.locator('a[href="/settings"]')),
    icon: 'app-icon',
    text: '.label',
    centred: true,
  },
  {
    name: 'the account menu who block',
    touch: false,
    row: accountRow((menu) => menu.locator('.who')),
    icon: 'app-user-avatar',
    text: '.who-email',
    centred: false,
  },
  {
    name: 'a share menu post item',
    touch: false,
    row: shareRow(0),
    icon: 'app-network-logo',
    text: '.label',
    centred: true,
  },
  {
    name: 'a share menu target item',
    touch: false,
    row: shareRow(1),
    icon: 'app-share-target-logo',
    text: '.label',
    centred: true,
  },
  {
    name: 'a repost menu item',
    touch: false,
    row: async (page) => (await openRepostMenu(page)).getByRole('menuitem').first(),
    icon: 'app-icon',
    text: null,
    centred: true,
  },
  {
    name: 'an action-sheet item with one line',
    touch: true,
    row: sheetRow(1),
    icon: 'app-icon',
    text: null,
    centred: true,
  },
  {
    name: 'an action-sheet item with a hint',
    touch: true,
    row: sheetRow(0),
    icon: 'app-masked-logo',
    text: '.labelled',
    centred: false,
  },
];

/** A menu item that is no row: the text of `item` starts where the lead icons of its menu do. */
const EDGE_CASES = [
  { name: 'the account menu heading', menu: MENU_CASES[0], item: '.head' },
  { name: 'the account menu who avatar', menu: MENU_CASES[0], item: '.who > app-user-avatar' },
  { name: 'the share popover quote hint', menu: MENU_CASES[5], item: '.quote-hint' },
  { name: 'the action-sheet note', menu: MENU_CASES[8], item: '.note' },
  { name: 'the action-sheet title', menu: MENU_CASES[8], item: '.title' },
];

async function prepareMenu(browser: Browser, setup: Setup, menu: MenuCase): Promise<Page> {
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    viewport: menu.touch ? PHONE : DESKTOP,
    hasTouch: menu.touch,
    isMobile: menu.touch,
  });
  const page = await context.newPage();
  await page.clock.setFixedTime(new Date('2026-11-24T12:00:00Z'));
  await presetLocalStorage(page, {
    'sfr.textSize': String(setup.step),
    'sfr.layout': 'pane',
    'sfr.theme': 'light',
  });
  await stubMenus(page);
  await stubAccountTheme(page, setup.theme);
  await page.goto('/?subscription=1');
  return page;
}

/** The first text's line box: its middle against the row's, in px. */
async function measureLineInRow(row: Locator, text: string | null): Promise<number> {
  return row.evaluate((element, selector) => {
    const holder = selector ? element.querySelector(selector)! : element;
    const walker = document.createTreeWalker(holder, NodeFilter.SHOW_TEXT);
    let node: Node | null = walker.nextNode();
    while (node && !node.textContent?.trim()) node = walker.nextNode();
    const range = document.createRange();
    range.selectNodeContents(node!);
    const line = range.getClientRects()[0];
    const box = element.getBoundingClientRect();
    return line.top + line.height / 2 - (box.top + box.height / 2);
  }, text);
}

for (const setup of SETUPS) {
  test.describe(`menus, ${setup.theme} at ${setup.step}%`, () => {
    for (const menu of MENU_CASES) {
      test(`${menu.name} centres its icon on the cap middle, as far from the edge as from the text`, async ({
        browser,
      }) => {
        const page = await prepareMenu(browser, setup, menu);
        try {
          const row = await menu.row(page);
          await expect(row).toBeVisible();
          await settle(page, setup.theme);
          const textHolder = menu.text === null ? row : row.locator(menu.text);

          const measured = await measureFirstLine({
            icon: row.locator(menu.icon),
            firstLine: textHolder,
            block: textHolder,
          });
          const { side, gap } = await measureSpacing(row, menu.icon, menu.text);

          expect(
            Math.abs(measured.offset),
            `icon centre ${measured.iconCentre} against cap middle ${measured.capMiddle}`,
          ).toBeLessThanOrEqual(TOLERANCE);
          expect(Math.abs(side - gap), `side ${side} against gap ${gap}`).toBeLessThanOrEqual(
            TOLERANCE,
          );
          if (menu.centred) {
            const off = await measureLineInRow(row, menu.text);
            expect(Math.abs(off), `the line sits ${off}px off the row middle`).toBeLessThanOrEqual(
              LINE_TOLERANCE,
            );
          }
        } finally {
          await page.context().close();
        }
      });
    }

    for (const edge of EDGE_CASES) {
      test(`${edge.name} starts on the edge its row icons start at`, async ({ browser }) => {
        const page = await prepareMenu(browser, setup, edge.menu);
        try {
          const row = await edge.menu.row(page);
          await expect(row).toBeVisible();
          await settle(page, setup.theme);

          const [iconLeft, textLeft] = await row.evaluate(
            (element, [iconSelector, itemSelector]) => {
              const menu = element.closest('[role=menu]')!;
              const scope = element.closest('app-action-sheet') ?? menu;
              const item = scope.querySelector(itemSelector!)!;
              const iconLeft = menu.querySelector(iconSelector!)!.getBoundingClientRect().left;
              if (item.matches('app-user-avatar'))
                return [iconLeft, item.getBoundingClientRect().left];
              const walker = document.createTreeWalker(item, NodeFilter.SHOW_TEXT);
              let node: Node | null = walker.nextNode();
              while (node && !node.textContent?.trim()) node = walker.nextNode();
              const range = document.createRange();
              range.selectNodeContents(node!);
              return [iconLeft, range.getClientRects()[0].left];
            },
            [edge.menu.icon, edge.item] as const,
          );
          expect(
            Math.abs(iconLeft - textLeft),
            `icons ${iconLeft}, item ${textLeft}`,
          ).toBeLessThanOrEqual(TOLERANCE);
        } finally {
          await page.context().close();
        }
      });
    }

    test('a count pill in the account menu keeps the gap token after its label, centred in the row', async ({
      browser,
    }) => {
      const menu = MENU_CASES[1];
      const page = await prepareMenu(browser, setup, menu);
      try {
        const row = await menu.row(page);
        await expect(row.locator('app-count-pill')).toBeVisible();
        await settle(page, setup.theme);

        const [gap, token, off] = await row.evaluate((element) => {
          const pill = element.querySelector('app-count-pill')!.getBoundingClientRect();
          const label = element.querySelector('.label')!.getBoundingClientRect();
          const box = element.getBoundingClientRect();
          return [
            pill.left - label.right,
            parseFloat(getComputedStyle(element).columnGap),
            pill.top + pill.height / 2 - (box.top + box.height / 2),
          ];
        });
        expect(Math.abs(gap - token), `gap ${gap} against token ${token}`).toBeLessThanOrEqual(
          TOLERANCE,
        );
        expect(Math.abs(off), `the pill sits ${off}px off the row middle`).toBeLessThanOrEqual(
          TOLERANCE,
        );
      } finally {
        await page.context().close();
      }
    });
  });
}

const EPISODE_POST = {
  ...POST,
  attachments: [{ url: 'https://icons.invalid/episode.mp3', mimeType: 'audio/mpeg' }],
};
const CHAPTERS = [
  { startSeconds: 0, title: 'Opening' },
  { startSeconds: 20, title: 'Ending' },
];
const LONG_SEARCH = 'a search term long enough to wrap';

/** A header's lead: its row, its icon, the text it leads (null: bare text), and what else to pin. */
interface HeaderCase {
  name: string;
  viewport: { width: number; height: number };
  storage?: Record<string, string>;
  stub: (page: Page) => Promise<void>;
  path: string;
  row: (page: Page) => Promise<Locator>;
  icon: string;
  text: string | null;
  /** A box or a fixed row: the glyph sits as far from the edge as from the text. */
  spaced?: boolean;
  /** The text spans two lines or more. */
  wraps?: boolean;
  /** On one line, the row is its glyph plus this block inset twice, however much shorter the line. */
  clearance?: string;
  /** Gives the row a label long enough to wrap. */
  lengthen?: (row: Locator) => Promise<void>;
}

const WRAPPING_LABEL = 'A label long enough to wrap onto a second line in the article';

async function openEpisode(page: Page): Promise<void> {
  await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
}

async function stubEpisode(page: Page): Promise<void> {
  await stubArticle(page, { post: EPISODE_POST, feed: { tags: TAGS } });
  await page.route(
    (url) => url.pathname === '/api/entries/1',
    (route) =>
      route.request().method() === 'GET'
        ? route.fulfill({ json: entryDetailJson(EPISODE_POST, '<p>Show notes.</p>', CHAPTERS) })
        : route.fallback(),
  );
}

const HEADER_CASES: HeaderCase[] = [
  {
    name: 'the app-bar brand',
    viewport: DESKTOP,
    stub: (page) => stubMagazine(page, ONE_ENTRY),
    path: '/?subscription=1',
    row: async (page) => page.locator('app-reader-header .brand'),
    icon: 'app-spinner',
    text: '.name',
  },
  {
    name: 'the list-header lead of a feed title',
    viewport: PHONE,
    stub: (page) => stubMagazine(page, ONE_ENTRY),
    path: '/?subscription=1',
    row: async (page) => page.locator('.list-header h2'),
    icon: 'app-favicon',
    text: ':scope > span:not(.sr-only)',
  },
  {
    name: 'the list-header lead of a tag title',
    viewport: PHONE,
    stub: (page) => stubMagazine(page, ONE_ENTRY, { tags: TAGS }),
    path: `/?tag=${TAGS[0].id}`,
    row: async (page) => page.locator('.list-header h2'),
    icon: 'app-tag-glyph',
    text: ':scope > span:not(.sr-only)',
  },
  {
    name: 'the list-header lead of a search title',
    viewport: PHONE,
    stub: async (page) => {
      await stubMagazine(page, ONE_ENTRY);
      await page.route(
        (url) => url.pathname === '/api/entries/search',
        (route) => route.fulfill({ json: { entries: ONE_ENTRY, nextCursor: null } }),
      );
    },
    path: `/?q=${encodeURIComponent(LONG_SEARCH)}`,
    row: async (page) => page.locator('.list-header h2'),
    icon: 'app-icon',
    text: ':scope > span:not(.sr-only)',
    wraps: true,
  },
  {
    name: 'a labelled list action',
    viewport: DESKTOP,
    storage: { 'sfr.layout': 'list' },
    stub: (page) => stubMagazine(page, ONE_ENTRY),
    path: '/?subscription=1',
    row: async (page) => page.locator('.list-header .list-action.refresh'),
    icon: 'app-icon',
    text: '.txt',
  },
  {
    name: 'the article bar nameplate',
    viewport: DESKTOP,
    stub: (page) => stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } }),
    path: '/?subscription=1',
    row: async (page) => {
      await openArticle(page);
      return page.locator('app-article-layer .bar-name');
    },
    icon: 'app-favicon',
    text: '.bar-title',
  },
  {
    name: 'the full-screen mini nameplate',
    viewport: PHONE,
    stub: (page) => stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } }),
    path: '/?subscription=1',
    row: async (page) => {
      await openArticle(page);
      return page.locator('app-article-layer .mini');
    },
    icon: 'app-favicon',
    text: '.mini-title',
  },
  {
    name: 'the listen row',
    viewport: DESKTOP,
    stub: stubEpisode,
    path: '/?subscription=1',
    row: async (page) => {
      await openEpisode(page);
      return page.locator('app-listen-row .listen:not(.queue)');
    },
    icon: 'app-icon',
    text: '.listen-label',
    spaced: true,
    clearance: '--space-2',
  },
  {
    name: 'a listen row whose label wraps',
    viewport: PHONE,
    stub: stubEpisode,
    path: '/?subscription=1',
    row: async (page) => {
      await openEpisode(page);
      return page.locator('app-listen-row .listen:not(.queue)');
    },
    icon: 'app-icon',
    text: '.listen-label',
    spaced: true,
    wraps: true,
    lengthen: (row) =>
      row.locator('.listen-label').evaluate((label, text) => {
        label.textContent = text;
      }, WRAPPING_LABEL),
  },
  {
    name: 'a reader-panel toggle',
    viewport: DESKTOP,
    stub: stubEpisode,
    path: '/?subscription=1',
    row: async (page) => {
      await openEpisode(page);
      return page.locator('app-reader-panel .reader-panel__toggle').first();
    },
    icon: 'app-icon',
    text: null,
    clearance: '--space-2',
  },
  {
    name: 'a reader-panel toggle whose label wraps',
    viewport: PHONE,
    stub: stubEpisode,
    path: '/?subscription=1',
    row: async (page) => {
      await openEpisode(page);
      return page.locator('app-reader-panel .reader-panel__toggle').first();
    },
    icon: 'app-icon',
    text: null,
    wraps: true,
    lengthen: (row) =>
      row.evaluate((toggle, text) => {
        toggle.lastChild!.textContent = text;
      }, WRAPPING_LABEL),
  },
];

/** The row's height inside its border, and its glyph's height plus the inset token twice. */
async function measureClearance(
  row: Locator,
  icon: string,
  inset: string,
): Promise<[number, number]> {
  return row.evaluate(
    (element, [iconSelector, token]) => {
      const style = getComputedStyle(element);
      const probe = document.createElement('div');
      probe.style.cssText = `position: absolute; height: var(${token})`;
      element.append(probe);
      const insetSize = probe.getBoundingClientRect().height;
      probe.remove();
      const glyph = element.querySelector(`${iconSelector} span`)!.getBoundingClientRect().height;
      const height =
        element.getBoundingClientRect().height -
        parseFloat(style.borderTopWidth) -
        parseFloat(style.borderBottomWidth);
      return [height, glyph + 2 * insetSize];
    },
    [icon, inset] as const,
  );
}

for (const setup of SETUPS) {
  test.describe(`headers, ${setup.theme} at ${setup.step}%`, () => {
    for (const header of HEADER_CASES) {
      test(`${header.name} centres its icon on the cap middle`, async ({ browser }) => {
        const page = await openPage(browser, setup, header.viewport);
        try {
          if (header.storage) await presetLocalStorage(page, header.storage);
          await header.stub(page);
          await stubAccountTheme(page, setup.theme);
          await page.goto(header.path);
          const row = await header.row(page);
          await expect(row).toBeVisible();
          await settle(page, setup.theme);
          await header.lengthen?.(row);
          const textHolder = header.text === null ? row : row.locator(header.text).first();

          const measured = await measureFirstLine({
            icon: row.locator(header.icon).first(),
            firstLine: textHolder,
            block: textHolder,
          });

          expect(
            Math.abs(measured.offset),
            `icon centre ${measured.iconCentre} against cap middle ${measured.capMiddle}`,
          ).toBeLessThanOrEqual(TOLERANCE);
          if (header.wraps) {
            expect(measured.lines, 'the text spans two lines or more').toBeGreaterThanOrEqual(
              MIN_LINES,
            );
          }
          if (header.clearance) {
            const [height, expected] = await measureClearance(row, header.icon, header.clearance);
            expect(
              Math.abs(height - expected),
              `row ${height} against ${expected}`,
            ).toBeLessThanOrEqual(TOLERANCE);
          }
          if (header.spaced) {
            const { side, gap } = await measureSpacing(row, header.icon, header.text);
            expect(Math.abs(side - gap), `side ${side} against gap ${gap}`).toBeLessThanOrEqual(
              TOLERANCE,
            );
          }
        } finally {
          await page.context().close();
        }
      });
    }
  });
}
