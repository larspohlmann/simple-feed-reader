import { Page } from '@playwright/test';
import type { EntryDto } from '../../src/app/reader/models';

/** A single opaque pixel: the smallest image a browser decodes. */
export const ONE_PIXEL_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
  'base64',
);

/** Answers every request to `host` whose path ends in `suffix` with the one pixel. */
export async function stubImageHost(page: Page, host: string, suffix = ''): Promise<void> {
  await page.route(
    (url) => url.hostname === host && url.pathname.endsWith(suffix),
    (route) => route.fulfill({ status: 200, contentType: 'image/png', body: ONE_PIXEL_PNG }),
  );
}

/** Serves every `https://fixtures.invalid/*.jpg` as a loadable image: an errored
 *  `<img>` sets a block's `imgError`, which hides the image and demotes the card
 *  to a text-only block, silently defeating a test of the image-bearing one. */
export async function stubFixtureImages(page: Page): Promise<void> {
  await stubImageHost(page, 'fixtures.invalid', '.jpg');
}

/** Inline, so no external host can 404 and cost a spec its image. */
export const INLINE_IMAGE =
  'data:image/svg+xml;base64,' +
  Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#8a8a86"/></svg>',
  ).toString('base64');

/** A large, non-portrait image on every entry so `hero` (>= 500px) and `wide`
 *  (>= 400px) both fit without demoting; seven entries across three sources
 *  spill a `wide` opener onto a second page whose template opens `hero`. */
export function imageEntries(entry: (id: number, source: string) => EntryDto): EntryDto[] {
  const sources = [
    'Heise',
    'Tagesschau',
    'Heise',
    'Der Spiegel',
    'Tagesschau',
    'Der Spiegel',
    'Heise',
  ];
  return sources.map((source, index) => ({
    ...entry(index + 1, source),
    imageUrl: INLINE_IMAGE,
    imageWidth: 1200,
    imageHeight: 800,
  }));
}
