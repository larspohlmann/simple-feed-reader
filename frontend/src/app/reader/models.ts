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
  /** When the feed was last successfully fetched (ISO), or null if never. Powers
   *  the list header's "Last refreshed" hint for a single-feed selection. */
  lastFetchedAt: string | null;
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
  /** Absolute image URL the feed supplied, or null. Persisted server-side. */
  imageUrl: string | null;
  /** Dimensions AS DECLARED by the feed. Null means unknown, not square. */
  imageWidth: number | null;
  imageHeight: number | null;
  publishedAt: string | null;
  createdAt: string;
  subscriptionId: number;
  source: string;
  /** Absolute https favicon URL for the entry's feed, or null if unresolved. */
  faviconUrl: string | null;
  isRead: boolean;
  isFavorite: boolean;
  isKept: boolean;
  /** One-way: the user actively opened this entry at least once (#307). */
  isViewed: boolean;
  /** Why the recommender picked this entry; set only on for-you results. */
  recommendationReason?: string | null;
  /** The model's 0-100 score for this entry; present only on for-you results
   *  and only when the user's debug setting is on. */
  recommendationScore?: number | null;
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
  isViewed: boolean;
  viewedAt: string | null;
}

export interface RefreshReport {
  status: 'busy' | 'partial' | 'completed' | 'aborted';
  total: number;
  fetched: number;
  notModified: number;
  failed: number;
  /** Feeds the site rationed. Healthy, and asked again shortly — not failures. */
  throttled: number;
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
export type ScrapeFailureReason =
  'blocked' | 'throttled' | 'unreachable' | 'not_scrapable' | (string & {});

/** POST /subscriptions returns either the created subscription or a candidate
 *  list; an empty list may carry the reason the scraper fallback gave up. */
export type SubscribeResult =
  | { subscription: SubscriptionDto }
  | { candidates: FeedCandidate[]; scrapeFailureReason?: ScrapeFailureReason };

export type EntryView = 'all' | 'unread' | 'favorites' | 'kept' | 'for-you';

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
  isViewed?: boolean;
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

/** Progress of a for-you recommendation run (POST/GET /api/recommendations/runs*). */
export interface RecommendationRunReport {
  status: 'none' | 'pending' | 'running' | 'completed' | 'cancelled' | 'failed';
  batchesTotal: number | null;
  batchesDone: number;
  error: string | null;
  /** True when a live worker owns execution and a tick is a pure status read;
   *  false when the client's own poll loop is doing the work (#308 regime). */
  background: boolean;
  /** Bytes of the in-flight provider answer received so far this call; 0
   *  between calls, since the server resets the counter when a call ends. */
  streamedChars: number;
  /** The surviving for-you list's own summary: how many entries it holds and
   *  when it was last generated. Describes the *list*, not this run — a
   *  failed latest run still carries the previous list's timestamp. */
  forYou: { itemCount: number; generatedAt: string | null };
}

/** One provider call logged during a for-you run: a scored batch or the
 *  final dedup pass. `verdict` is null while the call is still streaming. */
export interface DebugLogEntry {
  id: number;
  phase: 'batch' | 'dedup';
  batchNumber: number | null;
  attempt: number;
  verdict: 'usable' | 'unusable' | 'transport-failed' | null;
  requestBytes: number;
  responseBytes: number;
  /** Everything the provider sent, reasoning and framing included. */
  wireBytes: number;
  streamingText: string | null;
  createdAt: string;
  /** Null while the call is still streaming; set the moment it settles. */
  finishedAt: string | null;
  /** The transport exception's message, set only on a `transport-failed`
   *  verdict -- null on every other row, including a completed run. */
  errorDetail: string | null;
}

/** The latest for-you run, as the debug log's summary strip shows it: distinct
 *  from the per-row `errorDetail` above, `error` here is the run's own
 *  failure, not any one call's. Null when the user has never run. */
export interface DebugLogRunSummary {
  status: 'pending' | 'running' | 'completed' | 'failed';
  error: string | null;
  attempts: number;
  maxAttempts: number;
  transportFailures: number;
  maxTransportFailures: number;
  createdAt: string;
  completedAt: string | null;
}

/** The full request/response pair for one logged provider call. */
export interface DebugLogDetail {
  id: number;
  phase: 'batch' | 'dedup';
  batchNumber: number | null;
  attempt: number;
  verdict: string | null;
  requestBody: string;
  responseText: string;
  wireBytes: number;
}
