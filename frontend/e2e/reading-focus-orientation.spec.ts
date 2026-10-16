import { test, expect, Page } from '@playwright/test';
import { presetLocalStorage, signInAsAdmin } from './support/auth';
import { entryWire } from './support/reader';
import { READING_BAND } from '../src/app/reader/article/reading/reading-focus';

/** A phone portrait and the same phone rotated — both under `WIDE_QUERY`
 *  (900px), the dim's only gate, so it stays active; only the viewport
 *  height, and so the reading band, changes. */
const PORTRAIT = { width: 375, height: 667 };
const LANDSCAPE = { width: 667, height: 375 };

const ENTRY_COUNT = 40;

function entry(id: number) {
  return entryWire({
    id,
    title: `Fixture entry ${id}`,
    url: `https://fixtures.invalid/${id}`,
    summary: null,
  });
}

// Identical rows, so the row nearest the reading band is decided by
// position alone — not by one entry happening to be taller than its
// neighbours.
const ENTRIES = Array.from({ length: ENTRY_COUNT }, (_, i) => entry(i + 1));

/** Stub the entry list so this spec owns its data (#96). Matched on the
 *  pathname so entry detail/state calls still reach the real backend, as in
 *  magazine-kicker-one-line.spec.ts. */
async function stubEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
}

async function signInWithFocusedList(page: Page): Promise<boolean> {
  await stubEntries(page);
  // Pin layout to list (not magazine) and reading focus on — the trick from
  // reading-focus-blocks.spec.ts, now also covering the focus toggle so this
  // spec doesn't depend on the app's own default.
  await presetLocalStorage(page, { 'sfr.layout': 'list', 'sfr.readingFocus': 'true' });
  return signInAsAdmin(page);
}

interface FocusEdge {
  index: number;
  opacity: number;
}

/** The row on the band's lower edge — a rotation moves that edge past a row's
 *  height, where a row merely inside the band could stay in it — and the row
 *  farthest from it, each with its current inline opacity. */
async function readingFocusEdges(page: Page): Promise<{ near: FocusEdge; far: FocusEdge }> {
  return page.evaluate((bandBottom) => {
    const scroller = document.querySelector('.rows') as HTMLElement;
    const scrollerTop = scroller.getBoundingClientRect().top;
    const edge = scroller.clientHeight * bandBottom;
    const rows = Array.from(scroller.querySelectorAll(':scope > *:not(.foot)')) as HTMLElement[];
    const measured = rows.map((row, index) => {
      const rect = row.getBoundingClientRect();
      const top = rect.top - scrollerTop;
      const bottom = top + rect.height;
      const distance = Math.max(top - edge, edge - bottom, 0);
      const opacity = row.style.opacity === '' ? 1 : Number(row.style.opacity);
      return { index, distance, opacity };
    });
    const near = measured.reduce((a, b) => (b.distance < a.distance ? b : a));
    const far = measured.reduce((a, b) => (b.distance > a.distance ? b : a));
    return {
      near: { index: near.index, opacity: near.opacity },
      far: { index: far.index, opacity: far.opacity },
    };
  }, READING_BAND.bottom);
}

/** True once the dim has settled: the row nearest the band is focused, the
 *  row farthest from it is dimmed toward the curve's floor. */
async function isSettled(page: Page): Promise<boolean> {
  const { near, far } = await readingFocusEdges(page);
  return near.opacity >= 0.9 && far.opacity <= 0.5;
}

/** The current inline opacity of the row at this position among
 *  `.rows > *:not(.foot)` — the same set `ReadingFocusApplier` fades. */
async function opacityOfRow(page: Page, index: number): Promise<number> {
  return page.evaluate((rowIndex) => {
    const scroller = document.querySelector('.rows') as HTMLElement;
    const row = scroller.querySelectorAll(':scope > *:not(.foot)')[rowIndex] as HTMLElement;
    return row.style.opacity === '' ? 1 : Number(row.style.opacity);
  }, index);
}

test.describe('Reading focus dim survives a rotation', () => {
  test.use({ viewport: PORTRAIT });

  test('the dim re-applies against the new reading band after rotating', async ({ page }) => {
    const signedIn = await signInWithFocusedList(page);
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');

    // `.rows > *:not(.foot)` — what `ReadingFocusApplier` actually fades — also
    // holds a permanent, zero-height `.top-block` slot ahead of the entries;
    // count the entry rows themselves to confirm the fixture rendered in full.
    await expect(page.locator('.rows > .row-slot')).toHaveCount(ENTRY_COUNT);

    // Scroll so a mid-list row sits in the band, with rows on both
    // sides — not the first/last row, which would shift for reasons of its
    // own and not isolate what the rotation does to the geometry.
    await page.locator('.rows').evaluate((el) => {
      el.scrollTo({ top: (el.scrollHeight - el.clientHeight) / 2, behavior: 'instant' });
    });

    await expect
      .poll(() => isSettled(page), {
        message: 'reading-focus dim did not settle in portrait',
      })
      .toBe(true);
    const { near: portraitNear } = await readingFocusEdges(page);

    await page.setViewportSize(LANDSCAPE);

    // Layout reflects the new viewport at once, but `ReadingFocusApplier`'s
    // opacity write lags a frame — reading now both proves the band moved
    // to a new row (so the poll below can't hang) and captures its stale value.
    const { near: rotatedNear } = await readingFocusEdges(page);
    expect(
      rotatedNear.index,
      'the rotation should move the reading band onto a different row',
    ).not.toBe(portraitNear.index);

    // The dim re-applies against the new band: some row settles near-focused
    // and some row settles clearly dimmed (the general shape of the curve)…
    await expect
      .poll(() => isSettled(page), {
        message: 'reading-focus dim did not re-apply after the rotation',
      })
      .toBe(true);

    // …and, concretely, it is the row the rotation actually moved into the
    // band that picks up the focused opacity, not merely some row.
    await expect
      .poll(() => opacityOfRow(page, rotatedNear.index), {
        message: 'the row now in the reading band never regained focus',
      })
      .toBeGreaterThanOrEqual(0.9);

    // The row that used to sit in the band is out of it now, so it must have
    // lost the focused opacity it carried in portrait — otherwise nothing
    // would actually have been recomputed for the new geometry.
    await expect
      .poll(() => opacityOfRow(page, portraitNear.index), {
        message: 'the old band row kept its portrait opacity instead of re-fading',
      })
      .toBeLessThan(0.8);
  });
});
