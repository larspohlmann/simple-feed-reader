// src/app/reader/models.ts
export interface TagDto {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
  /** The tag's order in the sidebar list (ascending). */
  position: number;
}

/** A tag as embedded on a subscription: same shape as TagDto, but `position` is
 *  THIS feed's order within that tag (the join position), not the tag's own
 *  sidebar order. */
export interface SubscriptionTagDto {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
  position: number;
}

export interface SubscriptionDto {
  id: number;
  title: string;
  customTitle: string | null;
  feedUrl: string;
  siteUrl: string | null;
  status: 'active' | 'erroring' | 'gone';
  createdAt: string;
  /** The feed's order in the untagged "Feeds" list (ascending). */
  position: number;
  tags: SubscriptionTagDto[];
  unreadCount: number;
}

export interface EntryDto {
  id: number;
  title: string;
  url: string | null;
  author: string | null;
  summary: string | null;
  contentHtml: string | null;
  publishedAt: string | null;
  createdAt: string;
  subscriptionId: number;
  source: string;
  isRead: boolean;
  isFavorite: boolean;
  isKept: boolean;
}

export interface EntriesPage {
  entries: EntryDto[];
  nextCursor: string | null;
}

export interface EntryStateDto {
  entryId: number;
  isRead: boolean;
  isFavorite: boolean;
  isKept: boolean;
  readAt: string | null;
}

export interface RefreshReport {
  status: 'busy' | 'partial' | 'completed' | 'aborted';
  total: number;
  fetched: number;
  notModified: number;
  failed: number;
  skippedForBudget: number;
  remaining: number;
  pruned: number;
}

/** A candidate feed returned by POST /subscriptions when the URL was an HTML page. */
export interface FeedCandidate {
  url: string;
  title: string;
}

/** POST /subscriptions returns either the created subscription or a candidate list. */
export type SubscribeResult = { subscription: SubscriptionDto } | { candidates: FeedCandidate[] };

export type EntryView = 'all' | 'unread' | 'favorites' | 'kept';

/** A resolved selection the entry list turns into query params. */
export interface EntryQuery {
  view: EntryView;
  subscription?: number;
  tag?: number;
}

export type MarkReadScope = 'all' | 'feed' | 'tag';

export interface EntryStatePatch {
  isRead?: boolean;
  isFavorite?: boolean;
  isKept?: boolean;
}

export interface OpmlImportResult {
  imported: number;
  alreadySubscribed: number;
  invalid: number;
  skippedOverLimit: number;
}

/** Body for POST /api/tags and PATCH /api/tags/{id}. */
export interface TagInput {
  name: string;
  color: string | null;
  icon: string | null;
}

/** Body for PATCH /api/subscriptions/{id}. Replaces the whole tag set. */
export interface SubscriptionUpdate {
  customTitle: string | null;
  tagIds: number[];
}

/** A successfully extracted reader-mode article (GET /api/entries/{id}/reader). */
export interface ReaderArticle {
  status: 'ok';
  url: string;
  title: string;
  byline: string | null;
  siteName: string | null;
  contentHtml: string;
  excerpt: string | null;
  /** Lead image to show as a hero when the body has none of its own; else null. */
  leadImage: string | null;
  extractedAt: string;
}

/** Extraction could not produce an article; the client falls back to feed content. */
export interface ReaderFailure {
  status: 'failed';
  url: string | null;
  reason: 'no_url' | 'fetch' | 'unextractable' | 'empty';
}

export type ReaderContent = ReaderArticle | ReaderFailure;
