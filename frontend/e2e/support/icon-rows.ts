import { expect, Page } from '@playwright/test';
import { CommentsResponse } from '../../src/app/reader/entry-comment';
import type { EntryDto, SubscriptionDto } from '../../src/app/reader/models';
import type {
  NotificationActorDto,
  NotificationGroupDto,
} from '../../src/app/reader/notifications/notification.models';
import type { TrendingResponseDto } from '../../src/app/reader/trending/trending-page';
import {
  aCommentPost,
  aLinkCard,
  anEntryComment,
  aStory,
  aStoryArticle,
} from '../../src/testing/entry-testing';
import { makeTag } from '../../src/app/reader/testing/tag.factory';
import { DESKTOP, Surface } from './first-line';
import { stubImageHost } from './images';
import {
  entryDetailJson,
  entryWire,
  oneFeedJson,
  readerFailedJson,
  stubOneFeedReader,
  subscriptionsJson,
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

/** The post with a score, so its article carries a vote pill. */
export const SCORED_POST = { ...POST, score: 22, myVote: null };

const POST_BODY = '<p>The post itself.</p>';

export const COMMENTS: CommentsResponse = {
  status: 'ok',
  fetchedAt: new Date().toISOString(),
  omitted: 0,
  comments: [
    anEntryComment({
      author: 'Own Reply',
      authorAvatarUrl: AVATAR,
      html: '<p>A reply the reader may delete.</p>',
      post: aCommentPost({ isOwn: true }),
    }),
    anEntryComment({
      author: 'Lemmy Voter',
      authorAvatarUrl: AVATAR,
      html: '<p>A comment the reader votes on.</p>',
      score: 7,
      vote: { address: 'https://lemmy.invalid/comment/1', myVote: 0, canReply: true },
    }),
    anEntryComment({
      author: 'Scored Comment',
      html: '<p>A comment with a score.</p>',
      score: 3,
      linkCard: aLinkCard({ imageUrl: `https://${ICON_HOST}/card.png` }),
    }),
  ],
};

async function stubFeedReader(page: Page, feed: Partial<SubscriptionDto>): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  await stubImageHost(page, ICON_HOST);
  const subscriptions = oneFeedJson('Fixture feed', feed);
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: subscriptions }),
  );
  if (feed.tags) {
    await page.route(/\/api\/tags(?:[?#]|$)/, (route) =>
      route.fulfill({ json: { tags: feed.tags } }),
    );
  }
}

/** `feed` reshapes the one feed, e.g. to give its entries tag pills; its tags are also the tag list. */
export async function stubMagazine(
  page: Page,
  entries: EntryDto[],
  feed: Partial<SubscriptionDto> = {},
): Promise<void> {
  await stubFeedReader(page, feed);
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries, nextCursor: null } }),
  );
}

interface ArticleStub {
  post?: EntryDto;
  body?: string;
  feed?: Partial<SubscriptionDto>;
}

/** `post` replaces the one entry with id 1, e.g. to give it a score; `feed` is as for `stubMagazine`. */
export async function stubArticle(
  page: Page,
  { post = POST, body = POST_BODY, feed = {} }: ArticleStub = {},
): Promise<void> {
  await stubFeedReader(page, feed);
  await page.route('**/api/client-errors', (route) => route.fulfill({ status: 204 }));
  await page.route(
    (url) => url.pathname === '/api/entries',
    (route) => route.fulfill({ json: { entries: [post], nextCursor: null } }),
  );
  await page.route(
    (url) => url.pathname === '/api/entries/1',
    (route) =>
      route.request().method() === 'GET'
        ? route.fulfill({ json: entryDetailJson(post, body) })
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

/** Opens the post from the list, once its three comments are in. */
export async function openArticle(page: Page): Promise<void> {
  await page.locator('app-entry-list').getByText(POST.title, { exact: true }).click();
  await expect(page.locator('app-entry-comments li.comment')).toHaveCount(3);
}

function notificationActor(displayName: string, handle: string): NotificationActorDto {
  return { handle, displayName, avatarUrl: AVATAR, profileUrl: `https://bsky.invalid/${handle}` };
}

export const NOTIFICATION: NotificationGroupDto = {
  id: 1,
  kind: 'reply',
  account: '@reader.bsky.social',
  actors: [notificationActor('Chris Weber', 'chris.example')],
  actorCount: 1,
  excerpt: 'Does it count a wire story once when five outlets reprint it?',
  answeredExcerpt: null,
  threadTitle: null,
  threadPlace: null,
  threadBy: null,
  onComment: false,
  linkUrl: 'https://bsky.invalid/post/1',
  entryId: null,
  latestAt: '2026-11-16T10:00:00+00:00',
  seen: true,
};

/** One Bluesky notification whose actor has an avatar; open `/?view=notifications&network=bluesky`. */
export async function stubNotifications(page: Page): Promise<void> {
  await stubFeedReader(page, {});
  await page.route(/\/api\/me\/notifications(?:[?#]|$)/, (route) =>
    route.fulfill({
      json: { groups: [NOTIFICATION], nextCursor: null, scopeMissing: [], needsReconnect: [] },
    }),
  );
  await page.route('**/api/me/notifications/seen', (route) => route.fulfill({ status: 204 }));
}

export const STORY_TITLE = 'A story five outlets ran';

const TRENDING: TrendingResponseDto = {
  window: 'day',
  builtAt: '2026-11-18T08:00:00Z',
  stories: [
    aStory({
      id: 601,
      title: STORY_TITLE,
      outletCount: 3,
      articles: [601, 602, 603].map((id) =>
        aStoryArticle({
          id,
          title: `Outlet article ${id}`,
          source: `Outlet ${id}`,
          faviconUrl: FAVICON,
        }),
      ),
    }),
  ],
};

/** One Trending story with three outlets; open `/?view=trending&window=day` and expand its sources. */
export async function stubTrending(page: Page): Promise<void> {
  await stubFeedReader(page, {});
  await page.route(
    (url) => url.pathname === '/api/trending',
    (route) => route.fulfill({ json: TRENDING }),
  );
  await page.route(
    (url) => url.pathname === '/api/trending/followed',
    (route) => route.fulfill({ json: { stories: [], nextBuildDueAt: null } }),
  );
}

export const TAGS = [
  makeTag(7, 'Politics', { icon: 'home' }),
  makeTag(8, 'Science', { icon: 'science' }),
];
export const ONE_ENTRY = [entryWire({ id: 1, title: 'One', source: 'Heise' })];

export const SCORED_ARTICLE: Surface = {
  viewport: DESKTOP,
  stub: (page) => stubArticle(page, { post: SCORED_POST, feed: { tags: TAGS } }),
  path: '/?subscription=1',
};
export const ORGANISE: Surface = {
  viewport: DESKTOP,
  stub: stubTaggedMagazine,
  path: '/settings/organise',
};

export async function stubTaggedMagazine(page: Page): Promise<void> {
  await stubMagazine(page, ONE_ENTRY, { tags: TAGS });
}

export const MAGAZINE: Surface = {
  viewport: DESKTOP,
  stub: stubTaggedMagazine,
  path: '/?subscription=1',
};

export const COUNTED_ENTRY = entryWire({
  id: 1,
  title: 'One',
  source: 'Heise',
  score: 22,
  myVote: null,
  commentCount: 6,
  likeCount: 4,
  social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
});

/** The edited feed carries no tags and a newer one carries TAGS, so the picker suggests them. */
export async function stubSuggestedTags(page: Page): Promise<void> {
  await stubMagazine(page, ONE_ENTRY, { tags: [] });
  const [edited] = oneFeedJson('Fixture feed').subscriptions;
  const donor = { ...edited, id: 2, feedId: 2, title: 'Donor feed', tags: TAGS };
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: subscriptionsJson([edited, donor]) }),
  );
  await page.route(/\/api\/tags(?:[?#]|$)/, (route) => route.fulfill({ json: { tags: TAGS } }));
}

export async function openTagPicker(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Edit feed' }).first().click();
}
