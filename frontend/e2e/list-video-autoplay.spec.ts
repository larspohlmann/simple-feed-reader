import { test, expect, Page } from '@playwright/test';
import { signInAsAdmin } from './support/auth';
import { entryWire } from './support/reader';

const CLIP = 'https://fixtures.invalid/clip.mp4';

const ENTRIES = [
  entryWire({
    id: 1,
    url: 'https://bsky.app/profile/someone/post/1',
    title: 'A donkey at sunrise',
    titleDerived: true,
    share: 'media',
    excerpt: 'A donkey at sunrise',
    media: [{ url: CLIP, kind: 'video', width: 640, height: 360 }],
  }),
];

type Play = { src: string; muted: boolean };

/** Chromium here has no H.264, so `play()` is recorded instead of decoding a clip. */
async function recordPlays(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const plays: Play[] = [];
    (window as unknown as { plays: Play[] }).plays = plays;
    HTMLMediaElement.prototype.play = function (this: HTMLMediaElement) {
      plays.push({ src: this.currentSrc || this.src, muted: this.muted });
      return Promise.resolve();
    };
  });
}

async function stubEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
}

const plays = (page: Page) => page.evaluate(() => (window as unknown as { plays: Play[] }).plays);

test('a visible list video starts on its own, muted', async ({ page }) => {
  await recordPlays(page);
  await stubEntries(page);
  test.skip(!(await signInAsAdmin(page)), 'no admin account on this stack');

  await expect(page.locator('app-entry-media video')).toBeVisible();
  await expect.poll(() => plays(page)).toEqual([{ src: CLIP, muted: true }]);
});

test('nothing starts once autoplay is switched off', async ({ page }) => {
  await recordPlays(page);
  await stubEntries(page);
  test.skip(!(await signInAsAdmin(page)), 'no admin account on this stack');

  const toggle = page.locator('#list-autoplay-toggle');
  const label = page.locator('label[for="list-autoplay-toggle"]');
  try {
    await page.goto('/settings/preferences');
    await expect(toggle).toBeChecked();
    await label.click();
    await expect(toggle).not.toBeChecked();

    await page.goto('/');
    await expect(page.locator('app-entry-media video')).toBeVisible();
    await page.waitForTimeout(500);
    expect(await plays(page)).toEqual([]);
  } finally {
    await page.goto('/settings/preferences');
    if (!(await toggle.isChecked())) await label.click();
    await expect(toggle).toBeChecked();
  }
});

test('an autoplaying list video hides its controls until tapped, and the tap turns sound on', async ({
  page,
}) => {
  await recordPlays(page);
  await stubEntries(page);
  test.skip(!(await signInAsAdmin(page)), 'no admin account on this stack');

  const video = page.locator('app-entry-media video');
  await expect.poll(() => plays(page)).toEqual([{ src: CLIP, muted: true }]);
  await expect(video).not.toHaveAttribute('controls');

  await video.click();

  await expect(video).toHaveAttribute('controls');
  expect(await video.evaluate((element: HTMLVideoElement) => element.muted)).toBe(false);
});
