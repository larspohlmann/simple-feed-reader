import { Browser, expect, Page, test } from '@playwright/test';
import { makeTag } from '../src/app/reader/testing/tag.factory';
import { settleAnimations } from './support/animations';
import { presetLocalStorage } from './support/auth';
import { INLINE_IMAGE } from './support/images';
import {
  AVATAR,
  FAVICON,
  POST,
  STORY_TITLE,
  stubArticle,
  stubMagazine,
  stubNotifications,
  stubTrending,
} from './support/icon-rows';
import { entryWire } from './support/reader';

/**
 * #2524: inside a text-scaled container everything follows the text size, text and icons, in every
 * control, and an avatar in front of text with them; pictures, the glyphs on them and the chrome
 * around the content do not. Every API read is stubbed, so the spec owns what it measures.
 */
const DESKTOP = { width: 1280, height: 900 };

const COPY = entryWire({ id: 20, title: 'The same story elsewhere', source: 'Golem' });

const PILL_TAGS = [
  makeTag(7, 'Politics', { icon: 'label' }),
  makeTag(8, 'Science', { icon: 'science' }),
];

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

const SHORT_WITH_PICTURE = entryWire({
  id: 16,
  title: 'A short with a picture',
  source: 'Tagesschau',
  shortPlayerUrl: 'https://www.youtube.com/shorts/fixture16',
  imageUrl: INLINE_IMAGE,
  imageWidth: 1200,
  imageHeight: 800,
});

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

const PICTURE = { url: INLINE_IMAGE, kind: 'image' as const, width: 1200, height: 800 };

/** A short, a described photo and an animated picture, each shared as media: the play button, the
 *  ALT badge and the GIF badge sit on their pictures. */
const MEDIA_ENTRIES = [
  entryWire({
    id: 31,
    share: 'media',
    titleDerived: true,
    title: 'A short shared as media',
    shortPlayerUrl: 'https://www.youtube-nocookie.com/embed/fixture0031',
    media: [PICTURE],
  }),
  entryWire({
    id: 32,
    share: 'media',
    title: 'A photo with a description',
    media: [{ ...PICTURE, alt: 'A grey rectangle standing in for a photo.' }],
  }),
  entryWire({
    id: 33,
    share: 'media',
    title: 'An animated picture',
    media: [{ ...PICTURE, animated: true }],
  }),
];

const EPISODE = {
  ...POST,
  attachments: [{ url: 'https://fixtures.invalid/episode.mp3', mimeType: 'audio/mpeg' }],
};

type Step = 90 | 100 | 150;
type Surface = 'magazine' | 'grouped' | 'article' | 'notifications' | 'trending' | 'list' | 'media';

interface View {
  surface: Surface;
  step: Step;
  viewport?: { width: number; height: number };
}

interface Setup {
  step: Step;
  layout: 'pane' | 'magazine' | 'list';
  viewport: { width: number; height: number };
  stillMedia?: boolean;
}

async function openPage(
  browser: Browser,
  { step, layout, viewport, stillMedia = false }: Setup,
  load: (page: Page) => Promise<void>,
): Promise<Page> {
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    viewport,
    reducedMotion: stillMedia ? 'reduce' : 'no-preference',
  });
  const page = await context.newPage();
  await page.clock.setFixedTime(new Date('2026-11-24T12:00:00Z'));
  await presetLocalStorage(page, { 'sfr.textSize': String(step), 'sfr.layout': layout });
  await load(page);
  await page.evaluate(() => document.fonts.ready);
  await settleAnimations(page.locator('body'));
  return page;
}

const LAYOUTS: Record<Surface, Setup['layout']> = {
  magazine: 'magazine',
  grouped: 'magazine',
  article: 'pane',
  notifications: 'magazine',
  trending: 'magazine',
  list: 'list',
  media: 'magazine',
};

const LOADS: Record<Surface, (page: Page) => Promise<void>> = {
  magazine: async (page) => {
    await stubMagazine(page, LIST_ENTRIES, { tags: PILL_TAGS });
    await page.goto('/');
    await expect(page.locator('.also-foot').first()).toBeVisible();
    await expect(page.locator('app-entry-kicker-line img.avatar').first()).toBeVisible();
  },
  grouped: async (page) => {
    await stubMagazine(page, GROUPED_ENTRIES);
    await page.goto('/');
    await expect(page.locator('app-source-group button.more')).toBeVisible();
    await expect(
      page.locator('app-source-group app-entry-kicker-line app-entry-like .count').first(),
    ).toBeVisible();
  },
  article: async (page) => {
    await stubArticle(page, { post: EPISODE });
    await page.goto('/?subscription=1');
    await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
    await expect(page.locator('app-entry-comments li.comment')).toHaveCount(3);
    await expect(page.locator('app-entry-comments p.byline app-avatar').first()).toBeAttached();
    await expect(page.locator('app-listen-row')).toBeVisible();
  },
  notifications: async (page) => {
    await stubNotifications(page);
    await page.goto('/?view=notifications&network=bluesky');
    await expect(page.locator('app-notification-row app-avatar img')).toBeVisible();
  },
  trending: async (page) => {
    await stubTrending(page);
    await page.goto('/?view=trending&window=day');
    await expect(page.getByText(STORY_TITLE).first()).toBeVisible();
    await page.locator('app-story-sources button.toggle').click();
    await expect(
      page.locator('app-story-sources .thumb.tile app-favicon img').first(),
    ).toBeVisible();
  },
  list: async (page) => {
    await stubMagazine(page, [SHORT_WITH_PICTURE]);
    await page.goto('/');
    await expect(page.locator('app-short-badge')).toBeVisible();
  },
  media: async (page) => {
    await stubMagazine(page, MEDIA_ENTRIES);
    await page.goto('/');
    await expect(page.locator('app-entry-media button.play')).toBeVisible();
    await expect(page.locator('app-alt-text button.badge')).toBeVisible();
    await expect(page.locator('app-gif .badge')).toBeVisible();
  },
};

async function open(browser: Browser, { surface, step, viewport = DESKTOP }: View): Promise<Page> {
  return openPage(
    browser,
    { step, layout: LAYOUTS[surface], viewport, stillMedia: surface === 'media' },
    LOADS[surface],
  );
}

/** A part of the page: its font size, or the height of what it draws. `stays` marks a part that
 *  keeps its size at every step; `at100` is `develop`'s font size, which 100% must keep. */
interface Probe {
  selector: string;
  measure: 'font' | 'height';
  stays?: true;
  at100?: number;
}

const font = (selector: string, at100?: number): Probe => ({ selector, measure: 'font', at100 });
const height = (selector: string): Probe => ({ selector, measure: 'height' });
const glyph = (selector: string): Probe => height(`${selector} app-icon > span`);

const PROBES: Record<Surface, Record<string, Probe>> = {
  magazine: {
    kicker: font('app-entry-kicker-line .kicker', 13),
    kickerSource: font('app-entry-kicker-line .source', 13),
    kickerAvatar: height('app-entry-kicker-line img.avatar'),
    kickerFavicon: height('app-entry-kicker-line img.favicon'),
    pillText: font('app-entry-pills a.pill .name'),
    pillGlyph: height('app-entry-pills a.pill app-tag-glyph'),
    actionButton: glyph('app-entry-actions button'),
    alsoInLabel: font('.also-foot .lead', 11),
    alsoInButton: font('.also-foot .also-entry', 11),
  },
  grouped: {
    when: font('app-source-group app-entry-kicker-line .when', 13),
    shortPill: font('app-source-group app-entry-kicker-line app-entry-pills .pill', 11),
    likeCount: font('app-source-group app-entry-kicker-line app-entry-like .count', 13),
    likeGlyph: glyph('app-source-group app-entry-kicker-line app-entry-like'),
    moreFromLabel: font('app-source-group button.more'),
    moreFromGlyph: glyph('app-source-group button.more'),
  },
  article: {
    rowMeta: font('app-entry-row p.meta', 13),
    articleSource: font('app-article-page article > p.meta', 13),
    actionRow: glyph('app-reader-view app-entry-actions.actions button'),
    listenLabel: font('app-listen-row .listen-label'),
    listenGlyph: glyph('app-listen-row button.listen'),
    commentsTitle: font('app-entry-comments header.head .title', 18),
    commentsAge: font('app-entry-comments header.head .age', 13),
    commentsRefresh: height('app-entry-comments header.head button.ib'),
    commentAuthor: font('app-entry-comments p.byline .author', 13),
    commentScore: font('app-entry-comments p.byline app-vote-pill .number', 13),
    commentAvatar: height('app-entry-comments p.byline app-avatar'),
    deleteGlyph: glyph('app-entry-comments button.delete'),
    replyLabel: font('app-social-counts-row button.reply .label', 13),
    replyGlyph: glyph('app-social-counts-row button.reply'),
    likeLabel: font('app-social-counts-row button.like .label', 13),
    repostLabel: font('app-social-counts-row button.repost .label', 13),
    voteNumber: font('app-social-counts-row app-vote-pill .number', 13),
    voteArrow: glyph('app-social-counts-row app-vote-pill .arrow'),
    openInReaderLabel: font('app-open-in-reader .open-in-reader-label', 11),
    openInReaderGlyph: glyph('app-open-in-reader'),
    barTitle: { ...font('app-reader-view .bar-title'), stays: true },
    barButton: { ...glyph('app-reader-view .bar .nav button'), stays: true },
  },
  notifications: {
    line: font('app-notification-row p.line'),
    avatar: height('app-notification-row app-avatar'),
    kindGlyph: glyph('app-notification-row .kind'),
  },
  trending: {
    sourcesToggle: font('app-story-sources button.toggle'),
    sourcesGlyph: glyph('app-story-sources button.toggle'),
    miniTitle: font('app-story-sources .mini .title'),
    pictureTileFavicon: { ...height('app-story-sources .thumb.tile app-favicon img'), stays: true },
  },
  list: {
    rowTitle: font('app-entry-row .title'),
    shortBadge: glyph('app-short-badge'),
  },
  media: {
    playButton: font('app-entry-media button.play .material-symbols-outlined'),
    altBadge: font('app-alt-text button.badge'),
    gifBadge: font('app-gif .badge'),
  },
};

/** What each icon draws: an `app-icon`'s glyph span, any other icon itself. */
const GLYPHS = 'app-icon > span, app-favicon img, app-tag-glyph, app-network-logo';

async function iconHeights(page: Page, selector: string): Promise<number[]> {
  return page
    .locator(selector)
    .evaluateAll(
      (elements, glyphs) =>
        elements.flatMap((element) =>
          [...element.querySelectorAll(glyphs)].map(
            (glyph) => glyph.getBoundingClientRect().height,
          ),
        ),
      GLYPHS,
    );
}

async function measureProbe(page: Page, { selector, measure }: Probe): Promise<number> {
  const target = page.locator(selector).first();
  return measure === 'font'
    ? target.evaluate((element) => parseFloat(getComputedStyle(element).fontSize))
    : target.evaluate((element) => element.getBoundingClientRect().height);
}

/** Rows measured whole: every icon in them scales, and none goes missing at another step. */
const ROWS: Partial<Record<Surface, Record<string, string>>> = {
  magazine: { kicker: 'app-entry-kicker-line', alsoFoot: '.also-foot' },
  grouped: { groupedKicker: 'app-source-group app-entry-kicker-line' },
  article: {
    rowMeta: 'app-entry-row p.meta',
    articleSource: 'app-article-page article > p.meta',
    commentsHead: 'app-entry-comments header.head',
    commentActions: 'app-comment-actions',
    countsRow: 'app-social-counts-row',
  },
};

/** Controls that sit on a picture, which scale with the text but stay on their picture. */
const ON_PICTURES: Partial<Record<Surface, string[]>> = {
  media: [
    'app-entry-media button.play .material-symbols-outlined',
    'app-alt-text button.badge',
    'app-gif .badge',
  ],
  list: ['app-short-badge'],
  article: ['app-open-in-reader button'],
};

interface Measurement {
  probes: Record<string, number>;
  rows: Record<string, number[]>;
  sidebarIcons: number[];
  headerIcons: number[];
}

async function measure(browser: Browser, surface: Surface, step: Step): Promise<Measurement> {
  const page = await open(browser, { surface, step });
  const probes: Record<string, number> = {};
  for (const [name, probe] of Object.entries(PROBES[surface])) {
    probes[name] = await measureProbe(page, probe);
  }
  const rows: Record<string, number[]> = {};
  for (const [name, selector] of Object.entries(ROWS[surface] ?? {})) {
    rows[name] = await iconHeights(page, selector);
  }
  const measurement = {
    probes,
    rows,
    sidebarIcons: await iconHeights(page, 'aside.sidebar'),
    headerIcons: await iconHeights(page, 'app-reader-header, app-list-header'),
  };
  await page.context().close();
  return measurement;
}

/** Every text, control, icon and avatar inside a text-scaled container that is cut by an ancestor
 *  that clips, or that leaves the viewport; an ellipsis and screen-reader text are cut on purpose. */
async function escapes(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const parts = [...document.querySelectorAll('.text-scaled *')].filter((part) => {
      const box = part.getBoundingClientRect();
      return box.width > 0 && box.height > 0 && !part.closest('.sr-only');
    });
    const clipperOf = (part: Element) => {
      let clipper = part.parentElement;
      while (clipper && getComputedStyle(clipper).overflowX === 'visible') {
        clipper = clipper.parentElement;
      }
      return clipper;
    };
    const isCut = (part: Element) => {
      const box = part.getBoundingClientRect();
      const outer = clipperOf(part)?.getBoundingClientRect();
      return outer !== undefined && (box.right > outer.right + 0.5 || box.left < outer.left - 0.5);
    };
    const overflows = (part: Element) => {
      const style = getComputedStyle(part);
      return (
        style.overflowX !== 'visible' &&
        style.textOverflow !== 'ellipsis' &&
        part.scrollWidth > part.clientWidth + 1
      );
    };
    const found = parts.flatMap((part) => {
      const label = `${part.tagName.toLowerCase()}.${[...part.classList].join('.')}`;
      return [
        ...(isCut(part) ? [`${label} is cut`] : []),
        ...(overflows(part) ? [`${label} overflows`] : []),
        ...(part.getBoundingClientRect().right > window.innerWidth + 0.5
          ? [`${label} leaves the viewport`]
          : []),
      ];
    });
    const sideways =
      document.documentElement.scrollWidth > document.documentElement.clientWidth
        ? ['the page scrolls sideways']
        : [];
    return [...found, ...sideways];
  });
}

/** Each control on a picture that leaves its picture's box. */
async function offPicture(page: Page, selectors: string[]): Promise<string[]> {
  return page.evaluate((controls) => {
    const pictureOf = (control: Element) => {
      for (let node = control.parentElement; node; node = node.parentElement) {
        const picture = [...node.querySelectorAll('img, video')].find(
          (media) => !control.contains(media) && media.getBoundingClientRect().width > 0,
        );
        if (picture) return picture;
      }
      return null;
    };
    return controls.flatMap((selector) =>
      [...document.querySelectorAll(selector)].flatMap((control) => {
        const box = control.getBoundingClientRect();
        const frame = pictureOf(control)?.getBoundingClientRect();
        if (!frame) return [`${selector} has no picture`];
        const inside =
          box.left >= frame.left - 0.5 &&
          box.right <= frame.right + 0.5 &&
          box.top >= frame.top - 0.5 &&
          box.bottom <= frame.bottom + 0.5;
        return inside ? [] : [`${selector} leaves its picture`];
      }),
    );
  }, selectors);
}

const SURFACES = Object.keys(PROBES) as Surface[];

test.describe('everything in a text-scaled container follows the text size', () => {
  for (const surface of SURFACES) {
    test(`on the ${surface}, text, icons and avatars scale by the step; pictures and chrome stay`, async ({
      browser,
    }) => {
      const normal = await measure(browser, surface, 100);
      for (const [name, probe] of Object.entries(PROBES[surface])) {
        if (probe.at100 !== undefined) {
          expect.soft(normal.probes[name], `${name} at 100%`).toBe(probe.at100);
        }
      }
      for (const [row, heights] of Object.entries(normal.rows)) {
        expect(heights.length, `${row} shows no icon to measure`).toBeGreaterThan(0);
      }
      for (const step of [90, 150] as const) {
        const scaled = await measure(browser, surface, step);
        for (const [name, probe] of Object.entries(PROBES[surface])) {
          const expected = probe.stays ? normal.probes[name] : normal.probes[name] * (step / 100);
          expect
            .soft(Math.abs(scaled.probes[name] - expected), `${name} at ${step}%`)
            .toBeLessThanOrEqual(0.05);
        }
        for (const [row, heights] of Object.entries(normal.rows)) {
          expect(scaled.rows[row].length, `${row} at ${step}%`).toBe(heights.length);
          heights.forEach((height, index) => {
            expect
              .soft(
                Math.abs(scaled.rows[row][index] - height * (step / 100)),
                `${row} icon ${index}`,
              )
              .toBeLessThanOrEqual(0.5);
          });
        }
        expect.soft(scaled.sidebarIcons, `sidebar icons at ${step}%`).toEqual(normal.sidebarIcons);
        expect.soft(scaled.headerIcons, `header icons at ${step}%`).toEqual(normal.headerIcons);
      }
    });

    test(`on the ${surface}, nothing is cut or leaves the page on a phone at 150%`, async ({
      browser,
    }) => {
      const page = await open(browser, {
        surface,
        step: 150,
        viewport: { width: 390, height: 760 },
      });
      expect(await escapes(page)).toEqual([]);
      expect(await offPicture(page, ON_PICTURES[surface] ?? [])).toEqual([]);
      await page.context().close();
    });
  }
});

const PHONE = { width: 375, height: 812 };
const GROUPED_KICKER_HEIGHT = 23.5;

/** Each grouped action button's box, the card's, and whether an ancestor that clips overflow cuts it. */
async function actionBoxes(page: Page) {
  return page
    .locator(`app-source-group app-entry-kicker-line app-entry-actions button`)
    .evaluateAll((buttons) =>
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
      .locator(`app-source-group app-entry-kicker-line .kicker`)
      .evaluateAll((lines) => lines.map((line) => line.getBoundingClientRect().height));
    expect(heights.length).toBeGreaterThan(0);
    expect(
      heights.every((height) => height === GROUPED_KICKER_HEIGHT),
      `${heights}`,
    ).toBe(true);
    await page.context().close();
  });
});

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
  const layout = row === 'articleHead' ? 'pane' : 'magazine';
  return openPage(browser, { step, layout, viewport }, async (page) => {
    if (row === 'groupHead') {
      await stubMagazine(page, GROUPED_ENTRIES, { id: 2, tags: PILL_TAGS });
      await page.goto('/');
    } else {
      const scored = { ...POST, score: 12, myVote: null };
      await stubArticle(page, { post: scored, feed: { tags: PILL_TAGS } });
      await page.goto('/?subscription=1');
      await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
      await expect(page.locator(`${PILL_ROWS[row]} app-vote-pill`)).toBeVisible();
    }
    await expect(page.locator(`${PILL_ROWS[row]} app-entry-pills a.pill`)).toHaveCount(2);
  });
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
    .evaluate((container, glyphs) => {
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
      const heights = [...container.querySelectorAll(glyphs)].map(
        (glyph) => glyph.getBoundingClientRect().height,
      );
      return { icons: heights, texts, escapes };
    }, GLYPHS);
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
