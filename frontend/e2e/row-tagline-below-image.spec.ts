import { test, expect, Page } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryWire } from './support/reader';
import { stubFixtureImages } from './support/images';

const PHONE = { width: 375, height: 812 };

/**
 * Each entry has an image and an audio enclosure with a long declared duration,
 * so its actions carry the duration, play and add-to-playlist controls — the
 * widest cluster a row can get — and two saved-search pills with names to lose.
 */
function audioEntry(id: number) {
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
    savedSearches: [
      { id: 1, slug: 'soundcloud', term: 'soundcloud' },
      { id: 2, slug: 'chillout', term: 'Chillout' },
    ],
  });
}

const ENTRIES = Array.from({ length: 6 }, (_, index) => audioEntry(index + 1));

/** Matched on the pathname so `/api/entries/{id}` still reaches the backend. */
async function stubAudioEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
  await stubFixtureImages(page);
}

test.describe('Rows layout on a phone', () => {
  test.use({ viewport: PHONE, isMobile: true, hasTouch: true });

  /** Beside the thumbnail the pill names shrank to a letter or nothing (#1745). */
  test('a row with audio actions keeps its pill names and a row-wide tagline', async ({ page }) => {
    await stubAudioEntries(page);
    const signedIn = await signInWithLayout(page, 'list');
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    const rows = page.locator('app-entry-row > article.row');
    await expect(rows.first()).toBeVisible();
    await expect(rows.first().locator('img.thumb')).toBeVisible();
    await expect(rows.first().locator('app-entry-actions .duration')).toHaveText('1:02:37');

    const measurements = await rows.evaluateAll((elements) =>
      elements.map((row) => {
        const style = getComputedStyle(row);
        const contentWidth =
          row.getBoundingClientRect().width -
          parseFloat(style.paddingLeft) -
          parseFloat(style.paddingRight);
        const tagline = row.querySelector('.tagline');
        const names = Array.from(row.querySelectorAll('app-entry-pills .pill .name')).map(
          (name) => ({
            text: name.textContent?.trim() ?? '',
            width: Math.round(name.getBoundingClientRect().width),
            clipped: name.scrollWidth > name.clientWidth + 1,
          }),
        );
        return {
          taglineShortfall: tagline
            ? Math.round(contentWidth - tagline.getBoundingClientRect().width)
            : null,
          names,
        };
      }),
    );

    expect(
      measurements.map((row) => row.names.length),
      'every row must carry both pills',
    ).toEqual(ENTRIES.map(() => 2));

    const narrowRows = measurements
      .map((row) => row.taglineShortfall)
      .filter((shortfall) => shortfall === null || shortfall > 2);
    expect(narrowRows, `taglines short of the row's width by (px): ${narrowRows}`).toEqual([]);

    const lostNames = measurements
      .flatMap((row) => row.names)
      .filter((name) => name.width === 0 || name.clipped);
    expect(lostNames, `pill names hidden or clipped: ${JSON.stringify(lostNames)}`).toEqual([]);
  });
});
