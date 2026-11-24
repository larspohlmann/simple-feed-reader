#!/usr/bin/env node
// Measures where classic's text sits against its icons, compared with graphite's.
// Usage: node scripts/fonts/measure-metrics.mjs --browser chromium|webkit
import { chromium, webkit } from '@playwright/test';
import { build } from 'esbuild';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';

const BASE_URL = 'http://localhost:4200';
const VIEWPORT = { width: 1280, height: 900 };
const OFFSET_TOLERANCE = 0.25;
const X_HEIGHT_TOLERANCE = 0.2;
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
    const entries = magazineEntries(fixtures);
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
    },
  });
  const browserType = BROWSERS[values.browser];
  if (!browserType) throw new Error(`--browser must be chromium or webkit, not ${values.browser}`);
  const fixtures = await loadFixtures();
  const browser = await browserType.launch();
  const rows = [];
  const lines = {};
  const requests = {};
  try {
    for (const surface of ['magazine', 'article']) {
      const { page, fontRequests } = await open(browser, fixtures, surface);
      const graphite = {};
      for (const control of CONTROLS.filter((candidate) => candidate.surface === surface)) {
        graphite[control.name] = await measureControl(page, control);
      }
      lines.graphite ??= await lineXHeights(page);
      requests[`${surface}, graphite`] = [...fontRequests];
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
  console.log(
    `x-height, px, classic against graphite (${JUDGED_LINE}px judged, the rest for information)`,
  );
  for (const size of LINE_SIZES) {
    const difference = lines.classic[size] - lines.graphite[size];
    const judged = size === JUDGED_LINE;
    const verdict = judged && Math.abs(difference) > X_HEIGHT_TOLERANCE ? '  FAIL' : '';
    failed ||= verdict !== '';
    console.log(
      `${String(size).padStart(2)}px line: graphite ${lines.graphite[size].toFixed(2)}, classic ${lines.classic[size].toFixed(2)}, difference ${format(difference)}${judged ? ' (judged)' : ''}${verdict}`,
    );
  }
  console.log(`classic family: ${rows[0].classic.family}`);
  for (const [phase, urls] of Object.entries(requests)) {
    console.log(`font requests (${phase}): ${urls.length ? urls.join(', ') : 'none'}`);
  }
  if (failed) {
    console.error(
      `FAIL: an offset difference exceeds ${OFFSET_TOLERANCE}px, or a ${JUDGED_LINE}px line's x-height is more than ${X_HEIGHT_TOLERANCE}px from graphite's`,
    );
    process.exitCode = 1;
  }
}

await main();
