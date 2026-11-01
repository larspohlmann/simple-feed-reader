import { EntryCommentDto, EntryCommentPostDto } from '../app/reader/entry-comment';
import { EntryDto } from '../app/reader/models';
import { QuotedPostDto } from '../app/reader/quoted-post/quoted-post';
import { LinkCardDto } from '../app/reader/link-card/link-card';

/** A plain list entry: no image, no summary, nothing marked; `overrides` name what a test is about. */
export function anEntry(overrides: Partial<EntryDto> = {}): EntryDto {
  return {
    id: 1,
    title: 'A headline',
    url: null,
    author: null,
    summary: null,
    excerpt: '',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    imageAspectRatio: null,
    imageRenditions: [],
    media: [],
    attachments: [],
    categories: [],
    publishedAt: null,
    createdAt: '2026-08-01T12:00:00Z',
    subscriptionId: 1,
    source: 'A feed',
    poster: null,
    faviconUrl: null,
    isHidden: false,
    isFavorite: false,
    isKept: false,
    isViewed: false,
    shortPlayerUrl: null,
    titleDerived: false,
    share: null,
    linkCards: [],
    thread: [],
    quote: null,
    pending: false,
    social: null,
    discussionUrl: null,
    comments: null,
    ...overrides,
  };
}

export function aQuotedPost(overrides: Partial<QuotedPostDto> = {}): QuotedPostDto {
  return {
    url: 'https://bsky.app/profile/quoted.bsky.social/post/3kq',
    authorName: 'Quoted Person',
    authorHandle: 'quoted.bsky.social',
    authorAvatarUrl: null,
    postedAt: '2026-10-13T21:07:00+00:00',
    text: 'Quoted text.',
    media: [],
    nestedQuote: null,
    ...overrides,
  };
}

export const A_READER_TOKEN = 'a'.repeat(43);

/** A shared link's preview card with no description and no image. */
export function aLinkCard(overrides: Partial<LinkCardDto> = {}): LinkCardDto {
  return {
    url: 'https://news.test/story',
    title: 'A shared story',
    description: null,
    imageUrl: null,
    siteName: 'news.test',
    readerToken: A_READER_TOKEN,
    ...overrides,
  };
}

export function aCommentPost(overrides: Partial<EntryCommentPostDto> = {}): EntryCommentPostDto {
  return {
    address: 'at://did:plc:ann/app.bsky.feed.post/2',
    counts: { likes: 0, reposts: 0, replies: 0, quotes: null },
    isLiked: false,
    isReposted: false,
    canRepost: true,
    canReply: true,
    canQuote: true,
    isOwn: false,
    ...overrides,
  };
}

export function anEntryComment(overrides: Partial<EntryCommentDto> = {}): EntryCommentDto {
  return {
    author: null,
    authorHandle: null,
    authorUrl: null,
    authorAvatarUrl: null,
    url: null,
    publishedAt: null,
    html: '<p>x</p>',
    byEntryAuthor: false,
    depth: 0,
    media: [],
    linkCard: null,
    quote: null,
    post: null,
    ...overrides,
  };
}
