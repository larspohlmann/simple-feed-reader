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
  /** The shared feed's id — the handle for scoping a refresh to this feed. */
  feedId: number;
  title: string;
  /** Absolute https favicon URL for the feed's site, or null if unresolved. */
  faviconUrl: string | null;
  customTitle: string | null;
  feedUrl: string;
  siteUrl: string | null;
  status: 'active' | 'erroring' | 'gone';
  /** Where entries come from: 'xml' (a real RSS/Atom feed) or 'scraped'
   *  (generated from the page's article list) today; stays an open string. */
  sourceFormat: string;
  createdAt: string;
  /** The feed's order in the untagged "Feeds" list (ascending). */
  position: number;
  tags: SubscriptionTagDto[];
  unreadCount: number;
}

/** The sidebar bootstrap payload: the feed list plus the user-wide favourite and
 *  kept totals shown as badges on the Favorites/Kept nav items. */
export interface SubscriptionsResponse {
  subscriptions: SubscriptionDto[];
  favoritesCount: number;
  keptCount: number;
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
  /** Absolute https favicon URL for the entry's feed, or null if unresolved. */
  faviconUrl: string | null;
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
  title: string | null;
  /** The feed's syntax: 'rss' or 'atom' today; a future HTML-scraper source
   *  will add its own value, so this stays an open string. */
  format: string;
}

export interface FeedPreviewItem {
  title: string;
  publishedAt: string | null;
  author: string | null;
  hasImage: boolean;
  textLength: number;
  snippet: string;
}

/** A pre-subscribe preview of a candidate feed's content shape. */
export interface FeedPreview {
  title: string | null;
  itemCount: number;
  content: 'full' | 'summary' | 'title-only';
  hasImages: boolean;
  items: FeedPreviewItem[];
}

/**
 * Why the scraper fallback could not turn an HTML page into a feed. The known
 * reasons are enumerated for editor support, but the type stays an open string:
 * the backend's reason set is open (see the spec's openness note), so a newer
 * server may send a reason this build hasn't heard of. `failureText()` renders a
 * generic warning for anything outside the known set rather than an empty box.
 */
export type ScrapeFailureReason = 'blocked' | 'unreachable' | 'not_scrapable' | (string & {});

/** POST /subscriptions returns either the created subscription or a candidate
 *  list; an empty list may carry the reason the scraper fallback gave up. */
export type SubscribeResult =
  | { subscription: SubscriptionDto }
  | { candidates: FeedCandidate[]; scrapeFailureReason?: ScrapeFailureReason };

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
