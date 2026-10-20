import { Page } from '@playwright/test';

/** A single opaque pixel: the smallest image a browser decodes. */
const ONE_PIXEL_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
  'base64',
);

/** Serves every `https://fixtures.invalid/*.jpg` as a loadable image: an errored
 *  `<img>` sets a block's `imgError`, which hides the image and demotes the card
 *  to a text-only block, silently defeating a test of the image-bearing one. */
export async function stubFixtureImages(page: Page): Promise<void> {
  await page.route(
    (url) => url.hostname === 'fixtures.invalid' && url.pathname.endsWith('.jpg'),
    async (route) => {
      await route.fulfill({ status: 200, contentType: 'image/png', body: ONE_PIXEL_PNG });
    },
  );
}
