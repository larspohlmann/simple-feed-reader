#!/usr/bin/env node
// Measures where each Material Symbol's ink sits in its em box and writes the vertical shifts.
// Usage: npm run icons:measure (run npm run icons:glyph-names first after a font update)
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { importBundledTs } from '../lib/bundle-ts.mjs';

const FRONTEND = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const PACKAGE = join(FRONTEND, 'node_modules', 'material-symbols');
const OUTPUT = join(FRONTEND, 'src', 'app', 'shared', 'icon', 'icon-ink-shifts.ts');
const ORIGIN = 'http://icons.invalid';
const SIZE = 200;
const COLUMNS = 16;
const PRECISION = 1000;
const REPORTED_LARGEST = 5;
const ASSETS = {
  '/outlined.css': { file: 'outlined.css', contentType: 'text/css' },
  '/material-symbols-outlined.woff2': {
    file: 'material-symbols-outlined.woff2',
    contentType: 'font/woff2',
  },
};

const PAGE = `<!doctype html>
<html><head><meta charset="utf-8"><link rel="stylesheet" href="/outlined.css">
<style>
  body { margin: 0; background: #fff; color: #000; }
  .host { position: absolute; display: inline-flex; align-items: center;
    vertical-align: middle; font-size: ${SIZE}px; }
  .host span { display: inline-flex; align-items: center; justify-content: center;
    width: 1em; height: 1em; line-height: 1; overflow: hidden; user-select: none; }
</style></head><body></body></html>`;

function loadIconNames() {
  return importBundledTs({
    contents: "export * from './src/testing/icon-names';",
    cacheName: 'measure-ink-shifts',
  });
}

function buildFills(variations) {
  return [
    { name: 'outlined', variation: variations.outlined },
    { name: 'filled', variation: variations.filled },
  ];
}

function requireMatchingGlyphNames(iconNames) {
  const listed = iconNames.glyphNamesFontVersion(FRONTEND);
  const installed = iconNames.installedFontVersion(FRONTEND);
  if (listed !== installed) {
    throw new Error(
      `glyph-names.txt lists material-symbols ${listed || 'unknown'}, but ${installed} is installed. Run \`npm run icons:glyph-names\` first.`,
    );
  }
}

async function serveAssets(page) {
  await page.route(`${ORIGIN}/**`, (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/') {
      return route.fulfill({ contentType: 'text/html', body: PAGE });
    }
    const asset = ASSETS[path];
    if (!asset) {
      return route.fulfill({ status: 404, body: '' });
    }
    return route.fulfill({
      contentType: asset.contentType,
      body: readFileSync(join(PACKAGE, asset.file)),
    });
  });
}

async function renderGrid(page, names, variation) {
  return page.evaluate(
    async ({ names, variation, size, columns }) => {
      document.body.replaceChildren();
      for (const [index, name] of names.entries()) {
        const host = document.createElement('div');
        host.className = 'host';
        host.style.left = `${(index % columns) * size}px`;
        host.style.top = `${Math.floor(index / columns) * size}px`;
        const glyph = document.createElement('span');
        glyph.className = 'material-symbols-outlined';
        glyph.setAttribute('style', `font-size: inherit; font-variation-settings: ${variation}`);
        glyph.textContent = name;
        host.append(glyph);
        document.body.append(host);
      }
      await document.fonts.load(`${size}px "Material Symbols Outlined"`);
      await document.fonts.ready;
      return [...document.querySelectorAll('.host span')]
        .filter((glyph) => glyph.scrollWidth > glyph.clientWidth)
        .map((glyph) => glyph.textContent);
    },
    { names, variation, size: SIZE, columns: COLUMNS },
  );
}

function buildGridSize(names) {
  return { width: COLUMNS * SIZE, height: Math.ceil(names.length / COLUMNS) * SIZE };
}

async function readInkCentroids(page, names) {
  const grid = buildGridSize(names);
  const screenshot = await page.screenshot({ clip: { x: 0, y: 0, ...grid } });
  return page.evaluate(
    async ({ png, count, size, columns, width, height }) => {
      const image = new Image();
      image.src = `data:image/png;base64,${png}`;
      await image.decode();
      if (image.width !== width || image.height !== height) {
        throw new Error(`The screenshot is ${image.width}x${image.height}, not ${width}x${height}`);
      }
      const canvas = document.createElement('canvas');
      canvas.width = image.width;
      canvas.height = image.height;
      const context = canvas.getContext('2d', { willReadFrequently: true });
      context.drawImage(image, 0, 0);
      const pixels = context.getImageData(0, 0, image.width, image.height).data;
      const centroids = [];
      for (let index = 0; index < count; index++) {
        const left = (index % columns) * size;
        const top = Math.floor(index / columns) * size;
        let totalInk = 0;
        let weightedRow = 0;
        for (let row = 0; row < size; row++) {
          for (let column = 0; column < size; column++) {
            const ink = 255 - pixels[((top + row) * image.width + left + column) * 4];
            totalInk += ink;
            weightedRow += (row + 0.5) * ink;
          }
        }
        centroids.push(totalInk === 0 ? null : weightedRow / totalInk);
      }
      return centroids;
    },
    {
      png: screenshot.toString('base64'),
      count: names.length,
      size: SIZE,
      columns: COLUMNS,
      ...grid,
    },
  );
}

function toShift(centroid) {
  if (centroid === null) {
    return 0;
  }
  const shift = Math.round((-(centroid - SIZE / 2) / SIZE) * PRECISION) / PRECISION;
  return shift === 0 ? 0 : shift;
}

async function measureShifts(page, names, variation) {
  const fallbacks = await renderGrid(page, names, variation);
  if (fallbacks.length > 0) {
    throw new Error(`The icon font did not render: ${fallbacks.join(', ')}`);
  }
  return (await readInkCentroids(page, names)).map(toShift);
}

function formatTable(version, names, shiftsByFill) {
  const rows = names.map(
    (name, index) =>
      `  ${JSON.stringify(name)}: [${shiftsByFill.map((shifts) => shifts[index]).join(', ')}],`,
  );
  return [
    '// Generated by scripts/icons/measure-ink-shifts.mjs — do not edit.',
    `export const ICON_INK_SHIFTS_FONT_VERSION = ${JSON.stringify(version)};`,
    '',
    'export const ICON_INK_SHIFTS: Readonly<',
    '  Record<string, readonly [outlined: number, filled: number]>',
    '> = {',
    ...rows,
    '};',
    '',
  ].join('\n');
}

function reportLargest(names, fills, shiftsByFill) {
  const measured = names.flatMap((name, index) =>
    fills.map((fill, fillIndex) => ({
      name,
      fill: fill.name,
      shift: shiftsByFill[fillIndex][index],
    })),
  );
  measured.sort((left, right) => Math.abs(right.shift) - Math.abs(left.shift));
  console.log(`Measured ${names.length} glyphs, ${fills.map((fill) => fill.name).join(' and ')}.`);
  for (const { name, fill, shift } of measured.slice(0, REPORTED_LARGEST)) {
    console.log(`  ${name} (${fill}): ${shift}em`);
  }
}

async function main() {
  const iconNames = await loadIconNames();
  requireMatchingGlyphNames(iconNames);
  const fills = buildFills(iconNames.ICON_VARIATIONS);
  const names = iconNames.scanIconNames(FRONTEND);
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ viewport: buildGridSize(names), deviceScaleFactor: 1 });
    await serveAssets(page);
    await page.goto(`${ORIGIN}/`);
    const shiftsByFill = [];
    for (const fill of fills) {
      shiftsByFill.push(await measureShifts(page, names, fill.variation));
    }
    writeFileSync(
      OUTPUT,
      formatTable(iconNames.installedFontVersion(FRONTEND), names, shiftsByFill),
    );
    execFileSync(join(FRONTEND, 'node_modules', '.bin', 'prettier'), ['--write', OUTPUT]);
    reportLargest(names, fills, shiftsByFill);
  } finally {
    await browser.close();
  }
}

await main();
