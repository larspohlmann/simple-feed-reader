import { test, expect, Page } from '@playwright/test';
import { signInAsAdmin } from './support/auth';
import { entryWire } from './support/reader';
import { stubFixtureImages } from './support/images';

/** A phone in portrait: the width the thumb's text column is narrowest at. */
const PHONE = { width: 375, height: 812 };

/**
 * A 200px image fits the thumb slot, which takes any persisted image, and is too
 * narrow for split (300+), so the planner demotes every entry here to a thumb
 * card. Each has an audio enclosure with a long declared duration, so the
 * actions carry the duration, play and add-to-playlist controls — the widest
 * action cluster a card can get — and two saved-search pills with names to lose.
 */
function thumbEntry(id: number) {
  return entryWire({
    id,
    title: `Thumb fixture ${id}`,
    url: `https://fixtures.invalid/${id}`,
    summary: null,
    imageUrl: `https://fixtures.invalid/${id}.jpg`,
    imageWidth: 200,
    imageHeight: 150,
    subscriptionId: 100 + id,
    source: `Thumb source ${id}`,
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

const ENTRIES = Array.from({ length: 6 }, (_, index) => thumbEntry(index + 1));

/** Matched on the pathname so `/api/entries/{id}` still reaches the backend. */
async function stubThumbEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
  await stubFixtureImages(page);
}

/**
 * Starts on the phone instead of resizing into it — a resize measured a
 * half-applied layout once (#274). `isMobile` + `hasTouch` make
 * `(pointer: coarse)` match, which is what widens the action cluster's gaps.
 */
test.describe('Magazine thumb card on a phone', () => {
  test.use({ viewport: PHONE, isMobile: true, hasTouch: true });

  /** Measured, not eyeballed: the row's width against the card's content box, and
   *  each pill name's drawn width — beside the image they shrank to 0px (#1720). */
  test('a thumb card with audio actions keeps its pill names and a card-wide meta row', async ({
    page,
  }) => {
    await stubThumbEntries(page);
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    const cards = page.locator('app-entry-thumb .thumb');
    await expect(cards.first()).toBeVisible();
    await expect(cards.first().locator('img.img')).toBeVisible();
    await expect(cards.first().locator('app-entry-actions .duration')).toHaveText('1:02:37');

    const measurements = await cards.evaluateAll((elements) =>
      elements.map((card) => {
        const style = getComputedStyle(card);
        const contentWidth =
          card.getBoundingClientRect().width -
          parseFloat(style.paddingLeft) -
          parseFloat(style.paddingRight);
        const meta = card.querySelector('app-entry-meta');
        const names = Array.from(card.querySelectorAll('.pill .name')).map((name) => ({
          text: name.textContent?.trim() ?? '',
          width: Math.round(name.getBoundingClientRect().width),
          clipped: name.scrollWidth > name.clientWidth + 1,
        }));
        return {
          metaShortfall: meta
            ? Math.round(contentWidth - meta.getBoundingClientRect().width)
            : null,
          names,
        };
      }),
    );

    expect(
      measurements.every((card) => card.names.length === 2),
      'every thumb card must carry both pills',
    ).toBe(true);

    const narrowRows = measurements
      .map((card) => card.metaShortfall)
      .filter((shortfall) => shortfall === null || shortfall > 2);
    expect(narrowRows, `meta rows short of the card's width by (px): ${narrowRows}`).toEqual([]);

    const lostNames = measurements
      .flatMap((card) => card.names)
      .filter((name) => name.width === 0 || name.clipped);
    expect(lostNames, `pill names hidden or clipped: ${JSON.stringify(lostNames)}`).toEqual([]);
  });
});
