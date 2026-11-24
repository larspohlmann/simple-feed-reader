import { Browser, expect, Page, test } from '@playwright/test';
import { CommentsResponse } from '../src/app/reader/entry-comment';
import { aCommentPost, aLinkCard, anEntryComment } from '../src/testing/entry-testing';
import { presetLocalStorage } from './support/auth';
import { ONE_PIXEL_PNG } from './support/images';
import type { EntryDto } from '../src/app/reader/models';
import { entryDetailJson, entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';

/**
 * #2524: inside a text-scaled container a row of controls scales as one. Where a row's text follows
 * the text size, every icon in that row follows it too; the text itself stays at the size it had.
 * Every API read is stubbed, so the spec owns the rows it measures.
 */
const DESKTOP = { width: 1280, height: 900 };
const ICON_HOST = 'icons.invalid';
const AVATAR = `https://${ICON_HOST}/avatar.png`;
const FAVICON = `https://${ICON_HOST}/favicon.png`;

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

const POST = entryWire({
  id: 1,
  title: 'A post with comments',
  faviconUrl: FAVICON,
  social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
  comments: 'auto',
  discussionUrl: 'https://bsky.invalid/post/1',
});

const COMMENTS: CommentsResponse = {
  status: 'ok',
  fetchedAt: new Date().toISOString(),
  omitted: 0,
  comments: [
    anEntryComment({
      author: 'Own Reply',
      html: '<p>A reply the reader may delete.</p>',
      post: aCommentPost({ isOwn: true }),
    }),
    anEntryComment({
      author: 'Lemmy Voter',
      html: '<p>A comment the reader votes on.</p>',
      score: 7,
      vote: { address: 'https://lemmy.invalid/comment/1', myVote: 0, canReply: true },
    }),
    anEntryComment({
      author: 'Scored Comment',
      html: '<p>A comment with a score.</p>',
      score: 3,
      linkCard: aLinkCard(),
    }),
  ],
};

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

async function stubImages(page: Page): Promise<void> {
  await page.route(
    (url) => url.hostname === ICON_HOST,
    (route) => route.fulfill({ status: 200, contentType: 'image/png', body: ONE_PIXEL_PNG }),
  );
}

async function stubMagazine(page: Page, entries: EntryDto[]): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await stubImages(page);
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries, nextCursor: null } }),
  );
}

async function stubArticle(page: Page): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await stubImages(page);
  await page.route('**/api/client-errors', (route) => route.fulfill({ status: 204 }));
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [POST], nextCursor: null } }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1',
    (route) =>
      route.request().method() === 'GET'
        ? route.fulfill({ json: entryDetailJson(POST, '<p>The post itself.</p>') })
        : route.fallback(),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/reader',
    (route) => route.fulfill({ json: readerFailedJson() }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/social',
    (route) => route.fulfill({ json: { likes: 4, reposts: 1, replies: 6, quotes: 0 } }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/comments',
    (route) => route.fulfill({ json: COMMENTS }),
  );
}

async function open(browser: Browser, surface: Surface, step: Step): Promise<Page> {
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    viewport: DESKTOP,
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
  const page = await open(browser, surface, step);
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

/** Recorded on `develop` before #2524: the rows' text keeps exactly these sizes. */
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
        expect.soft(scaled.openInReaderIcon, 'open-in-reader icon').toBe(normal.openInReaderIcon);
        expect.soft(scaled.deleteGlyph, 'delete beside reply').toBe(scaled.replyGlyph);
      }
    });
  }
});
