#!/usr/bin/env node
// Renders docs/homepage/share.png, the 1200x630 preview card that social
// networks show for the homepage (og:image / twitter:image). Rerun after
// retaking docs/screenshots/magazine-light.webp:
//
//   node scripts/homepage-share-image.mjs
//
// Uses the Playwright that frontend/npm ci installs, or a global one.

import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const homepage = path.join(repoRoot, 'docs', 'homepage');
const output = path.join(homepage, 'share.png');

function loadPlaywright() {
  for (const from of [path.join(repoRoot, 'frontend', 'package.json'), import.meta.url]) {
    try {
      return createRequire(from)('playwright');
    } catch (error) {
      if (error.code !== 'MODULE_NOT_FOUND') throw error;
    }
  }
  throw new Error('playwright not found: run `npm ci` in frontend/ or install it globally');
}

const screenshot = fs.readFileSync(path.join(homepage, 'screenshots', 'magazine-light.webp')).toString('base64');
const glyph = 'M200-120q-33 0-56.5-23.5T120-200q0-33 23.5-56.5T200-280q33 0 56.5 23.5T280-200q0 33-23.5 56.5T200-120Zm480 0q0-117-44-218.5T516-516q-76-76-177.5-120T120-680v-120q142 0 265 53t216 146q93 93 146 216t53 265H680Zm-240 0q0-67-25-124.5T346-346q-44-44-101.5-69T120-440v-120q92 0 171.5 34.5T431-431q60 60 94.5 139.5T560-120H440Z';

const card = `<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<style>
  html,body{margin:0}
  body{width:1200px;height:630px;overflow:hidden;background:#f5f5f4;color:#2a2a2a;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;position:relative}
  .text{position:absolute;left:72px;top:72px;width:520px}
  .brand{display:flex;align-items:center;gap:12px;font-size:26px;font-weight:700;letter-spacing:-.01em}
  .brand svg{width:34px;height:34px;color:#3f8676}
  h1{margin:40px 0 0;font-size:54px;line-height:1.08;font-weight:700;letter-spacing:-.025em}
  h1 em{font-style:normal;color:#3f8676}
  p{margin:28px 0 0;font-size:22px;line-height:1.45;color:#5f5f5c}
  .facts{position:absolute;left:72px;bottom:64px;font-size:17px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#3f8676}
  .shot{position:absolute;left:660px;top:84px;width:860px;border-radius:14px;overflow:hidden;
    border:1px solid #d4d4d1;box-shadow:0 30px 80px rgba(0,0,0,.18);background:#fff}
  .shot img{display:block;width:100%;height:auto}
</style></head>
<body>
  <div class="text">
    <div class="brand"><svg viewBox="0 -960 960 960"><path fill="currentColor" d="${glyph}"/></svg><span>simple feed reader</span></div>
    <h1>An RSS reader that lays feeds out <em>like a magazine.</em></h1>
    <p>Self-hosted, for one or several users. Articles, podcasts, YouTube, Bluesky and Mastodon in one reading column.</p>
  </div>
  <div class="facts">Open source · MIT · Docker</div>
  <div class="shot"><img src="data:image/webp;base64,${screenshot}" alt=""></div>
</body></html>`;

const { chromium } = loadPlaywright();
const browser = await chromium.launch();
try {
  const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
  await page.setContent(card, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: output, type: 'png' });
} finally {
  await browser.close();
}
console.log(`==> wrote ${path.relative(repoRoot, output)} (${Math.round(fs.statSync(output).size / 1024)} KB)`);
