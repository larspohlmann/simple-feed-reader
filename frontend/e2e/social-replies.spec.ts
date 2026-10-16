import { expect, Page, test } from '@playwright/test';
import { anEntryComment } from '../src/testing/entry-testing';
import { CommentsResponse } from '../src/app/reader/entry-comment';
import { presetLocalStorage } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson, stubOneFeedReader } from './support/reader';

/**
 * #1616: a social post's article lists its replies, indented by depth, with
 * their media, an "Updated" age and a refresh that refetches replies and
 * counts. Every API read is stubbed, so the spec owns the replies it asserts on.
 */
const POST = entryWire({
  id: 1,
  title: 'A post worth answering',
  social: { network: 'bluesky', isLiked: false, canLike: true },
  comments: 'auto',
  discussionUrl: 'https://bsky.invalid/post/1',
});

const REPLIES: CommentsResponse = {
  status: 'ok',
  fetchedAt: new Date().toISOString(),
  omitted: 3,
  comments: [
    anEntryComment({
      author: 'Ada Reply',
      html: '<p>The first reply.</p>',
      depth: 0,
      media: [{ url: 'https://media.invalid/photo.png', kind: 'image', width: 800, height: 600 }],
    }),
    anEntryComment({ author: 'Bob Answer', html: '<p>An answer to the reply.</p>', depth: 1 }),
    anEntryComment({
      author: 'Cy Looper',
      html: '<p>A reply with a GIF.</p>',
      depth: 0,
      media: [
        {
          url: 'https://media.invalid/loop.mp4',
          kind: 'video',
          animated: true,
          width: 400,
          height: 300,
          previewImageUrl: 'https://media.invalid/loop.png',
        },
      ],
    }),
  ],
};

async function stubPostWithReplies(
  page: Page,
  { replies = REPLIES, body = '<p>The post itself.</p>' } = {},
): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await page.route('**/api/client-errors', (route) => route.fulfill({ status: 204 }));
  await page.route('https://media.invalid/**', (route) => route.fulfill({ status: 404 }));
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [POST], nextCursor: null } }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1',
    (route) =>
      route.request().method() === 'GET'
        ? route.fulfill({ json: entryDetailJson(POST, body) })
        : route.fallback(),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/reader',
    (route) => route.fulfill({ json: readerFailedJson() }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/social',
    (route) => route.fulfill({ json: { likes: 4, reposts: 1, replies: 6, quotes: 0 } }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1/comments',
    (route) => route.fulfill({ json: replies }),
  );
}

test('the replies render indented, with media, a GIF, their age and a refresh', async ({
  page,
}) => {
  await stubPostWithReplies(page);
  await page.setViewportSize({ width: 1280, height: 800 });
  await presetLocalStorage(page, { 'sfr.layout': 'pane' });
  await page.goto('/?subscription=1');
  await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
  const comments = page.locator('app-entry-comments');
  await comments.scrollIntoViewIfNeeded();

  const items = comments.locator('li.comment');
  await expect(items).toHaveCount(3);
  const reply = items.filter({ hasText: 'The first reply.' });
  const answer = items.filter({ hasText: 'An answer to the reply.' });
  const replyBox = (await reply.locator('.body').boundingBox())!;
  const answerBox = (await answer.locator('.body').boundingBox())!;
  expect(answerBox.x).toBeGreaterThan(replyBox.x);

  await expect(reply.locator('app-post-media img')).toHaveCount(1);
  await expect(items.filter({ hasText: 'A reply with a GIF.' }).locator('app-gif')).toHaveCount(1);
  await expect(comments.locator('app-link-card')).toHaveCount(0);

  await expect(comments.locator('.age')).toContainText('Updated');
  await expect(comments.locator('a.all')).toContainText('3 more on Bluesky');

  const commentsRefetch = page.waitForRequest((request) =>
    request.url().includes('/api/entries/1/comments?refresh=1'),
  );
  const socialRefetch = page.waitForRequest((request) =>
    request.url().includes('/api/entries/1/social?refresh=1'),
  );
  await comments.getByRole('button', { name: 'Reload comments' }).click();
  await Promise.all([commentsRefetch, socialRefetch]);
});

const REPLY_CLIP = 'https://media.invalid/reply.mp4';
const POST_CLIP = 'https://media.invalid/post.mp4';

const REPLY_WITH_VIDEO: CommentsResponse = {
  ...REPLIES,
  comments: [
    anEntryComment({
      author: 'Vi Deo',
      html: '<p>A reply with a video.</p>',
      depth: 0,
      media: [{ url: REPLY_CLIP, kind: 'video', width: 640, height: 360 }],
    }),
  ],
};

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

const plays = (page: Page) => page.evaluate(() => (window as unknown as { plays: Play[] }).plays);

/** A play or a pause the recorded `play()` never fires on its own. */
async function report(page: Page, selector: string, playing: boolean): Promise<void> {
  await page.locator(selector).evaluate((medium, isPlaying) => {
    Object.defineProperty(medium, 'paused', { configurable: true, value: !isPlaying });
    medium.dispatchEvent(new Event(isPlaying ? 'play' : 'pause'));
  }, playing);
}

async function openPostWithReplyVideo(page: Page): Promise<string[]> {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(error.message));
  await recordPlays(page);
  await stubPostWithReplies(page, {
    replies: REPLY_WITH_VIDEO,
    body: `<p>The post itself.</p><video controls preload="none" src="${POST_CLIP}"></video>`,
  });
  await page.setViewportSize({ width: 1280, height: 800 });
  await presetLocalStorage(page, { 'sfr.layout': 'pane' });
  await page.goto('/?subscription=1');
  await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
  return errors;
}

test('a reply video autoplays muted once it scrolls into view', async ({ page }) => {
  const errors = await openPostWithReplyVideo(page);

  await page.locator('app-entry-comments video').scrollIntoViewIfNeeded();

  await expect.poll(() => plays(page)).toContainEqual({ src: REPLY_CLIP, muted: true });
  expect(errors.filter((error) => error.includes('NG0'))).toEqual([]);
});

test("the post's own video holds reply autoplay until it stops", async ({ page }) => {
  await openPostWithReplyVideo(page);
  await expect(page.locator('.content video')).toHaveCount(1);
  await report(page, '.content video', true);

  await page.locator('app-entry-comments video').scrollIntoViewIfNeeded();
  await page.waitForTimeout(500);
  expect((await plays(page)).map((play) => play.src)).not.toContain(REPLY_CLIP);

  await report(page, '.content video', false);
  await expect.poll(() => plays(page)).toContainEqual({ src: REPLY_CLIP, muted: true });
});
