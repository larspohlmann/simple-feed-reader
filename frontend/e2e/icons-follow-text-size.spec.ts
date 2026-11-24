import { Browser, expect, Page, test } from '@playwright/test';
import { makeTag } from '../src/app/reader/testing/tag.factory';
import { presetLocalStorage } from './support/auth';
import { AVATAR, FAVICON, POST, stubArticle, stubMagazine } from './support/icon-rows';
import { entryDetailJson, entryWire, oneFeedJson } from './support/reader';

/**
 * #2524: inside a text-scaled container a row of controls scales as one. Where a row's text follows
 * the text size, every icon in that row follows it too, and so does the text of its pills and counts.
 * Every API read is stubbed, so the spec owns the rows it measures.
 */
const DESKTOP = { width: 1280, height: 900 };

const COPY = entryWire({ id: 20, title: 'The same story elsewhere', source: 'Golem' });

const LIST_ENTRIES = [
  entryWire({
    id: 11,
    title: 'Posted by a person',
    source: 'Heise',
    poster: { name: 'Ada Poster', url: null, imageUrl: AVATAR },
  }),
  entryWire({ id: 12, title: 'From a site', source: 'Tagesschau', faviconUrl: FAVICON }),
  entryWire({
    id: 13,
    title: 'Covered twice',
    source: 'Der Spiegel',
    faviconUrl: FAVICON,
    duplicates: [COPY],
  }),
  entryWire({ id: 14, title: 'Another one', source: 'Heise', faviconUrl: FAVICON }),
  entryWire({ id: 15, title: 'And one more', source: 'Golem' }),
];

/** A run of eight from one feed folds into a source group, whose cards carry their pills and
 *  actions on the kicker line (see `magazine-compact-actions-flush.spec.ts`). */
function groupedEntry(id: number, source: string, subscriptionId: number) {
  return entryWire({
    id,
    title: `Short ${id}`,
    summary: null,
    source,
    subscriptionId,
    shortPlayerUrl: `https://www.youtube.com/shorts/fixture${id}`,
    social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
    likeCount: 5,
  });
}

const GROUPED_ENTRIES = [
  ...[1, 2, 3, 4, 5, 6, 7, 8].map((id) => groupedEntry(id, 'Heise', 2)),
  groupedEntry(9, 'Golem', 1),
  groupedEntry(10, 'Tagesschau', 3),
  groupedEntry(11, 'Netzpolitik', 4),
];

/** The rows that scale as one; each holds text that follows the text size. */
const ROWS = {
  kicker: 'app-entry-kicker-line',
  alsoFoot: '.also-foot',
  rowMeta: 'app-entry-row p.meta',
  articleSource: 'app-article-page article > p.meta',
  commentsHead: 'app-entry-comments header.head',
  commentActions: 'app-comment-actions',
  groupedKicker: 'app-source-group app-entry-kicker-line',
  countsRow: 'app-social-counts-row',
};

const ICONS = 'app-icon, app-favicon img, app-tag-glyph, app-network-logo';

type Step = 90 | 100 | 150;
type Surface = 'magazine' | 'grouped' | 'article';

interface Measurement {
  icons: Record<string, number[]>;
  texts: Record<string, number>;
  sidebarIcons: number[];
  headerIcons: number[];
  openInReaderIcon: number;
  deleteGlyph: number;
  replyGlyph: number;
}

interface View {
  surface: Surface;
  step: Step;
  viewport?: { width: number; height: number };
}

async function open(browser: Browser, { surface, step, viewport = DESKTOP }: View): Promise<Page> {
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    viewport,
  });
  const page = await context.newPage();
  await presetLocalStorage(page, {
    'sfr.textSize': String(step),
    'sfr.layout': surface === 'article' ? 'pane' : 'magazine',
  });
  if (surface === 'magazine') {
    await stubMagazine(page, LIST_ENTRIES);
    await page.goto('/');
    await expect(page.locator('.also-foot').first()).toBeVisible();
    await expect(page.locator('app-entry-kicker-line img.avatar').first()).toBeVisible();
  } else if (surface === 'grouped') {
    await stubMagazine(page, GROUPED_ENTRIES);
    await page.goto('/');
    await expect(page.locator(`${ROWS.groupedKicker} app-entry-pills .pill`).first()).toBeVisible();
    await expect(page.locator(`${ROWS.groupedKicker} app-entry-like .count`).first()).toBeVisible();
  } else {
    await stubArticle(page);
    await page.goto('/?subscription=1');
    await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
    await expect(page.locator('app-entry-comments li.comment')).toHaveCount(3);
    await expect(page.locator('app-entry-comments button.delete')).toBeVisible();
    await expect(page.locator('app-open-in-reader app-icon').first()).toBeAttached();
  }
  await page.evaluate(() => document.fonts.ready);
  return page;
}

function glyphHeight(element: Element): number {
  const box = element.tagName === 'APP-ICON' ? element.querySelector('span')! : element;
  return box.getBoundingClientRect().height;
}

async function iconHeights(page: Page, selector: string): Promise<number[]> {
  return page.locator(selector).evaluateAll(
    (elements, icons) =>
      elements.flatMap((element) =>
        [...element.querySelectorAll(icons)].map((icon) => {
          const box = icon.tagName === 'APP-ICON' ? icon.querySelector('span')! : icon;
          return box.getBoundingClientRect().height;
        }),
      ),
    ICONS,
  );
}

async function fontSize(page: Page, selector: string): Promise<number> {
  return page
    .locator(selector)
    .first()
    .evaluate((element) => parseFloat(getComputedStyle(element).fontSize));
}

const TEXTS: Record<Surface, Record<string, string>> = {
  magazine: {
    kicker: 'app-entry-kicker-line .kicker',
    kickerSource: 'app-entry-kicker-line .source',
    alsoFootLead: '.also-foot .lead',
    alsoFootEntry: '.also-foot .also-entry',
  },
  grouped: {
    when: 'app-source-group app-entry-kicker-line .when',
    shortPill: 'app-source-group app-entry-kicker-line app-entry-pills .pill',
    likeCount: 'app-source-group app-entry-kicker-line app-entry-like .count',
  },
  article: {
    rowMeta: 'app-entry-row p.meta',
    articleSource: 'app-article-page article > p.meta',
    commentsTitle: 'app-entry-comments header.head .title',
    commentsAge: 'app-entry-comments header.head .age',
    commentAuthor: 'app-entry-comments p.byline .author',
    commentScore: 'app-entry-comments p.byline app-vote-pill .number',
    replyLabel: 'app-social-counts-row button.reply .label',
    likeLabel: 'app-social-counts-row button.like .label',
    repostLabel: 'app-social-counts-row button.repost .label',
    voteNumber: 'app-social-counts-row app-vote-pill .number',
    openInReaderLabel: 'app-open-in-reader .open-in-reader-label',
  },
};

const ROWS_ON: Record<Surface, (keyof typeof ROWS)[]> = {
  magazine: ['kicker', 'alsoFoot'],
  grouped: ['groupedKicker'],
  article: ['rowMeta', 'articleSource', 'commentsHead', 'commentActions', 'countsRow'],
};

async function measure(browser: Browser, surface: Surface, step: Step): Promise<Measurement> {
  const page = await open(browser, { surface, step });
  const icons: Record<string, number[]> = {};
  for (const row of ROWS_ON[surface]) icons[row] = await iconHeights(page, ROWS[row]);
  const texts: Record<string, number> = {};
  for (const [name, selector] of Object.entries(TEXTS[surface])) {
    texts[name] = await fontSize(page, selector);
  }
  const single = (selector: string) =>
    surface === 'article' ? page.locator(selector).first().evaluate(glyphHeight) : 0;
  const measurement = {
    icons,
    texts,
    sidebarIcons: await iconHeights(page, 'aside.sidebar'),
    headerIcons: await iconHeights(page, 'app-list-header'),
    openInReaderIcon: await single('app-open-in-reader app-icon'),
    deleteGlyph: await single('app-entry-comments button.delete app-icon'),
    replyGlyph: await single('app-entry-comments button.reply app-icon'),
  };
  await page.context().close();
  return measurement;
}

/** The rows' text: `develop`'s sizes, except the grouped card's pill and like count, which now
 *  scale with their kicker line. */
const TEXT_SIZES: Record<Surface, Partial<Record<Step, Record<string, number>>>> = {
  magazine: {
    100: { kicker: 13, kickerSource: 13, alsoFootLead: 11, alsoFootEntry: 11 },
    150: { kicker: 19.5, kickerSource: 19.5, alsoFootLead: 16.5, alsoFootEntry: 16.5 },
  },
  grouped: {
    90: { when: 11.7, shortPill: 9.9, likeCount: 11.7 },
    100: { when: 13, shortPill: 11, likeCount: 13 },
    150: { when: 19.5, shortPill: 16.5, likeCount: 19.5 },
  },
  article: {
    100: {
      rowMeta: 13,
      articleSource: 13,
      commentsTitle: 18,
      commentsAge: 13,
      commentAuthor: 13,
      commentScore: 13,
      replyLabel: 13,
      likeLabel: 13,
      repostLabel: 13,
      voteNumber: 13,
      openInReaderLabel: 11,
    },
    150: {
      rowMeta: 19.5,
      articleSource: 19.5,
      commentsTitle: 27,
      commentsAge: 19.5,
      commentAuthor: 19.5,
      commentScore: 19.5,
      replyLabel: 19.5,
      likeLabel: 19.5,
      repostLabel: 19.5,
      voteNumber: 19.5,
      openInReaderLabel: 11,
    },
  },
};

/** The grouped card's kicker holds pills and actions too, so it is checked below 100% as well. */
const STEPS: Record<Surface, Exclude<Step, 100>[]> = {
  magazine: [150],
  grouped: [90, 150],
  article: [150],
};

test.describe('icons follow the text size of their row', () => {
  for (const surface of ['magazine', 'grouped', 'article'] as const) {
    test(`on the ${surface}, a scaled row's icons grow with its text, and nothing else moves`, async ({
      browser,
    }) => {
      const normal = await measure(browser, surface, 100);
      expect.soft(normal.texts, 'text at 100%').toEqual(TEXT_SIZES[surface][100]);

      for (const step of STEPS[surface]) {
        const scaled = await measure(browser, surface, step);
        const factor = step / 100;
        for (const row of ROWS_ON[surface]) {
          expect(normal.icons[row].length, `${row} shows no icon to measure`).toBeGreaterThan(0);
          expect(scaled.icons[row].length, row).toBe(normal.icons[row].length);
          normal.icons[row].forEach((height, index) => {
            expect
              .soft(
                Math.abs(scaled.icons[row][index] - height * factor),
                `${row} icon ${index} at ${step}%`,
              )
              .toBeLessThanOrEqual(0.5);
          });
        }
        expect.soft(scaled.texts, `text at ${step}%`).toEqual(TEXT_SIZES[surface][step]);
        expect.soft(scaled.sidebarIcons, 'sidebar icons').toEqual(normal.sidebarIcons);
        expect.soft(scaled.headerIcons, 'list header icons').toEqual(normal.headerIcons);
        if (surface === 'article') {
          expect.soft(scaled.openInReaderIcon, 'open-in-reader icon').toBe(normal.openInReaderIcon);
          expect.soft(scaled.deleteGlyph, 'delete glyph').toBeGreaterThan(0);
          expect.soft(scaled.deleteGlyph, 'delete beside reply').toBe(scaled.replyGlyph);
        }
      }
    });
  }
});

const PHONE = { width: 375, height: 812 };
const GROUPED_KICKER_HEIGHT = 23.5;

/** Each grouped action button's box, the card's, and whether an ancestor that clips overflow cuts it. */
async function actionBoxes(page: Page) {
  return page.locator(`${ROWS.groupedKicker} app-entry-actions button`).evaluateAll((buttons) =>
    buttons.map((button) => {
      const box = button.getBoundingClientRect();
      const card = button.closest('article')!.getBoundingClientRect();
      const inside = (outer: DOMRect) =>
        box.left >= outer.left - 0.5 &&
        box.right <= outer.right + 0.5 &&
        box.bottom <= outer.bottom + 0.5;
      const clippers: Element[] = [];
      for (let node = button.parentElement; node; node = node.parentElement) {
        if (getComputedStyle(node).overflowX !== 'visible') clippers.push(node);
      }
      return {
        insideCard: inside(card),
        clipped: clippers.some((clipper) => !inside(clipper.getBoundingClientRect())),
      };
    }),
  );
}

test.describe("a grouped card's kicker on a phone", () => {
  test('wraps its actions at 150% rather than letting the card clip them', async ({ browser }) => {
    const page = await open(browser, { surface: 'grouped', step: 150, viewport: PHONE });
    const boxes = await actionBoxes(page);
    expect(boxes.length, 'no grouped action button to measure').toBeGreaterThan(0);
    expect(boxes.filter((box) => !box.insideCard).length, 'buttons outside the card').toBe(0);
    expect(boxes.filter((box) => box.clipped).length, 'buttons clipped by an ancestor').toBe(0);
    await page.context().close();
  });

  test('stays one line at 100%', async ({ browser }) => {
    const page = await open(browser, { surface: 'grouped', step: 100, viewport: PHONE });
    const heights = await page
      .locator(`${ROWS.groupedKicker} .kicker`)
      .evaluateAll((lines) => lines.map((line) => line.getBoundingClientRect().height));
    expect(heights.length).toBeGreaterThan(0);
    expect(
      heights.every((height) => height === GROUPED_KICKER_HEIGHT),
      `${heights}`,
    ).toBe(true);
    await page.context().close();
  });
});

const PILL_TAGS = [
  makeTag(7, 'Politics', { icon: 'label' }),
  makeTag(8, 'Science', { icon: 'science' }),
];

const PILL_ROWS = {
  articleHead: 'app-reader-view .head-line',
  groupHead: 'app-source-group .ghead',
};

type PillRow = keyof typeof PILL_ROWS;

/** The article's head line carries the post's vote pill beside the feed's tag pills; a source
 *  group's head carries its name beside the same tags. */
interface PillRowView {
  row: PillRow;
  step: Step;
  viewport?: { width: number; height: number };
}

async function openPillRow(browser: Browser, { row, step, viewport = DESKTOP }: PillRowView) {
  const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, viewport });
  const page = await context.newPage();
  await presetLocalStorage(page, {
    'sfr.textSize': String(step),
    'sfr.layout': row === 'articleHead' ? 'pane' : 'magazine',
  });
  if (row === 'groupHead') {
    await stubMagazine(page, GROUPED_ENTRIES, { id: 2, tags: PILL_TAGS });
    await page.goto('/');
  } else {
    const scored = { ...POST, score: 12, myVote: null };
    await stubArticle(page);
    await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
      route.fulfill({ json: oneFeedJson('Fixture feed', { tags: PILL_TAGS }) }),
    );
    await page.route(/\/api\/tags(?:[?#]|$)/, (route) =>
      route.fulfill({ json: { tags: PILL_TAGS } }),
    );
    await page.route(
      (url) => url.pathname === '/api/entries',
      (route) => route.fulfill({ json: { entries: [scored], nextCursor: null } }),
    );
    await page.route(
      (url) => url.pathname === '/api/entries/1',
      (route) =>
        route.request().method() === 'GET'
          ? route.fulfill({ json: entryDetailJson(scored, '<p>The post itself.</p>') })
          : route.fallback(),
    );
    await page.goto('/?subscription=1');
    await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
    await expect(page.locator(`${PILL_ROWS[row]} app-vote-pill`)).toBeVisible();
  }
  await expect(page.locator(`${PILL_ROWS[row]} app-entry-pills a.pill`)).toHaveCount(2);
  await page.evaluate(() => document.fonts.ready);
  return page;
}

interface PillRowMeasurement {
  icons: number[];
  texts: number[];
  escapes: string[];
}

/** Icon heights and text sizes in the row, and every part of it that is cut or leaves the row. */
async function measurePillRow(page: Page, row: PillRow): Promise<PillRowMeasurement> {
  return page
    .locator(PILL_ROWS[row])
    .first()
    .evaluate((container, icons) => {
      const outer = container.getBoundingClientRect();
      const parts = [...container.querySelectorAll('*')].filter(
        (part) => part.getBoundingClientRect().width > 0,
      );
      const escapes = parts.flatMap((part) => {
        const box = part.getBoundingClientRect();
        const label = `${part.tagName.toLowerCase()}.${[...part.classList].join('.')}`;
        const outside =
          box.left < outer.left - 0.5 ||
          box.right > outer.right + 0.5 ||
          box.top < outer.top - 0.5 ||
          box.bottom > outer.bottom + 0.5;
        const cut =
          getComputedStyle(part).overflowX !== 'visible' && part.scrollWidth > part.clientWidth;
        return [
          ...(outside ? [`${label} leaves the row`] : []),
          ...(cut ? [`${label} is cut`] : []),
        ];
      });
      const texts = [...container.querySelectorAll('.name, .number')].map((text) =>
        parseFloat(getComputedStyle(text).fontSize),
      );
      const heights = [...container.querySelectorAll(icons)].map((icon) => {
        const box = icon.tagName === 'APP-ICON' ? icon.querySelector('span')! : icon;
        return box.getBoundingClientRect().height;
      });
      return { icons: heights, texts, escapes };
    }, ICONS);
}

test.describe('a row of pills scales as one', () => {
  for (const row of Object.keys(PILL_ROWS) as PillRow[]) {
    test(`${row}: icons and pill text grow with the text size, and nothing leaves the row on a phone`, async ({
      browser,
    }) => {
      const normalPage = await openPillRow(browser, { row, step: 100 });
      const normal = await measurePillRow(normalPage, row);
      await normalPage.context().close();
      const scaledPage = await openPillRow(browser, { row, step: 150 });
      const scaled = await measurePillRow(scaledPage, row);
      await scaledPage.context().close();

      expect(normal.icons.length, 'no icon in the row').toBeGreaterThan(0);
      expect(normal.texts.length, 'no pill text in the row').toBeGreaterThan(0);
      normal.icons.forEach((height, index) =>
        expect
          .soft(Math.abs(scaled.icons[index] - height * 1.5), `icon ${index}`)
          .toBeLessThanOrEqual(0.5),
      );
      normal.texts.forEach((size, index) =>
        expect.soft(scaled.texts[index], `text ${index}`).toBeCloseTo(size * 1.5, 1),
      );

      const phonePage = await openPillRow(browser, {
        row,
        step: 150,
        viewport: { width: 390, height: 760 },
      });
      expect((await measurePillRow(phonePage, row)).escapes).toEqual([]);
      await phonePage.context().close();
    });
  }
});
