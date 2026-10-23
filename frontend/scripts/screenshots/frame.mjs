// Wraps a 2x phone screenshot in a phone frame: a status bar strip above and a home-indicator
// strip below, both in the page's own background colour, a dark bezel and a dynamic island,
// transparent outside the device.
//
//   node frame.mjs <in.png|webp> <out.png>
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';

const [input, output] = process.argv.slice(2);
if (!input || !output) throw new Error('Usage: node frame.mjs <in.png|webp> <out.png>');

const mime = extname(input) === '.webp' ? 'image/webp' : 'image/png';
const dataUri = `data:${mime};base64,${readFileSync(resolve(input)).toString('base64')}`;

const STATUS_ICONS = `
  <svg width="38" height="24" viewBox="0 0 19 12"><g fill="var(--fg)"><rect x="0" y="8" width="3" height="4" rx=".8"/><rect x="5" y="5.5" width="3" height="6.5" rx=".8"/><rect x="10" y="3" width="3" height="9" rx=".8"/><rect x="15" y="0" width="3" height="12" rx=".8"/></g></svg>
  <svg width="34" height="24" viewBox="0 0 17 12"><path fill="var(--fg)" d="M8.5 11.6 6.3 9.3a3.1 3.1 0 0 1 4.4 0Zm3.7-3.8a5.3 5.3 0 0 0-7.4 0L3.3 6.3a7.4 7.4 0 0 1 10.4 0ZM15.3 4.8a10 10 0 0 0-13.6 0L.2 3.3a12.2 12.2 0 0 1 16.6 0Z"/></svg>
  <svg width="54" height="26" viewBox="0 0 27 13"><rect x=".5" y=".5" width="23" height="12" rx="3.5" fill="none" stroke="var(--fg)" stroke-opacity=".4"/><rect x="2" y="2" width="20" height="9" rx="2" fill="var(--fg)"/><path d="M25 4.5v4a2 2 0 0 0 0-4Z" fill="var(--fg)" fill-opacity=".4"/></svg>`;

const html = `<!doctype html><html><head><style>
  html, body { margin: 0; background: transparent; }
  body { padding: 40px; display: inline-block; }
  .phone { position: relative; border-radius: 118px; background: #101012; padding: 24px;
           box-shadow: 0 30px 60px rgba(0,0,0,.35), inset 0 0 0 3px #3a3a3e, inset 0 0 0 5px #0b0b0c; }
  .screen { position: relative; border-radius: 94px; overflow: hidden; background: var(--bg); }
  .status { height: 100px; display: flex; align-items: flex-end; justify-content: space-between;
            padding: 0 54px 16px 64px; color: var(--fg); letter-spacing: -.5px;
            font: 600 32px/1 -apple-system, "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif; }
  .status .right { display: flex; align-items: center; gap: 10px; }
  .island { position: absolute; top: 24px; left: 50%; transform: translateX(-50%);
            width: 250px; height: 74px; border-radius: 40px; background: #000; }
  img { display: block; width: 780px; }
  .home { height: 68px; display: flex; align-items: center; justify-content: center; }
  .home i { width: 278px; height: 10px; border-radius: 5px; background: var(--fg); opacity: .9; }
  svg { display: block; }
</style></head><body>
<div class="phone"><div class="screen">
  <div class="status"><span>9:41</span><span class="right">${STATUS_ICONS}</span></div>
  <div class="island"></div>
  <img id="shot" src="${dataUri}">
  <div class="home"><i></i></div>
</div></div>
<canvas id="sampler" hidden></canvas>
</body></html>`;

const browser = await chromium.launch();
const page = await browser.newPage({ deviceScaleFactor: 1 });
await page.setContent(html);
await page.evaluate(async () => {
  const image = document.getElementById('shot');
  await image.decode();
  const canvas = document.getElementById('sampler');
  canvas.width = image.naturalWidth;
  canvas.height = image.naturalHeight;
  const context = canvas.getContext('2d');
  context.drawImage(image, 0, 0);
  const pixel = (x, y) => Array.from(context.getImageData(x, y, 1, 1).data.slice(0, 3));
  const samples = [
    pixel(2, 2),
    pixel(image.naturalWidth - 3, 2),
    pixel(Math.floor(image.naturalWidth / 2), 1),
  ];
  const background = samples
    .reduce((sum, sample) => sum.map((channel, index) => channel + sample[index]), [0, 0, 0])
    .map((channel) => Math.round(channel / samples.length));
  const luminance =
    (0.2126 * background[0] + 0.7152 * background[1] + 0.0722 * background[2]) / 255;
  document.documentElement.style.setProperty('--bg', `rgb(${background.join(',')})`);
  document.documentElement.style.setProperty('--fg', luminance > 0.5 ? '#111' : '#fff');
});
const box = await page.locator('.phone').boundingBox();
await page.setViewportSize({
  width: Math.ceil(box.width + 80),
  height: Math.ceil(box.height + 80),
});
await page.screenshot({ path: output, omitBackground: true, fullPage: true });
await browser.close();
