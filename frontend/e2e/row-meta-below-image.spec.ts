import { test, expect, Page } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryWire } from './support/reader';
import { stubFixtureImages } from './support/images';

type SavedSearchPill = { id: number; slug: string; term: string };

const TWO_PILLS: SavedSearchPill[] = [
  { id: 1, slug: 'soundcloud', term: 'soundcloud' },
  { id: 2, slug: 'chillout', term: 'Chillout' },
];

const THREE_LONGER_PILLS: SavedSearchPill[] = [
  { id: 1, slug: 'psytrance', term: 'Psytrance releases' },
  { id: 2, slug: 'chillout', term: 'Chillout mixes' },
  { id: 3, slug: 'soundcloud', term: 'soundcloud' },
];

/**
 * An image and an audio enclosure with a long declared duration, so the
 * actions carry the duration, play and add-to-playlist controls — the widest
 * cluster a row can get — beside saved-search pills with names to lose.
 */
function audioEntry(id: number, savedSearches: SavedSearchPill[]) {
  return entryWire({
    id,
    title: `Row fixture ${id}`,
    url: `https://fixtures.invalid/${id}`,
    summary: null,
    imageUrl: `https://fixtures.invalid/${id}.jpg`,
    imageWidth: 200,
    imageHeight: 150,
    subscriptionId: 100 + id,
    source: `Row source ${id}`,
    attachments: [
      {
        url: `https://fixtures.invalid/episode-${id}.mp3`,
        mimeType: 'audio/mpeg',
        durationInSeconds: 3757,
      },
    ],
    savedSearches,
  });
}

/** Matched on the pathname so `/api/entries/{id}` still reaches the backend. */
async function stubAudioEntries(page: Page, savedSearches: SavedSearchPill[]): Promise<void> {
  const entries = Array.from({ length: 6 }, (_, index) => audioEntry(index + 1, savedSearches));
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries, nextCursor: null } });
    },
  );
  await stubFixtureImages(page);
}

async function expectFullWidthMetaWithReadablePills(page: Page, pillCount: number) {
  const signedIn = await signInWithLayout(page, 'list');
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  const rows = page.locator('app-entry-row > article.row');
  await expect(rows.first().locator('img.thumb')).toBeVisible();
  await expect(rows.first().locator('app-entry-actions .duration')).toHaveText('1:02:37');

  const measurements = await rows.evaluateAll((elements) =>
    elements.map((row) => {
      const meta = row.querySelector('app-entry-meta')!.getBoundingClientRect().width;
      const top = row.querySelector('.top')!.getBoundingClientRect().width;
      const names = Array.from(row.querySelectorAll('app-entry-pills .pill .name')).map((name) => ({
        text: name.textContent?.trim() ?? '',
        width: Math.round(name.getBoundingClientRect().width),
        clipped: name.scrollWidth > name.clientWidth + 1,
      }));
      return { metaShortfall: Math.round(top - meta), names };
    }),
  );

  expect(measurements.map((row) => row.names.length)).toEqual(measurements.map(() => pillCount));
  const narrowRows = measurements.map((row) => row.metaShortfall).filter((gap) => gap > 1);
  expect(narrowRows, `meta rows short of the row's width by (px): ${narrowRows}`).toEqual([]);
  const lostNames = measurements
    .flatMap((row) => row.names)
    .filter((name) => name.width === 0 || name.clipped);
  expect(lostNames, `pill names hidden or clipped: ${JSON.stringify(lostNames)}`).toEqual([]);
}

/** Beside the thumbnail the pill names shrank to a letter or nothing (#1745). */
test.describe('List layout on a phone', () => {
  test.use({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });

  test('a row with audio actions keeps its pill names and a row-wide meta line', async ({
    page,
  }) => {
    await stubAudioEntries(page, TWO_PILLS);
    await expectFullWidthMetaWithReadablePills(page, 2);
  });
});

test.describe('List layout on a narrow phone', () => {
  test.use({ viewport: { width: 320, height: 640 }, isMobile: true, hasTouch: true });

  test('the actions wrap below pills that cannot keep their names beside them', async ({
    page,
  }) => {
    await stubAudioEntries(page, THREE_LONGER_PILLS);
    await expectFullWidthMetaWithReadablePills(page, 3);
  });

  test('a row without pills keeps its actions on one line, with no blank line above', async ({
    page,
  }) => {
    await stubAudioEntries(page, []);
    const signedIn = await signInWithLayout(page, 'list');
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    const meta = page.locator('app-entry-row app-entry-meta').first();
    await expect(meta.locator('app-entry-actions .duration')).toHaveText('1:02:37');
    const heights = await meta.evaluate((element) => ({
      meta: element.getBoundingClientRect().height,
      actions: element.querySelector('app-entry-actions')!.getBoundingClientRect().height,
    }));
    expect(heights.meta).toBeLessThanOrEqual(heights.actions + 1);
  });
});
