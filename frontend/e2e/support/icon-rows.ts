import type { Page } from '@playwright/test';
import { CommentsResponse } from '../../src/app/reader/entry-comment';
import type { EntryDto, SubscriptionDto } from '../../src/app/reader/models';
import { aCommentPost, aLinkCard, anEntryComment } from '../../src/testing/entry-testing';
import { ONE_PIXEL_PNG } from './images';
import {
  entryDetailJson,
  entryWire,
  oneFeedJson,
  readerFailedJson,
  stubOneFeedReader,
} from './reader';

/** Rows of text beside icons, every API read stubbed, so a spec or a script owns what it measures. */
export const ICON_HOST = 'icons.invalid';
export const AVATAR = `https://${ICON_HOST}/avatar.png`;
export const FAVICON = `https://${ICON_HOST}/favicon.png`;

export const POST = entryWire({
  id: 1,
  title: 'A post with comments',
  faviconUrl: FAVICON,
  social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
  comments: 'auto',
  discussionUrl: 'https://bsky.invalid/post/1',
});

const POST_BODY = '<p>The post itself.</p>';

export const COMMENTS: CommentsResponse = {
  status: 'ok',
  fetchedAt: new Date().toISOString(),
  omitted: 0,
  comments: [
    anEntryComment({
      author: 'Own Reply',
      html: '<p>A reply the reader may delete.</p>',
      post: aCommentPost({ isOwn: true }),
    }),
    anEntryComment({
      author: 'Lemmy Voter',
      html: '<p>A comment the reader votes on.</p>',
      score: 7,
      vote: { address: 'https://lemmy.invalid/comment/1', myVote: 0, canReply: true },
    }),
    anEntryComment({
      author: 'Scored Comment',
      html: '<p>A comment with a score.</p>',
      score: 3,
      linkCard: aLinkCard(),
    }),
  ],
};

export async function stubImages(page: Page): Promise<void> {
  await page.route(
    (url) => url.hostname === ICON_HOST,
    (route) => route.fulfill({ status: 200, contentType: 'image/png', body: ONE_PIXEL_PNG }),
  );
}

/** `feed` reshapes the one feed, e.g. to give its entries tag pills. */
export async function stubMagazine(
  page: Page,
  entries: EntryDto[],
  feed: Partial<SubscriptionDto> = {},
): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await stubImages(page);
  if (feed.tags) {
    const subscriptions = oneFeedJson('Fixture feed', feed);
    await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
      route.fulfill({ json: subscriptions }),
    );
    await page.route(/\/api\/tags(?:[?#]|$)/, (route) =>
      route.fulfill({ json: { tags: feed.tags } }),
    );
  }
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries, nextCursor: null } }),
  );
}

export async function stubArticle(page: Page, body: string = POST_BODY): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await stubImages(page);
  await page.route('**/api/client-errors', (route) => route.fulfill({ status: 204 }));
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
    (route) => route.fulfill({ json: COMMENTS }),
  );
}
