import { expect, Locator, Page } from '@playwright/test';
import { aStory } from '../src/testing/entry-testing';
import {
  DESKTOP,
  expectNear,
  expectOnCapMiddle,
  firstTextRect,
  LeadParts,
  measureFirstLine,
  measureToken,
  PHONE,
  settle,
  Setup,
  Surface,
  test,
  TOLERANCE,
} from './support/first-line';
import {
  NOTIFICATION,
  ONE_ENTRY,
  openArticle,
  ORGANISE,
  POST,
  SCORED_ARTICLE,
  SCORED_POST,
  stubArticle,
  stubMagazine,
  stubNotifications,
  stubTrending,
  TAGS,
} from './support/icon-rows';
import {
  MENU_COARSE,
  MENU_FINE,
  openAccountMenu,
  openRepostMenu,
  openShareMenu,
  openShareSheet,
} from './support/menu-rows';
import { entryDetailJson, entryWire } from './support/reader';

/**
 * #2558: an icon in front of text that can wrap hangs its centre on the cap middle of the text's
 * first line, whatever the theme or the text size. Every API read is stubbed, so the spec owns what it
 * measures. A case is real only when its text spans two lines or more.
 */
const LINE_TOLERANCE = 1;
const MIN_LINES = 1.9;

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

/** The icon, the element whose first line it hangs on, and the whole text block, per surface. */
interface Case extends Surface {
  name: string;
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
    for (const surface of CASES) {
      test(`${surface.name} hangs its icon on the first line`, async ({ openSurface }) => {
        const page = await openSurface(setup, surface);
        const parts = await surface.parts(page);
        await settle(page, setup.theme);

        const measured = await measureFirstLine(parts);

        expect(measured.lines, 'the text spans two lines or more').toBeGreaterThanOrEqual(
          MIN_LINES,
        );
        expectOnCapMiddle(measured);
      });
    }
  });
}

const BLUESKY_FEED = {
  social: { network: 'bluesky' as const, kind: 'home' as const, accountId: 1 },
};
/** A lead in a pill, a button or a menu: its row, its icon and the element holding its text (null:
 *  bare text). */
interface LeadCase extends Surface {
  name: string;
  row: (page: Page) => Promise<Locator>;
  icon: string;
  text: string | null;
}

const LEMMY_COMMENT = 'app-entry-comments li.comment:nth-child(2) app-social-counts-row';
const BLUESKY_COMMENT = 'app-entry-comments li.comment:nth-child(1) app-social-counts-row';

const PILL_CASES: LeadCase[] = [
  {
    name: 'an entry tag pill',
    ...SCORED_ARTICLE,
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
    ...SCORED_ARTICLE,
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
    ...ORGANISE,
    row: async (page) => page.getByRole('button', { name: 'New tag' }),
    icon: 'app-icon',
    text: null,
  },
];

/** A control and the glyph it paints; `alone` when no text stands beside the glyph. */
interface ControlCase extends Surface {
  name: string;
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
    ...ORGANISE,
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
  { icon, text }: Pick<LeadCase, 'icon' | 'text'>,
): Promise<{ side: number; gap: number }> {
  const [glyph, edge, first] = await Promise.all([
    row
      .locator(icon)
      .first()
      .evaluate((element) => element.getBoundingClientRect().toJSON()),
    row.evaluate(
      (element) =>
        element.getBoundingClientRect().left +
        parseFloat(getComputedStyle(element).borderLeftWidth),
    ),
    firstTextRect(text ? row.locator(text).first() : row, text ? null : icon),
  ]);
  return { side: glyph.left - edge, gap: first.left - glyph.right };
}

/** The icon centres on the cap middle, and stands as far from the row's edge as from its text. */
async function expectLead(row: Locator, lead: LeadCase): Promise<void> {
  const textHolder = lead.text === null ? row : row.locator(lead.text);
  expectOnCapMiddle(
    await measureFirstLine({ icon: row.locator(lead.icon), firstLine: textHolder }),
  );
  const { side, gap } = await measureSpacing(row, lead);
  expectNear(side, gap, 'side against gap');
}

for (const setup of SETUPS) {
  test.describe(`pills and buttons, ${setup.theme} at ${setup.step}%`, () => {
    for (const pill of PILL_CASES) {
      test(`${pill.name} centres its icon on the cap middle, as far from the edge as from the text`, async ({
        openSurface,
      }) => {
        const page = await openSurface(setup, pill);
        const row = await pill.row(page);
        await expect(row).toBeVisible();
        await settle(page, setup.theme);

        await expectLead(row, pill);
      });
    }

    test('a network logo in a lead slot keeps its token size and stays visible', async ({
      openSurface,
    }) => {
      const pill = PILL_CASES.find(({ name }) => name === 'an entry network pill')!;
      const page = await openSurface(setup, pill);
      const logo = (await pill.row(page)).locator('app-network-logo');
      await expect(logo).toBeVisible();
      await settle(page, setup.theme);

      const [box, token] = await Promise.all([
        logo.evaluate((element) => element.getBoundingClientRect().toJSON()),
        measureToken(logo.locator('xpath=..'), 'var(--icon-xs)'),
      ]);
      expect([box.width, box.height], 'the logo box against --icon-xs').toEqual([token, token]);
    });

    for (const control of CONTROL_CASES) {
      test(`${control.name} paints its glyph inside its edges`, async ({ openSurface }) => {
        const page = await openSurface(setup, control);
        const element = await control.control(page);
        await expect(element).toBeVisible();
        await settle(page, setup.theme);

        const inset = await measureGlyphInset(element);
        for (const [edge, room] of Object.entries(inset)) {
          expect(room, `${edge} room ${room}`).toBeGreaterThanOrEqual(-TOLERANCE);
        }
        if (control.alone) {
          expectNear(inset.top, inset.bottom, 'top room against bottom room');
          expectNear(inset.start, inset.end, 'start room against end room');
        }
      });
    }

    test('a trailing chevron in an app-button keeps the gap token after its label and no lead space after it', async ({
      openSurface,
    }) => {
      const page = await openSurface(setup, ORGANISE);
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
      expectNear(gap, token, 'gap against the token');
      expectNear(start, end, 'label side against chevron side');
    });

    test('the vote pill centres both arrows on the cap middle and its count between them', async ({
      openSurface,
    }) => {
      const page = await openSurface(setup, SCORED_ARTICLE);
      await openArticle(page);
      const pill = page.locator(`${LEMMY_COMMENT} app-vote-pill .pill`);
      await settle(page, setup.theme);

      for (const arrow of [0, 1]) {
        const measured = await measureFirstLine({
          icon: pill.locator('.arrow app-icon').nth(arrow),
          firstLine: pill.locator('.number'),
        });
        expectOnCapMiddle(measured, `arrow ${arrow}`);
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
      expectNear(before, after, 'count to the up arrow against count to the down arrow');
    });
  });
}

const COUNTED_ENTRY = entryWire({
  id: 1,
  title: 'One',
  source: 'Heise',
  score: 22,
  myVote: null,
  commentCount: 6,
  likeCount: 4,
  social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
});

const COUNTED_LIST: Surface = {
  viewport: DESKTOP,
  stub: (page) => stubMagazine(page, [COUNTED_ENTRY]),
  path: '/?subscription=1',
};

/** A count beside a glyph: its row, the glyph and the number. */
interface CountCase extends Surface {
  name: string;
  open: (page: Page) => Promise<void>;
  row: string;
  icon: string;
  text: string;
}

const COUNT_CASES: CountCase[] = [
  {
    name: 'the list comment bubble',
    ...COUNTED_LIST,
    open: async () => undefined,
    row: 'app-entry-meta .comments',
    icon: 'app-icon',
    text: '.count',
  },
  {
    name: 'the list like button count',
    ...COUNTED_LIST,
    open: async () => undefined,
    row: 'app-entry-like button',
    icon: 'app-icon',
    text: '.count',
  },
  {
    name: 'the list like count without a button',
    viewport: DESKTOP,
    stub: (page) => stubMagazine(page, [{ ...COUNTED_ENTRY, social: null, myVote: 0 }]),
    path: '/?subscription=1',
    open: async () => undefined,
    row: 'app-entry-like .likes',
    icon: 'app-icon',
    text: '.count',
  },
  {
    name: 'the article social count',
    ...SCORED_ARTICLE,
    open: openArticle,
    row: 'app-social-counts app-social-counts-row .count',
    icon: 'app-icon',
    text: '.number',
  },
];

for (const setup of SETUPS) {
  test.describe(`counts, ${setup.theme} at ${setup.step}%`, () => {
    for (const count of COUNT_CASES) {
      test(`${count.name} centres its glyph on the cap middle`, async ({ openSurface }) => {
        const page = await openSurface(setup, count);
        await count.open(page);
        const row = page.locator(count.row).first();
        await expect(row).toBeVisible();
        await settle(page, setup.theme);

        expectOnCapMiddle(
          await measureFirstLine({
            icon: row.locator(count.icon).first(),
            firstLine: row.locator(count.text).first(),
          }),
        );
      });
    }

    test('the comment bubble and the vote arrows share a line', async ({ openSurface }) => {
      const page = await openSurface(setup, COUNTED_LIST);
      const discussion = page.locator('app-entry-meta .discussion').first();
      await expect(discussion.locator('.comments')).toBeVisible();
      await settle(page, setup.theme);

      const centres = await discussion.evaluate((element) =>
        ['.comments app-icon', 'app-vote-pill .arrow app-icon'].map((selector) => {
          const box = element.querySelector(selector)!.getBoundingClientRect();
          return box.top + box.height / 2;
        }),
      );
      expectNear(centres[0], centres[1], 'the bubble centre against the arrow centre');
    });
  });
}

/** A row of a menu: how to open the menu and find the row, its lead icon and the text it leads. */
interface MenuCase extends LeadCase {
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
    ...MENU_FINE,
    row: accountRow((menu) => menu.locator('a[href="/settings"]')),
    icon: 'app-icon',
    text: '.label',
    centred: true,
  },
  {
    name: 'the account menu notification item',
    ...MENU_FINE,
    row: accountRow((menu) => menu.locator('a.item').first()),
    icon: 'app-network-logo',
    text: '.label',
    centred: true,
  },
  {
    name: 'the account menu Settings item on a coarse pointer',
    ...MENU_COARSE,
    row: accountRow((menu) => menu.locator('a[href="/settings"]')),
    icon: 'app-icon',
    text: '.label',
    centred: true,
  },
  {
    name: 'the account menu who block',
    ...MENU_FINE,
    row: accountRow((menu) => menu.locator('.who')),
    icon: 'app-user-avatar',
    text: '.who-email',
    centred: false,
  },
  {
    name: 'a share menu post item',
    ...MENU_FINE,
    row: shareRow(0),
    icon: 'app-network-logo',
    text: '.label',
    centred: true,
  },
  {
    name: 'a share menu target item',
    ...MENU_FINE,
    row: shareRow(1),
    icon: 'app-share-target-logo',
    text: '.label',
    centred: true,
  },
  {
    name: 'a repost menu item',
    ...MENU_FINE,
    row: async (page) => (await openRepostMenu(page)).getByRole('menuitem').first(),
    icon: 'app-icon',
    text: null,
    centred: true,
  },
  {
    name: 'an action-sheet item with one line',
    ...MENU_COARSE,
    row: sheetRow(1),
    icon: 'app-icon',
    text: null,
    centred: true,
  },
  {
    name: 'an action-sheet item with a hint',
    ...MENU_COARSE,
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

/** The first text's line box: its middle against the row's, in px. */
async function measureLineInRow(row: Locator, text: string | null): Promise<number> {
  const [line, box] = await Promise.all([
    firstTextRect(text ? row.locator(text).first() : row),
    row.evaluate((element) => element.getBoundingClientRect().toJSON()),
  ]);
  return line.top + line.height / 2 - (box.top + box.height / 2);
}

/** The left of the first icon of `row`'s menu, and the left of the edge item's text (or avatar). */
async function measureEdge(row: Locator, icon: string, item: string): Promise<[number, number]> {
  const menu = row.locator('xpath=ancestor::*[@role="menu"][1]');
  const sheet = row.locator('xpath=ancestor::app-action-sheet[1]');
  const scope = (await sheet.count()) > 0 ? sheet : menu;
  const edgeItem = scope.locator(item).first();
  const iconLeft = await menu
    .locator(icon)
    .first()
    .evaluate((element) => element.getBoundingClientRect().left);
  const itemLeft = item.endsWith('app-user-avatar')
    ? await edgeItem.evaluate((element) => element.getBoundingClientRect().left)
    : (await firstTextRect(edgeItem)).left;
  return [iconLeft, itemLeft];
}

for (const setup of SETUPS) {
  test.describe(`menus, ${setup.theme} at ${setup.step}%`, () => {
    for (const menu of MENU_CASES) {
      test(`${menu.name} centres its icon on the cap middle, as far from the edge as from the text`, async ({
        openSurface,
      }) => {
        const page = await openSurface(setup, menu);
        const row = await menu.row(page);
        await expect(row).toBeVisible();
        await settle(page, setup.theme);

        await expectLead(row, menu);
        if (menu.centred) {
          const off = await measureLineInRow(row, menu.text);
          expect(Math.abs(off), `the line sits ${off}px off the row middle`).toBeLessThanOrEqual(
            LINE_TOLERANCE,
          );
        }
      });
    }

    for (const edge of EDGE_CASES) {
      test(`${edge.name} starts on the edge its row icons start at`, async ({ openSurface }) => {
        const page = await openSurface(setup, edge.menu);
        const row = await edge.menu.row(page);
        await expect(row).toBeVisible();
        await settle(page, setup.theme);

        const [iconLeft, itemLeft] = await measureEdge(row, edge.menu.icon, edge.item);
        expectNear(itemLeft, iconLeft, 'the item against the row icons');
      });
    }

    test('a count pill in the account menu keeps the gap token after its label, centred in the row', async ({
      openSurface,
    }) => {
      const menu = MENU_CASES[1];
      const page = await openSurface(setup, menu);
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
      expectNear(gap, token, 'gap against the token');
      expectNear(off, 0, 'the pill against the row middle');
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
interface HeaderCase extends LeadCase {
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
    ...SCORED_ARTICLE,
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
  const [height, glyph, insetSize] = await Promise.all([
    row.evaluate((element) => {
      const style = getComputedStyle(element);
      return (
        element.getBoundingClientRect().height -
        parseFloat(style.borderTopWidth) -
        parseFloat(style.borderBottomWidth)
      );
    }),
    row
      .locator(`${icon} span`)
      .first()
      .evaluate((element) => element.getBoundingClientRect().height),
    measureToken(row, `var(${inset})`),
  ]);
  return [height, glyph + 2 * insetSize];
}

for (const setup of SETUPS) {
  test.describe(`headers, ${setup.theme} at ${setup.step}%`, () => {
    for (const header of HEADER_CASES) {
      test(`${header.name} centres its icon on the cap middle`, async ({ openSurface }) => {
        const page = await openSurface(setup, header);
        const row = await header.row(page);
        await expect(row).toBeVisible();
        await settle(page, setup.theme);
        await header.lengthen?.(row);
        const textHolder = header.text === null ? row : row.locator(header.text).first();

        const measured = await measureFirstLine({
          icon: row.locator(header.icon).first(),
          firstLine: textHolder,
        });

        expectOnCapMiddle(measured);
        if (header.wraps) {
          expect(measured.lines, 'the text spans two lines or more').toBeGreaterThanOrEqual(
            MIN_LINES,
          );
        }
        if (header.clearance) {
          const [height, expected] = await measureClearance(row, header.icon, header.clearance);
          expectNear(height, expected, 'the row against its glyph and insets');
        }
        if (header.spaced) {
          const { side, gap } = await measureSpacing(row, header);
          expectNear(side, gap, 'side against gap');
        }
      });
    }
  });
}

test.describe('an icon-only list action', () => {
  test('keeps its glyph box while pending dots stand in for the icon', async ({ openSurface }) => {
    const page = await openSurface(
      { theme: 'graphite', step: 100 },
      {
        touch: true,
        stub: async (stubbed) => {
          await stubNotifications(stubbed);
          await stubbed.route('**/api/me/notifications/seen', () => new Promise(() => undefined));
        },
        path: '/?view=notifications&network=bluesky',
      },
    );
    const action = page.locator('button.mark-seen');
    const box = (glyph: string) =>
      action.locator(glyph).evaluate((element) => {
        const { width, height, top } = element.getBoundingClientRect();
        return { width, height, centre: top + height / 2 };
      });
    await expect(action.locator('app-icon')).toBeVisible();
    await settle(page, 'graphite');
    const icon = await box('app-icon');

    await action.dispatchEvent('click');
    await expect(action.locator('app-pending-dots')).toBeVisible();
    const dots = await box('app-pending-dots');
    const dot = await action.locator('app-pending-dots i').first().boundingBox();

    expectNear(dots.width, icon.width, 'the pending box width against the icon box');
    expectNear(dots.height, icon.height, 'the pending box height against the icon box');
    expectNear(dot!.y + dot!.height / 2, dots.centre, 'the dots against the middle of their box');
  });
});
