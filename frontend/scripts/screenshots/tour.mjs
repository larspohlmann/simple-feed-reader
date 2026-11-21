// Records the homepage phone tour against the dev stack: seven scenes on a 390×760 phone at 2x,
// once per theme, encoded to MP4 with a poster; the scene start times go into the homepage's
// scene list. Run from `frontend/`:
//
//   node --env-file=.env.screenshots scripts/screenshots/tour.mjs [light|dark ...]   default: both
//
// Needs the demo account in .env.screenshots, ffmpeg and cwebp on the PATH, and nothing else:
// the podcasts it plays and the news feeds its Trending scene needs are borrowed through
// podcast-setup.mjs and trending-setup.mjs and returned afterwards. It refuses to record while
// Trending is below its floor; a run right after borrowing waits for the stories to be built.
// The video scene needs a connected network with a video post among its followed accounts
// (social-setup.mjs).
// TOUR_OUT overrides the output folder (default docs/homepage); the page there, if any, gets the
// start times.
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  articles,
  BASE,
  isCalmEntry,
  leaveOutDistressingNews,
  PHONE,
  seedSettings,
  settle,
  signIn,
  stubAiryStyle,
} from './lib.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(process.env.TOUR_OUT ?? join(HERE, '..', '..', '..', 'docs', 'homepage'));
const RAW = join(tmpdir(), 'sfr-tour');
const PODCAST_SETUP = join(HERE, 'podcast-setup.mjs');
const TRENDING_SETUP = join(HERE, 'trending-setup.mjs');
const TRENDING_WAIT_MINUTES = 20;
// Third in the magazine: below the fold at first, in view after the swipe that folds the bars.
const VIDEO_POSITION = 2;
const VIDEO_PAGES_SEARCHED = 5;
const VIEWPORT = PHONE.viewport;
const TOUR_PHONE = { ...PHONE, reducedMotion: 'no-preference' };
const VIDEO_SIZE = {
  width: VIEWPORT.width * PHONE.deviceScaleFactor,
  height: VIEWPORT.height * PHONE.deviceScaleFactor,
};
const LEAD_IN_SECONDS = 0.4;
const READABLE_LENGTH = 1200;
const READABLE_WANTED = 4;
const CARD_TITLE = '.title, h2, h3';

// Drawn into the page so the recording shows where the finger is: a ripple per tap and a dot
// that follows a drag. An init script, so it survives navigations.
const installTouchOverlay = () => {
  const install = () => {
    const layer = document.createElement('div');
    layer.style.cssText = 'position:fixed;inset:0;z-index:2147483647;pointer-events:none;';
    const dot =
      'width:40px;height:40px;margin:-20px 0 0 -20px;border-radius:50%;' +
      'background:rgba(20,20,20,.28);box-shadow:0 0 0 2.5px rgba(255,255,255,.9),0 2px 10px rgba(0,0,0,.35);';
    const finger = document.createElement('div');
    finger.style.cssText = `position:absolute;${dot}opacity:0;transition:opacity .15s;`;
    layer.append(finger);
    document.body.append(layer);
    window.__tourFinger = (x, y, down) => {
      finger.style.left = `${x}px`;
      finger.style.top = `${y}px`;
      finger.style.opacity = down ? '1' : '0';
    };
    window.__tourRipple = (x, y) => {
      const ripple = document.createElement('div');
      ripple.style.cssText =
        `position:absolute;left:${x}px;top:${y}px;${dot}` +
        'transform:scale(.6);opacity:1;transition:transform .45s ease-out,opacity .45s ease-out;';
      layer.append(ripple);
      requestAnimationFrame(() => {
        ripple.style.transform = 'scale(1.6)';
        ripple.style.opacity = '0';
      });
      setTimeout(() => ripple.remove(), 550);
    };
  };
  if (document.body) install();
  else document.addEventListener('DOMContentLoaded', install);
};

const easeInOut = (t) => (t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2);

// Clear of the edges, where a tap on a half-cut control is not read as a press.
async function comfortablyOnScreen(locator) {
  const box = await locator.boundingBox();
  if (!box) return false;
  const x = box.x + box.width / 2;
  const y = box.y + box.height / 2;
  return x >= 0 && x <= VIEWPORT.width && y >= 100 && y <= VIEWPORT.height - 70;
}

// The first point of the element's on-screen part that a tap there would actually hit, the
// centre preferred: header buttons scroll away, the closed drawer sits beside the viewport and
// the open drawer covers most of its own backdrop, all while staying in the DOM.
function hittablePoint(element, viewport) {
  const box = element.getBoundingClientRect();
  const left = Math.max(box.left, 0);
  const right = Math.min(box.right, viewport.width);
  const top = Math.max(box.top, 0);
  const bottom = Math.min(box.bottom, viewport.height);
  if (left >= right || top >= bottom) return null;
  const fractions = Array.from({ length: 10 }, (_, index) => 0.05 + index * 0.1);
  const candidates = [[0.5, 0.5], ...fractions.flatMap((fx) => fractions.map((fy) => [fx, fy]))];
  for (const [fx, fy] of candidates) {
    const x = left + (right - left) * fx;
    const y = top + (bottom - top) * fy;
    if (element.contains(document.elementFromPoint(x, y))) return { x, y };
  }
  return null;
}

class Finger {
  constructor(page, cdp) {
    this.page = page;
    this.cdp = cdp;
  }

  async hold(milliseconds) {
    await this.page.waitForTimeout(milliseconds);
  }

  async tapAt(x, y) {
    await this.page.evaluate(([px, py]) => window.__tourRipple(px, py), [x, y]);
    await this.hold(80);
    await this.page.touchscreen.tap(x, y);
  }

  async tap(locator) {
    for (const match of await locator.all()) {
      const point = await match.evaluate(hittablePoint, VIEWPORT);
      if (point) return this.tapAt(point.x, point.y);
    }
    throw new Error(`Nothing a tap could hit matches ${locator}`);
  }

  async drag(from, to, steps) {
    await this.show(from, true);
    await this.cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [from] });
    for (let index = 1; index <= steps; index++) {
      const progress = easeInOut(index / steps);
      const point = {
        x: from.x + (to.x - from.x) * progress,
        y: from.y + (to.y - from.y) * progress,
      };
      await this.cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [point] });
      await this.show(point, true);
      await this.hold(16);
    }
    await this.cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await this.show(to, false);
  }

  swipeUp({ x = 200, distance = 420, steps = 30 } = {}) {
    return this.drag({ x, y: 640 }, { x, y: 640 - distance }, steps);
  }

  show(point, down) {
    return this.page.evaluate(
      ([x, y, isDown]) => window.__tourFinger(x, y, isDown),
      [point.x, point.y, down],
    );
  }
}

const cardTitle = (card) => card.locator(CARD_TITLE).first();
const drawer = (page) => page.getByRole('navigation', { name: 'Feeds' });
const sidebarButton = (page) => page.getByRole('button', { name: /^(Toggle|Show) sidebar$/ });
const swipeDrawer = (finger, distance = 400) => finger.swipeUp({ x: 150, distance, steps: 26 });

function waitForDrawer(page, open) {
  return page.waitForFunction((wantOpen) => {
    const box = document.querySelector('aside.sidebar')?.getBoundingClientRect();
    if (!box) return false;
    return wantOpen ? box.left >= 0 : box.right <= 0;
  }, open);
}

// A touch that lands while the drawer still glides is read as a scroll stop, not a press.
function waitForDrawerToSettle(page) {
  return page.evaluate(
    () =>
      new Promise((resolve) => {
        const scroller = document.querySelector('.sidebar-body');
        let last = Number.NaN;
        let stillFrames = 0;
        const check = () => {
          stillFrames = scroller.scrollTop === last ? stillFrames + 1 : 0;
          last = scroller.scrollTop;
          if (stillFrames >= 6) resolve();
          else requestAnimationFrame(check);
        };
        requestAnimationFrame(check);
      }),
  );
}

async function bringIntoReachInDrawer(page, finger, control) {
  for (let swipe = 0; swipe < 3 && !(await comfortablyOnScreen(control)); swipe++) {
    await swipeDrawer(finger);
    await waitForDrawerToSettle(page);
  }
  if (!(await comfortablyOnScreen(control))) await control.scrollIntoViewIfNeeded();
  await waitForDrawerToSettle(page);
}

// An episode opens its show notes, not an article; Polygon's extract opens with a share toast.
async function isArticleCandidate(card) {
  if ((await card.getByRole('button', { name: 'Play', exact: true }).count()) > 0) return false;
  return (await card.locator('app-entry-source').filter({ hasText: 'Polygon' }).count()) === 0;
}

// Opens the first cards once off camera and returns the titles whose reader renders a body:
// the recorded tap then lands on an article that loads at once, with its text, not a toast.
async function warmReadableTitles(page) {
  await page.goto(`${BASE}/`);
  await articles(page).first().waitFor();
  const readable = [];
  for (let index = 0; index < 10 && readable.length < READABLE_WANTED; index++) {
    const card = articles(page).nth(index);
    const title = (await cardTitle(card).textContent())?.trim();
    if (!title || !(await isArticleCandidate(card))) continue;
    await cardTitle(card).click();
    const length = await readerBodyLength(page, title, 20000);
    const isReadable = length >= READABLE_LENGTH;
    if (isReadable) readable.push(title);
    console.log(`  ${isReadable ? 'readable' : 'skipped '} (${length} chars) ${title}`);
    await page.goBack();
    await articles(page).first().waitFor();
  }
  if (readable.length === 0) throw new Error('None of the first cards opens in the reader');
  return readable;
}

// The article is briefly not busy before the tapped entry starts loading, so the heading must
// name the tapped entry first; measuring earlier read the previous state, the title alone.
// A fallback, an error or a timeout reads as 0.
function readerBodyLength(page, title, timeout) {
  return page
    .waitForFunction(
      ([expected, minimum]) => {
        const reader = document.querySelector('app-reader-view');
        const heading = reader?.querySelector('h1.title')?.textContent?.trim() ?? '';
        if (!heading.startsWith(expected.slice(0, 40))) return false;
        if (reader.querySelector('.reader-fallback, .reader-fallback-quiet, .body-error'))
          return { length: 0 };
        const length = reader.querySelector('app-article-content')?.innerText.length ?? 0;
        return length >= minimum && { length };
      },
      [title, READABLE_LENGTH],
      { timeout },
    )
    .then(
      (handle) => handle.jsonValue().then(({ length }) => length),
      () => 0,
    );
}

async function onScreenReadableTitle(page, readable) {
  const titles = articles(page).locator(CARD_TITLE);
  const count = await titles.count();
  for (let index = 0; index < count; index++) {
    const title = titles.nth(index);
    const box = await title.boundingBox();
    if (!box || box.y < 110 || box.y + box.height > 700) continue;
    if (readable.includes((await title.textContent())?.trim())) return title;
  }
  return null;
}

async function scrollToReadableTitle(page, finger, readable) {
  let title = await onScreenReadableTitle(page, readable);
  for (let attempt = 0; attempt < 4 && !title; attempt++) {
    await finger.swipeUp({ distance: 400 });
    await finger.hold(900);
    title = await onScreenReadableTitle(page, readable);
  }
  if (!title) throw new Error('No readable card reached the screen');
  return title;
}

async function backToList(page, finger) {
  await finger.tap(page.getByRole('button', { name: 'Back to list', exact: true }));
  await page.waitForURL((url) => !url.searchParams.has('entry'));
}

// A warmed article can still fall back on camera; then the next warmed card gets the tap.
async function openReadableArticle(page, finger, readable) {
  const untried = [...readable];
  for (let attempt = 0; attempt < 3; attempt++) {
    const title = await scrollToReadableTitle(page, finger, untried);
    const text = (await title.textContent()).trim();
    untried.splice(untried.indexOf(text), 1);
    await finger.tap(title);
    if ((await readerBodyLength(page, text, 8000)) >= READABLE_LENGTH) return;
    console.log(`  fell back on camera: ${text}`);
    await backToList(page, finger);
    await finger.hold(600);
  }
  throw new Error('No warmed article opened with its body');
}

// The swipe is slow on purpose: it is the moment the app bar, the tag strip and the list header
// fold into one title line, and the scene is there to show it.
async function magazineScene({ finger }) {
  await finger.hold(1700);
  await finger.swipeUp({ distance: 260, steps: 55 });
  await finger.hold(1500);
}

// The followed accounts' posts with a video of their own, which is the kind a list plays. Only
// accounts picked for the demo are searched: a home timeline is the account owner's reading.
async function findVideoPosts(page) {
  return page.evaluate(
    async ({ pages, networks }) => {
      const token = localStorage.getItem('sfr.jwt');
      const posts = [];
      for (const network of networks) {
        let cursor = null;
        for (let fetched = 0; fetched < pages; fetched++) {
          const query = new URLSearchParams({ view: 'all', network, feedKind: 'profile' });
          if (cursor) query.set('cursor', cursor);
          const response = await fetch(`/api/entries?${query}`, {
            headers: { Authorization: `Bearer ${token}` },
          });
          if (!response.ok) break;
          const { entries, nextCursor } = await response.json();
          // A post that also shares a link shows the link's card in a list, not its video.
          posts.push(
            ...entries.filter(
              (entry) =>
                entry.linkCards.length === 0 &&
                entry.media.some(
                  (medium) => medium.kind === 'video' && /\.(m3u8|mp4)/.test(medium.url),
                ),
            ),
          );
          if (posts.length > 0 || !nextCursor) break;
          cursor = nextCursor;
        }
      }
      return posts;
    },
    { pages: VIDEO_PAGES_SEARCHED, networks: ['bluesky', 'mastodon'] },
  );
}

const isAllItems = (address) =>
  address.pathname === '/api/entries' &&
  !['subscription', 'tag', 'network', 'q'].some((filter) => address.searchParams.has(filter));
// The magazine moves the first entry with a lead-worthy picture to the front. The video would be
// that entry and play from the first frame, so the list opens on an article's picture instead.
const LEAD_PICTURE_WIDTH = 1000;
const opensOnAPicture = (entry) =>
  entry.media.every((medium) => medium.kind !== 'video') &&
  entry.imageUrl !== null &&
  entry.imageWidth >= LEAD_PICTURE_WIDTH &&
  entry.imageWidth > entry.imageHeight;
// The magazine folds a run of one source into a group as tall as several cards, too far for the
// video to scroll into view: the list takes each source's newest entry first, then each one's next.
function oneSourceAfterAnother(entries) {
  const bySource = [...Map.groupBy(entries, (entry) => entry.subscriptionId).values()];
  const rounds = Math.max(0, ...bySource.map((own) => own.length));
  return Array.from({ length: rounds }, (_, round) =>
    bySource.flatMap((own) => own[round] ?? []),
  ).flat();
}
const withMixedSources = (entries, address) =>
  isAllItems(address) ? oneSourceAfterAnother(entries) : entries;
const placedInTheMagazine = (videoPost) => (entries, address) => {
  if (!isAllItems(address)) return entries;
  const others = oneSourceAfterAnother(entries.filter((entry) => entry.id !== videoPost.id));
  const opener = others.findIndex(opensOnAPicture);
  if (opener > 0) others.unshift(...others.splice(opener, 1));
  return [...others.slice(0, VIDEO_POSITION), videoPost, ...others.slice(VIDEO_POSITION)];
};

// The post scrolls into view and starts on its own, muted; the scene waits until it really plays.
const someListVideoPlays = () =>
  [...document.querySelectorAll('app-entry-list article video')].some(
    (video) => !video.paused && video.currentTime > 0,
  );
async function videoScene({ page, finger }) {
  const isPlaying = () => page.evaluate(someListVideoPlays);
  if (await isPlaying()) console.log('  the video leads the list and plays from the start');
  for (let swipe = 0; swipe < 6 && !(await isPlaying()); swipe++) {
    await finger.swipeUp({ distance: 240, steps: 30 });
    await page.waitForFunction(someListVideoPlays, null, { timeout: 1800 }).catch(() => {});
  }
  if (!(await isPlaying())) throw new Error('No video in the magazine started playing');
  await finger.hold(3600);
  await finger.swipeUp();
  await finger.hold(1100);
}

async function readerScene({ page, finger, readable }) {
  await openReadableArticle(page, finger, readable);
  await finger.hold(1800);
  await finger.swipeUp({ distance: 460, steps: 40 });
  await finger.hold(900);
  await finger.swipeUp({ distance: 460, steps: 40 });
  await finger.hold(1400);
  await backToList(page, finger);
  await finger.hold(1500);
}

const storyCards = (page) => page.locator('app-story-title');
async function trendingScene({ page, finger }) {
  await finger.tap(sidebarButton(page));
  await waitForDrawer(page, true);
  await finger.hold(900);
  await finger.tap(
    drawer(page)
      .getByRole('link', { name: /^Trending/ })
      .first(),
  );
  await page.waitForURL(/view=trending/, { timeout: 10000 });
  await waitForDrawer(page, false);
  await storyCards(page).first().waitFor();
  await finger.hold(1900);
  await finger.swipeUp({ distance: 300 });
  await finger.hold(1300);
  await finger.tap(storyCards(page).locator('a.story-link'));
  await page.locator('app-story-layer').waitFor();
  await finger.hold(1900);
  await finger.swipeUp();
  await finger.hold(1400);
  await finger.tap(page.locator('app-story-layer button.story-back'));
  await page.locator('app-story-layer').waitFor({ state: 'hidden' });
  await finger.hold(900);
}

async function sidebarScene({ page, finger }) {
  await finger.tap(sidebarButton(page));
  await waitForDrawer(page, true);
  await finger.hold(1300);
  await swipeDrawer(finger, 240);
  const podcasts = drawer(page)
    .getByRole('link', { name: /Podcasts/ })
    .first();
  await bringIntoReachInDrawer(page, finger, podcasts);
  await finger.hold(500);
  await finger.tap(podcasts);
  await page.waitForURL(/tag=/, { timeout: 10000 });
  await waitForDrawer(page, false);
  await finger.hold(1800);
}

async function podcastScene({ page, finger }) {
  await finger.tap(articles(page).first().getByRole('button', { name: 'Play', exact: true }));
  await page.locator('app-audio-player-bar').waitFor();
  await finger.hold(1800);
  await finger.tap(page.getByRole('button', { name: 'Open player', exact: true }));
  await page.locator('app-big-player').waitFor();
  await finger.hold(4000);
  await finger.tap(page.getByRole('button', { name: 'Minimise player', exact: true }));
  await finger.hold(1500);
}

// Done listening: the player bar goes first, or it would cover the drawer's foot.
async function themeScene({ page, finger, theme }) {
  await finger.tap(
    page.locator('app-audio-player-bar').getByRole('button', { name: 'Close player', exact: true }),
  );
  await page.locator('app-audio-player-bar').waitFor({ state: 'hidden' });
  await finger.hold(900);
  await finger.tap(sidebarButton(page));
  await waitForDrawer(page, true);
  await finger.hold(900);
  const otherTheme = page.getByRole('button', {
    name: theme === 'light' ? 'Dark' : 'Light',
    exact: true,
  });
  await bringIntoReachInDrawer(page, finger, otherTheme);
  await finger.hold(900);
  await finger.tap(otherTheme);
  await page.waitForFunction(
    (button) => button.getAttribute('aria-pressed') === 'true',
    await otherTheme.elementHandle(),
  );
  await finger.hold(1800);
  await finger.tap(page.getByRole('button', { name: 'Close menu', exact: true }));
  await waitForDrawer(page, false);
  await finger.hold(3000);
}

const SCENES = [
  ['magazine', magazineScene],
  ['video', videoScene],
  ['reader', readerScene],
  ['trending', trendingScene],
  ['sidebar', sidebarScene],
  ['podcast', podcastScene],
  ['theme', themeScene],
];

// Signs in and finds the readable articles once, off camera; neither depends on the theme.
async function prepare(browser) {
  const session = await browser.newContext(TOUR_PHONE);
  await session.addInitScript(seedSettings, { theme: 'light', layout: 'magazine' });
  await leaveOutDistressingNews(session, { arrange: withMixedSources });
  const page = await session.newPage();
  await signIn(page);
  const readable = await warmReadableTitles(page);
  const videoPost = (await findVideoPosts(page)).find(isCalmEntry);
  if (!videoPost) throw new Error('No followed account has posted a video lately');
  console.log(`  video post: ${videoPost.source} — ${videoPost.title.slice(0, 70)}`);
  const storageState = await session.storageState();
  await session.close();
  return { readable, storageState, videoPost };
}

async function recordTheme(browser, theme, { readable, storageState, videoPost }) {
  const recording = await browser.newContext({
    ...TOUR_PHONE,
    storageState,
    recordVideo: { dir: RAW, size: VIDEO_SIZE },
  });
  await recording.addInitScript(seedSettings, { theme, layout: 'magazine' });
  await recording.addInitScript(installTouchOverlay);
  await stubAiryStyle(recording);
  await leaveOutDistressingNews(recording, { arrange: placedInTheMagazine(videoPost) });
  const videoStart = Date.now();
  const elapsed = () => (Date.now() - videoStart) / 1000;
  const page = await recording.newPage();
  // The dev server's live-reload socket would reload the page when a file changes elsewhere.
  await page.routeWebSocket(
    () => true,
    () => {},
  );
  await page.goto(`${BASE}/`);
  await settle(page, 0);
  await articles(page).first().waitFor();
  const context = {
    page,
    finger: new Finger(page, await recording.newCDPSession(page)),
    readable,
    theme,
  };
  const starts = {};
  try {
    for (const [name, play] of SCENES) {
      starts[name] = elapsed();
      await play(context);
    }
  } catch (error) {
    await page.screenshot({ path: join(RAW, `${theme}-failure.png`) }).catch(() => {});
    throw error;
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  }
  const videoPath = await page.video().path();
  await recording.close();
  return { videoPath, starts };
}

function encode(theme, { videoPath, starts }) {
  const trimFrom = Math.max(0, starts.magazine - LEAD_IN_SECONDS);
  const mp4 = join(OUT, `tour-${theme}.mp4`);
  const posterPng = join(RAW, `tour-${theme}-poster.png`);
  execFileSync('ffmpeg', [
    ...['-v', 'error', '-y', '-ss', String(trimFrom), '-i', videoPath],
    ...['-c:v', 'libx264', '-preset', 'slow', '-crf', '30', '-pix_fmt', 'yuv420p'],
    ...['-movflags', '+faststart', mp4],
  ]);
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', mp4, '-frames:v', '1', posterPng]);
  execFileSync('cwebp', [
    '-quiet',
    '-q',
    '85',
    posterPng,
    '-o',
    join(OUT, `tour-${theme}-poster.webp`),
  ]);
  return SCENES.map(([name]) => ({ name, seconds: Number((starts[name] - trimFrom).toFixed(2)) }));
}

// Each scene button carries its start per theme: data-scene="reader" … data-light="6.5".
function writeSceneStarts(theme, scenes) {
  const pagePath = join(OUT, 'index.html');
  if (!existsSync(pagePath)) return;
  let page = readFileSync(pagePath, 'utf8');
  for (const { name, seconds } of scenes) {
    const attribute = new RegExp(`(data-scene="${name}"[^>]*\\bdata-${theme}=")[^"]*"`);
    if (!attribute.test(page)) throw new Error(`index.html has no data-${theme} on scene ${name}`);
    page = page.replace(attribute, `$1${seconds}"`);
  }
  writeFileSync(pagePath, page);
}

const themes = process.argv.length > 2 ? process.argv.slice(2) : ['light', 'dark'];
for (const theme of themes) {
  if (!['light', 'dark'].includes(theme))
    throw new Error(`Unknown theme "${theme}"; use light or dark`);
}
rmSync(RAW, { recursive: true, force: true });
mkdirSync(RAW, { recursive: true });
mkdirSync(OUT, { recursive: true });

// trending-setup.mjs ends with this status while the list is below its floor; any other failure
// is an error and stops the tour.
const BELOW_FLOOR = 2;
function isAboveFloor(mode) {
  try {
    execFileSync('node', mode ? [TRENDING_SETUP, mode] : [TRENDING_SETUP], { stdio: 'inherit' });
    return true;
  } catch (error) {
    if (error.status !== BELOW_FLOOR) throw error;
    return false;
  }
}
// Stories are built on the worker's schedule and titled within five minutes of that.
async function waitForTrending() {
  for (let minute = 0; !isAboveFloor('status'); minute++) {
    if (minute >= TRENDING_WAIT_MINUTES) throw new Error('Trending stayed below its floor');
    await new Promise((resolve) => setTimeout(resolve, 60_000));
  }
}

execFileSync('node', [PODCAST_SETUP], { stdio: 'inherit' });
try {
  if (!isAboveFloor()) await waitForTrending();
  const browser = await chromium.launch({
    args: [`--force-device-scale-factor=${PHONE.deviceScaleFactor}`],
  });
  try {
    const prepared = await prepare(browser);
    for (const theme of themes) {
      console.log(`recording ${theme}…`);
      const scenes = encode(theme, await recordTheme(browser, theme, prepared));
      writeSceneStarts(theme, scenes);
      console.log(
        `✓ tour-${theme}: ${scenes.map((scene) => `${scene.name}@${scene.seconds}s`).join(' ')}`,
      );
    }
  } finally {
    await browser.close();
  }
} finally {
  try {
    execFileSync('node', [PODCAST_SETUP, 'teardown'], { stdio: 'inherit' });
  } finally {
    execFileSync('node', [TRENDING_SETUP, 'teardown'], { stdio: 'inherit' });
  }
}
