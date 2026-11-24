#!/usr/bin/env node
// Measures where classic's text sits against its icons, compared with graphite's.
// Usage: node scripts/fonts/measure-metrics.mjs --browser chromium|webkit [--styles <out.json>] [--graphite-only]
import { chromium, webkit } from '@playwright/test';
import { build } from 'esbuild';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';

const BASE_URL = 'http://localhost:4200';
const VIEWPORT = { width: 1280, height: 900 };
const OFFSET_TOLERANCE = 0.25;
const X_HEIGHT_TOLERANCE = 0.2;
const X_HEIGHT_TARGET = 0.5;
const JUDGED_LINE = 15;
const LINE_SIZES = [11, 13, JUDGED_LINE, 24];
const FONT_TIMEOUT = 10_000;
const CLASSIC_FACE = "16px 'Newsreader Classic'";
const FRONTEND = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const BROWSERS = { chromium, webkit };

const CONTROLS = [
  {
    name: 'tag pill',
    surface: 'magazine',
    control: 'app-entry-pills a.pill:has(app-tag-glyph)',
    text: '.name',
    icon: 'app-tag-glyph .material-symbols-outlined',
  },
  {
    name: 'vote pill',
    surface: 'article',
    control: 'app-entry-comments app-vote-pill:not(.bare) .pill',
    text: '.number',
    icon: '.arrow .material-symbols-outlined',
  },
  {
    name: 'Reply',
    surface: 'article',
    control: 'app-entry-comments button.reply',
    label: 'Reply',
    text: '.label',
    icon: '.material-symbols-outlined',
  },
  {
    name: 'Add comment',
    surface: 'article',
    control: 'app-social-counts-row button.reply',
    label: 'Add comment',
    text: '.label',
    icon: '.material-symbols-outlined',
  },
  {
    name: 'sidebar row',
    surface: 'magazine',
    control: 'aside.sidebar a.nav.all',
    text: '.name',
    icon: '.material-symbols-outlined',
  },
];

/** The elements whose type the classic theme converts, and the properties it may touch. */
const STYLED = {
  magazine: [
    'app-magazine-block .title',
    'app-magazine-block .dek',
    'app-reader-header .brand',
    'app-reader-header .brand .name',
    'aside.sidebar .count',
    'aside.sidebar .section-count',
    'aside.sidebar .name',
    'app-entry-pills .name',
  ],
  'magazine-text': ['app-magazine-block .title', 'app-magazine-block .dek'],
  'magazine-shares': [
    'app-magazine-block .title',
    'app-magazine-block .dek',
    'app-magazine-block app-link-card strong',
  ],
  article: [
    'app-entry-row .title',
    'app-entry-row .snippet',
    'app-article-page .title',
    'app-article-content',
    'app-article-content p',
    'app-article-content h2',
    'app-link-card strong',
    'app-entry-comments .title',
    'app-social-counts-row .label',
  ],
};
const STYLE_PROPERTIES = [
  'font-family',
  'font-size',
  'font-weight',
  'font-style',
  'line-height',
  'letter-spacing',
  'font-variant-numeric',
];

const ARTICLE_BODY =
  '<p>The post itself, with <em>an italic phrase</em> in the running text.</p>' +
  '<h2>A section heading</h2><p>More of the article.</p>';

async function loadFixtures() {
  const outfile = join(FRONTEND, 'node_modules', '.cache', 'measure-metrics', 'fixtures.mjs');
  await build({
    stdin: {
      contents: [
        "export * from './e2e/support/icon-rows';",
        "export { entryWire } from './e2e/support/reader';",
        "export { presetLocalStorage } from './e2e/support/auth';",
        "export { stubFixtureImages } from './e2e/support/images';",
        "export { makeTag } from './src/app/reader/testing/tag.factory';",
        "export { aLinkCard, aQuotedPost } from './src/testing/entry-testing';",
        "export { aThreadPost } from './src/testing/thread-testing';",
      ].join('\n'),
      resolveDir: FRONTEND,
      loader: 'ts',
    },
    bundle: true,
    format: 'esm',
    platform: 'node',
    external: ['@playwright/test'],
    outfile,
    logLevel: 'warning',
  });
  return import(pathToFileURL(outfile).href);
}

function magazineEntries(fixtures) {
  const long = 'A summary long enough to be quoted, '.repeat(12);
  const image = (id) => ({
    imageUrl: `https://fixtures.invalid/${id}.jpg`,
    imageWidth: 1200,
    imageHeight: 800,
  });
  return [
    fixtures.entryWire({
      id: 1,
      title: 'A story with a picture',
      faviconUrl: fixtures.FAVICON,
      ...image(1),
    }),
    fixtures.entryWire({
      id: 2,
      title: 'A second story',
      faviconUrl: fixtures.FAVICON,
      ...image(2),
    }),
    fixtures.entryWire({ id: 3, title: 'A story in words', summary: long, excerpt: long }),
    fixtures.entryWire({ id: 4, title: 'Another picture', ...image(4) }),
    fixtures.entryWire({
      id: 5,
      title: 'A post',
      titleDerived: true,
      excerpt: 'A post someone wrote.',
    }),
    fixtures.entryWire({ id: 6, title: 'A plain one', summary: null, excerpt: '' }),
    fixtures.entryWire({ id: 7, title: 'One more picture', ...image(7) }),
    fixtures.entryWire({ id: 8, title: 'And a last one', summary: long, excerpt: long }),
  ];
}

function textEntries(fixtures) {
  const long = 'Copy long enough for a pull quote to stand on its own, '.repeat(10);
  return [1, 2, 3, 4, 5, 6, 7, 8].map((id) =>
    fixtures.entryWire({
      id,
      title: `A story told in text ${id}`,
      summary: long,
      excerpt: long,
      ...(id === 4 ? { titleDerived: true, excerpt: 'A short post.' } : {}),
    }),
  );
}

function shareEntries(fixtures) {
  const picture = (id, width) => ({
    imageUrl: `https://fixtures.invalid/${id}.jpg`,
    imageWidth: width,
    imageHeight: Math.round(width / 1.5),
  });
  const media = (id) => [
    { url: `https://fixtures.invalid/m${id}.jpg`, kind: 'image', width: 1200, height: 800 },
  ];
  return [
    fixtures.entryWire({
      id: 1,
      title: 'A shared link',
      share: 'link',
      linkCards: [fixtures.aLinkCard()],
    }),
    fixtures.entryWire({ id: 2, title: 'A shared picture', share: 'media', media: media(2) }),
    fixtures.entryWire({
      id: 3,
      title: 'A quoting post',
      share: 'quote',
      quote: fixtures.aQuotedPost(),
    }),
    fixtures.entryWire({ id: 4, title: 'A thread', thread: [fixtures.aThreadPost()] }),
    fixtures.entryWire({ id: 5, title: 'A small picture', ...picture(5, 200) }),
    fixtures.entryWire({ id: 6, title: 'Another small picture', ...picture(6, 200) }),
    fixtures.entryWire({ id: 7, title: 'A plain entry' }),
    fixtures.entryWire({ id: 8, title: 'A last small picture', ...picture(8, 200) }),
  ];
}

const ENTRIES = {
  magazine: magazineEntries,
  'magazine-text': textEntries,
  'magazine-shares': shareEntries,
};

async function open(browser, fixtures, surface) {
  const context = await browser.newContext({ baseURL: BASE_URL, viewport: VIEWPORT });
  const page = await context.newPage();
  const fontRequests = [];
  page.on('request', (request) => {
    const { pathname } = new URL(request.url());
    if (pathname.startsWith('/fonts/') || /fonts\.(googleapis|gstatic)\.com/.test(request.url())) {
      fontRequests.push(request.url());
    }
  });
  await fixtures.presetLocalStorage(page, {
    'sfr.textSize': '100',
    'sfr.layout': surface === 'article' ? 'pane' : 'magazine',
  });
  if (surface === 'article') {
    await fixtures.stubArticle(page, ARTICLE_BODY);
    await page.route(
      (url) => url.pathname === '/api/entries/1/social/viewer',
      (route) =>
        route.fulfill({
          json: {
            isLiked: false,
            isReposted: false,
            canRepost: true,
            canReply: true,
            canQuote: true,
          },
        }),
    );
    await page.goto('/?subscription=1');
    await page.locator('app-entry-list').getByText(fixtures.POST.title, { exact: true }).click();
    await page.locator('app-entry-comments li.comment').nth(2).waitFor();
    await page.locator('app-social-counts-row button.reply', { hasText: 'Add comment' }).waitFor();
  } else {
    const tag = fixtures.makeTag(7, 'Politics', { icon: 'label' });
    const entries = ENTRIES[surface](fixtures);
    await fixtures.stubFixtureImages(page);
    await fixtures.stubMagazine(page, entries, { tags: [tag], unreadCount: entries.length });
    await page.goto('/');
    await page.locator('app-entry-pills a.pill').first().waitFor();
  }
  await page.evaluate(() => document.fonts.ready);
  return { page, fontRequests };
}

async function measureControl(page, control) {
  return page.evaluate((spec) => {
    const hosts = [...document.querySelectorAll(spec.control)].filter(
      (host) => !spec.label || host.querySelector(spec.text)?.textContent.trim() === spec.label,
    );
    const host = hosts[0];
    if (!host) return null;
    const text = host.querySelector(spec.text);
    const icon = host.querySelector(spec.icon);
    const marker = document.createElement('span');
    marker.style.cssText = 'display: inline-block; width: 0; height: 0;';
    text.prepend(marker);
    const baseline = marker.getBoundingClientRect().bottom;
    marker.remove();
    const style = getComputedStyle(text);
    const context = document.createElement('canvas').getContext('2d');
    context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
    const xHeight = context.measureText('x').actualBoundingBoxAscent;
    const iconBox = icon.getBoundingClientRect();
    return {
      offset: baseline - xHeight / 2 - (iconBox.top + iconBox.height / 2),
      family: style.fontFamily,
    };
  }, control);
}

async function lineXHeights(page) {
  return page.evaluate(
    (sizes) =>
      Object.fromEntries(
        sizes.map((size) => {
          const line = document.createElement('p');
          line.style.fontSize = `${size}px`;
          line.textContent = 'x';
          document.body.append(line);
          const style = getComputedStyle(line);
          const context = document.createElement('canvas').getContext('2d');
          context.font = `${style.fontWeight} ${size}px ${style.fontFamily}`;
          line.remove();
          return [size, context.measureText('x').actualBoundingBoxAscent];
        }),
      ),
    LINE_SIZES,
  );
}

async function readStyles(page, selectors) {
  return page.evaluate(
    ({ selectors, properties }) =>
      selectors.flatMap((selector) =>
        [...document.querySelectorAll(selector)].map((element, index) => {
          const block = element.closest(
            'app-entry-hero, app-entry-thread, app-entry-link, app-entry-media, app-entry-quoting, ' +
              'app-entry-wide, app-entry-quote, app-entry-split, app-entry-kicker, app-entry-thumb, ' +
              'app-entry-compact, app-source-group, app-entry-row',
          );
          const style = getComputedStyle(element);
          const where = block ? ` in ${block.tagName.toLowerCase()}` : '';
          const kind = element.classList.contains('post') ? '.post' : '';
          return {
            key: `${selector}${kind}${where} #${index}`,
            style: Object.fromEntries(
              properties.map((name) => [name, style.getPropertyValue(name)]),
            ),
          };
        }),
      ),
    { selectors, properties: STYLE_PROPERTIES },
  );
}

async function setClassic(page, on) {
  await page.evaluate((classic) => {
    if (classic) document.documentElement.setAttribute('data-theme-name', 'classic');
    else document.documentElement.removeAttribute('data-theme-name');
  }, on);
  if (!on) return;
  try {
    await page.waitForFunction((face) => document.fonts.check(face), CLASSIC_FACE, {
      timeout: FONT_TIMEOUT,
    });
  } catch {
    throw new Error(`document.fonts.check("${CLASSIC_FACE}") never became true under classic`);
  }
  await page.evaluate(() => document.fonts.ready);
}

const format = (value) => (value >= 0 ? '+' : '') + value.toFixed(2);

async function main() {
  const { values } = parseArgs({
    options: {
      browser: { type: 'string', default: 'chromium' },
      styles: { type: 'string' },
      'graphite-only': { type: 'boolean', default: false },
    },
  });
  const browserType = BROWSERS[values.browser];
  if (!browserType) throw new Error(`--browser must be chromium or webkit, not ${values.browser}`);
  const fixtures = await loadFixtures();
  const browser = await browserType.launch();
  const rows = [];
  const lines = {};
  const styles = [];
  const requests = {};
  try {
    for (const surface of ['magazine', 'magazine-text', 'magazine-shares', 'article']) {
      const { page, fontRequests } = await open(browser, fixtures, surface);
      styles.push(...(await readStyles(page, STYLED[surface])));
      const graphite = {};
      for (const control of CONTROLS.filter((candidate) => candidate.surface === surface)) {
        graphite[control.name] = await measureControl(page, control);
      }
      lines.graphite ??= await lineXHeights(page);
      requests[`${surface}, graphite`] = [...fontRequests];
      if (values['graphite-only']) {
        await page.context().close();
        continue;
      }
      await setClassic(page, true);
      for (const control of CONTROLS.filter((candidate) => candidate.surface === surface)) {
        const classic = await measureControl(page, control);
        const before = graphite[control.name];
        if (!before || !classic) throw new Error(`${control.name}: no control on the ${surface}`);
        rows.push({ name: control.name, graphite: before, classic });
      }
      lines.classic ??= await lineXHeights(page);
      requests[`${surface}, classic`] = fontRequests.slice(requests[`${surface}, graphite`].length);
      await setClassic(page, false);
      await page.context().close();
    }
  } finally {
    await browser.close();
  }

  if (values.styles) {
    await mkdir(dirname(values.styles), { recursive: true });
    await writeFile(values.styles, JSON.stringify(styles, null, 2));
    console.log(`graphite styles of ${styles.length} elements written to ${values.styles}`);
  }
  if (values['graphite-only']) return;

  console.log(`${values.browser}: x-height centre minus icon centre, px, at 100% text size`);
  console.log('control       graphite  classic  difference');
  let failed = false;
  for (const { name, graphite, classic } of rows) {
    const difference = classic.offset - graphite.offset;
    const verdict = Math.abs(difference) > OFFSET_TOLERANCE ? '  FAIL' : '';
    failed ||= verdict !== '';
    console.log(
      `${name.padEnd(12)}  ${format(graphite.offset).padStart(8)}  ${format(classic.offset).padStart(7)}  ${format(difference).padStart(10)}${verdict}`,
    );
  }
  console.log(`x-height, px, against ${X_HEIGHT_TARGET}em (graphite for information only)`);
  for (const size of LINE_SIZES) {
    const target = X_HEIGHT_TARGET * size;
    const difference = lines.classic[size] - target;
    const judged = size === JUDGED_LINE;
    const verdict = judged && Math.abs(difference) > X_HEIGHT_TOLERANCE ? '  FAIL' : '';
    failed ||= verdict !== '';
    console.log(
      `${String(size).padStart(2)}px line: classic ${lines.classic[size].toFixed(2)}, target ${target.toFixed(2)}, difference ${format(difference)}, graphite ${lines.graphite[size].toFixed(2)}${judged ? ' (judged)' : ''}${verdict}`,
    );
  }
  console.log(`classic family: ${rows[0].classic.family}`);
  for (const [phase, urls] of Object.entries(requests)) {
    console.log(`font requests (${phase}): ${urls.length ? urls.join(', ') : 'none'}`);
  }
  if (failed) {
    console.error(
      `FAIL: an offset difference exceeds ${OFFSET_TOLERANCE}px, or a ${JUDGED_LINE}px line's x-height is more than ${X_HEIGHT_TOLERANCE}px from ${X_HEIGHT_TARGET}em`,
    );
    process.exitCode = 1;
  }
}

await main();
