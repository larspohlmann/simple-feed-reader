# #501 — List blinks to the background colour while scrolling far down: findings and fix design

Issue: https://github.com/larspohlmann/simple-feed-reader/issues/501

## Symptom

On iPhone 12 in Brave (WebKit), scrolling far down a long entry list sometimes
blinks part of the list to the app background colour for a split second, then
the content comes back. The scroll position is kept. It is rare and only seen
far down a long list.

## Root cause (measured 2026-09-05)

Every scroll event on the list scroller runs a **full change-detection tick
over a Default-CD component tree whose cost is linear in the number of loaded
blocks**, and the reading-focus pass adds a second tick per frame:

1. `.rows` is wired with a template `(scroll)="onRowsScroll($event)"`
   (`entry-list.component.html:247` and `:381`). A template listener runs
   inside the Angular zone, so zone.js ends every scroll event with
   `ApplicationRef.tick()`. The app uses `provideZoneChangeDetection({ eventCoalescing: true })`
   (`app.config.ts`); none of the magazine block components
   (`src/app/reader/magazine/*`), `EntryRowComponent` or `EntryListComponent`
   are `OnPush`, so the tick re-checks every binding of every loaded block.
2. `onRowsScroll` calls `pulseFocus()`, which bumps the `focusPulse` signal.
   That re-runs the `_readingFocus` effect, which calls `scheduleFocus()`
   **inside the zone**, so the `requestAnimationFrame` callback that runs
   `applyFocus()` also ends in a tick. Two ticks per scroll frame.
3. iOS WebKit scrolls `overflow: auto` boxes on the compositor thread and
   paints their content in tiles ahead of the scroll. When the main thread
   misses frames, the scroll thread reaches tiles that are not painted yet.
   `.rows` has no background, so an unpainted tile shows the themed app
   background — exactly the "blinks to the background colour" report. Once
   the main thread catches up the tiles paint and the content "reappears".

Measured in headless Chromium at a 375x812 viewport on a hermetic list
(100 entries per page, every second entry with an image, `sfr.readingFocus`
on), `Emulation.setCPUThrottlingRate` 6 to approximate the phone
(script in the appendix):

| Loaded blocks | one CD tick (median) | reading-focus pass | frame time during a synthetic scroll (mean / p95) |
|---|---|---|---|
| 101 | 5.2 ms | 1.4 ms | 20.6 / 31.9 ms |
| 101, focus off | 4.9 ms | – | 11.7 / 17.1 ms |
| 601 | 32.6 ms | 8.4 ms | 77.1 / 93.5 ms |
| 601, focus off | 31.5 ms | – | 65.3 / 77.6 ms |

Unthrottled on the M-series Mac the tick is 0.9 ms at 101 blocks and 4.1 ms
at 601 — the scaling is the same, the desktop just has headroom. The frame
time at 601 blocks is about two ticks plus the focus pass, which matches the
mechanism above. A frame budget is 16.7 ms.

### What the issue's own hypotheses were worth

- **`transform: translateY(0px)` at rest on `.rows`** — not the cause. On iOS
  every `overflow: auto` box is already an async-scrolled composited layer with
  tiled content; the transform changes nothing about that. Leave it.
- **Reading-focus opacity floor 0.2** — the issue ruled the fade out because it
  cannot reach the background colour. Right conclusion, wrong mechanism: the
  fade's cost is main-thread time, not its visual output.
- **`backdrop-filter`** — since #758 (2026-09-03) the list header and the
  to-top button carry `backdrop-filter`. The issue predates that, so it is not
  the original cause. It is not touched here.

## Fix design

Keep the scroll path off the Angular zone so a scroll frame costs what the
scroll handler itself costs, not a tree-wide tick:

1. **`ScrollOutsideZoneDirective`** (`frontend/src/app/reader/scroll-outside-zone.directive.ts`,
   selector `[appScrollOutsideZone]`): attaches a passive `scroll` listener to
   its host element inside `NgZone.runOutsideAngular`, forwards the event to
   the handler given as its input, removes the listener on destroy. Same
   pattern as `PaneResizeDirective`'s live drag.
2. **Both `#rows` elements** use it instead of `(scroll)`:
   `[appScrollOutsideZone]="onRowsScroll"`. `onRowsScroll` becomes an arrow
   property so it can be handed over as a value.
3. **The scroll path calls `scheduleFocus()` directly** instead of
   `pulseFocus()`. Bumping a signal per scroll event forces an effect run and
   with it a tick. `focusPulse` stays for resize and the row-collapse
   animation, which are rare.
4. **`scheduleFocus()` requests its frame inside `runOutsideAngular`**, so the
   `applyFocus()` frame ends without a tick from either caller (the effect or
   the scroll handler). `applyFocus()` writes inline styles only, never a
   signal.

Signal writes from the outside-zone handler (`collapsed`, `showToTop`) still
render: Angular 20's hybrid scheduler (`ignoreChangesOutsideZone` is `false`
by default) schedules a tick when a signal value actually changes, and
`signal.set` with an equal value costs nothing. `header-scroll-mobile.spec.ts`
proves the collapse still renders in a real browser.

Expected after the fix (same script, throttle 6, 601 blocks, focus on): the
CD-tick measurement is unchanged (it measures a tick, not the scroll), the
frame time drops to roughly the focus pass plus the handler — well under one
tick. Record the numbers in the PR.

## Non-goals / deferred

- **`OnPush` on the magazine block components, `EntryRowComponent` and the
  list itself.** This is the architectural fix for the tick cost itself: every
  tick anywhere in the app would stop re-checking hundreds of unchanged
  blocks. It needs an audit that each component drives its template from
  signals or inputs only. Worth its own issue; this fix removes the ticks that
  scroll causes, which is what #501 is about.
- **`reader-view.component.ts`** has the same in-zone `@HostListener('scroll') onScroll()` →
  `scheduleFocus()` → `requestAnimationFrame` chain for the article pane
  (`reader-view.component.ts:515,605`). An article is one page, so the cost
  is bounded; migrate it to the directive when it is next touched.
- Trimming `applyFocus()` (skip rows whose opacity does not change, early exit
  once rows are a viewport below the fold). Second-order after the ticks.

## Verification

- Unit: `docker compose exec -T frontend npm run check` (ESLint + Prettier +
  Stylelint + Jest, the CI gate). Native `npx jest` skips the type check.
- Real browser: `npm run e2e -- header-scroll-mobile pull-to-refresh-mobile list-scroll-reset`
  from `frontend/` with the Docker stack up (these three drive the scroller).
- Measurement: run the appendix script before and after, `THROTTLE=6 PAGES=6`.
- Device: after the next `vX.Y.Z-dev.N` deploy, scroll far down a long list on
  the iPhone. The issue lists what to watch for.

## Appendix — measurement script

Save as `measure-501.mjs` in a scratch directory (not in the repo), with the
Docker stack up and the dev server on `http://localhost:4200`. Run from
anywhere: `THROTTLE=6 PAGES=6 FOCUS=on node measure-501.mjs`. It imports
Playwright from `frontend/node_modules`, stubs every reader API route, loads
`PAGES` pages of 100 entries by scrolling to the sentinel, then measures one
change-detection tick (`window.ng.applyChanges` on the reader shell, 30 runs),
a replica of the reading-focus pass (20 runs) and the frame time of a
90-frame synthetic scroll upward from the bottom.

```js
import { chromium } from '/Users/lars/Documents/work/eigenes/simple-feed-reader/frontend/node_modules/playwright/index.mjs';

const PAGES = Number(process.env.PAGES ?? 5);
const FOCUS = process.env.FOCUS !== 'off';
const PAGE_SIZE = 100;
const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
);

function entry(id) {
  const withImage = id % 2 === 0;
  return {
    id,
    title: `Fixture entry ${id} with a reasonably long headline that wraps`,
    url: `https://fixtures.invalid/e/${id}`,
    author: null,
    summary: 'A short fixture summary that reads like a real teaser paragraph would.',
    contentHtml: '<p>Fixture body.</p>',
    imageUrl: withImage ? `https://fixtures.invalid/img/${id}.png` : null,
    imageWidth: withImage ? 1200 : null,
    imageHeight: withImage ? 800 : null,
    publishedAt: '2026-08-29T08:00:00Z',
    createdAt: '2026-08-29T08:00:00Z',
    subscriptionId: 1 + (id % 4),
    source: ['Heise', 'Tagesschau', 'NDR', 'Spiegel'][id % 4],
    faviconUrl: null,
    isHidden: false,
    isFavorite: false,
    isKept: false,
    isViewed: false,
  };
}

function subscription(id, title) {
  return {
    id, feedId: id * 10, title, customTitle: null, lastFetchedAt: '2026-08-29T09:00:00Z',
    feedUrl: `https://fixtures.invalid/${id}`, siteUrl: null, status: 'active', sourceFormat: 'xml',
    createdAt: '2026-08-01T00:00:00Z', tags: [], unreadCount: 500, includeInAllItems: true, includeInForYou: true,
  };
}

async function stubReader(page) {
  await page.addInitScript((focus) => {
    localStorage.setItem('sfr.jwt', 'stub-token-for-the-guard');
    localStorage.setItem('sfr.readingFocus', String(focus));
  }, FOCUS);
  await page.route('https://fixtures.invalid/img/**', (route) =>
    route.fulfill({ status: 200, contentType: 'image/png', body: PNG_1X1 }),
  );
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    const json = (body) => route.fulfill({ status: 200, json: body });
    if (path.endsWith('/api/subscriptions')) {
      return json({ subscriptions: [1, 2, 3, 4].map((i) => subscription(i, ['Heise', 'Tagesschau', 'NDR', 'Spiegel'][i - 1])), favoritesCount: 0, keptCount: 0, viewedCount: 0 });
    }
    if (path.endsWith('/api/me')) return json({ email: 'fixture@example.invalid', roles: ['ROLE_USER'] });
    if (path.endsWith('/api/tags')) return json({ tags: [] });
    if (path.endsWith('/api/saved-searches')) return json({ savedSearches: [] });
    if (path.endsWith('/api/setup/status')) return json({ needsSetup: false, mailEnabled: true, passkeySignInAvailable: false });
    if (path.endsWith('/api/entries')) {
      const pageNo = Number(url.searchParams.get('cursor') ?? 0);
      const entries = Array.from({ length: PAGE_SIZE }, (_, i) => entry(pageNo * PAGE_SIZE + i + 1));
      return json({ entries, nextCursor: pageNo + 1 < PAGES ? String(pageNo + 1) : null });
    }
    if (path.includes('/api/recommendations/runs/current')) {
      return json({ status: 'none', batchesTotal: null, batchesDone: 0, error: null, background: false, streamedChars: 0, forYou: { itemCount: 0, generatedAt: null, newestRunId: null } });
    }
    if (path.endsWith('/api/version')) return json({ version: 'dev', commit: 'local', builtAt: '', latest: null, updateAvailable: false });
    return json({});
  });
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 375, height: 812 } });
await stubReader(page);
const THROTTLE = Number(process.env.THROTTLE ?? 1);
if (THROTTLE > 1) { const cdp = await page.context().newCDPSession(page); await cdp.send('Emulation.setCPUThrottlingRate', { rate: THROTTLE }); }
await page.goto('http://localhost:4200/reader?view=all');
const rows = page.locator('.rows');
await rows.first().waitFor();
await page.locator('.rows .magazine-slot').first().waitFor();

for (let attempt = 0; attempt < PAGES * 4; attempt++) {
  const before = await page.locator('.rows > *').count();
  await rows.evaluate((el) => el.scrollTo({ top: el.scrollHeight, behavior: 'instant' }));
  try {
    await page.waitForFunction((n) => document.querySelector('.rows').children.length > n, before, { timeout: 1500 });
  } catch { break; }
}
await page.waitForTimeout(300);

const result = await page.evaluate(async () => {
  const rows = document.querySelector('.rows');
  const children = rows.children.length;
  const ng = window.ng;
  const shellCmp = ng.getComponent(document.querySelector('app-reader-shell'));

  const ticks = [];
  for (let i = 0; i < 30; i++) {
    const t0 = performance.now();
    ng.applyChanges(shellCmp);
    ticks.push(performance.now() - t0);
  }
  ticks.sort((a, b) => a - b);

  const focusRuns = [];
  for (let i = 0; i < 20; i++) {
    const t0 = performance.now();
    const viewport = rows.clientHeight;
    const rowsTop = rows.getBoundingClientRect().top;
    for (const child of Array.from(rows.children)) {
      const rect = child.getBoundingClientRect();
      const top = rect.top - rowsTop;
      const center = viewport / 2;
      const d = Math.max(top - center, center - (top + rect.height), 0);
      const ratio = Math.min(d / center, 1);
      child.style.opacity = String(+(1 - ratio * 0.8 + i * 0.0001).toFixed(4));
    }
    void rows.offsetHeight;
    focusRuns.push(performance.now() - t0);
  }
  focusRuns.sort((a, b) => a - b);

  rows.scrollTop = rows.scrollHeight;
  await new Promise((r) => requestAnimationFrame(r));
  const frames = [];
  let last = performance.now();
  await new Promise((resolve) => {
    let n = 0;
    const step = () => {
      const now = performance.now();
      frames.push(now - last);
      last = now;
      rows.scrollTop -= 40;
      if (++n < 90) requestAnimationFrame(step); else resolve();
    };
    requestAnimationFrame(step);
  });
  frames.shift();
  const sorted = [...frames].sort((a, b) => a - b);
  const p = (arr, q) => arr[Math.min(arr.length - 1, Math.floor(arr.length * q))];
  return {
    children,
    tickMs: { median: p(ticks, 0.5).toFixed(2), p90: p(ticks, 0.9).toFixed(2) },
    focusPassMs: { median: p(focusRuns, 0.5).toFixed(2), p90: p(focusRuns, 0.9).toFixed(2) },
    frameMs: { mean: (frames.reduce((a, b) => a + b, 0) / frames.length).toFixed(2), p95: p(sorted, 0.95).toFixed(2), max: sorted[sorted.length - 1].toFixed(2), over16: frames.filter((f) => f > 16.7).length },
  };
});
console.log(JSON.stringify({ PAGES, FOCUS, THROTTLE, ...result }));
await browser.close();
```
