import { Browser, expect, Locator, Page, test } from '@playwright/test';
import { makeTag } from '../src/app/reader/testing/tag.factory';
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
import {
  openAccountMenu,
  openRepostMenu,
  openShareMenu,
  openShareSheet,
  stubMenus,
} from './support/menu-rows';
import { entryWire } from './support/reader';

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

async function expectTheme(page: Page, theme: ThemeName): Promise<void> {
  const html = page.locator('html');
  if (theme === 'classic') await expect(html).toHaveAttribute('data-theme-name', 'classic');
  else await expect(html).not.toHaveAttribute('data-theme-name', /.*/);
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

    test('a phone Reply with neither label nor count sits midway between its edges', async ({
      browser,
    }) => {
      const page = await openPage(browser, setup, PHONE);
      try {
        await stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } });
        await stubAccountTheme(page, setup.theme);
        await page.goto('/?subscription=1');
        await openArticle(page);
        const reply = page.locator(`${LEMMY_COMMENT} button.reply`);
        await settle(page, setup.theme);

        const [start, end] = await reply.evaluate((button) => {
          const box = button.getBoundingClientRect();
          const border = parseFloat(getComputedStyle(button).borderLeftWidth);
          const glyph = button.querySelector('app-icon')!.getBoundingClientRect();
          return [glyph.left - box.left - border, box.right - border - glyph.right];
        });
        expect(Math.abs(start - end), `${start} | ${end}`).toBeLessThanOrEqual(TOLERANCE);
      } finally {
        await page.context().close();
      }
    });

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
