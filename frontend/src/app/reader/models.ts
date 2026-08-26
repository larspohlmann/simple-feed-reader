// src/app/reader/models.ts
export interface TagDto {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
  /** The tag's order in the sidebar list (ascending). */
  position: number;
}

/** The sidebar's view of a saved search: the badge reads `unreadCount`. The
 *  store derives it from the wire's id set, dropping an entry the moment it is
 *  read, so the count falls without another round-trip (#645). */
export interface SavedSearchDto {
  id: number;
  /** The trimmed search term (no trailing whole-word space). */
  term: string;
  /** True when the saved search matches whole words only. */
  wholeWord: boolean;
  /** Reserved for a future sidebar reorder; unused in v1. */
  position: number;
  /** Live count of unread entries matching this search. */
  unreadCount: number;
}

/** The API shape of a saved search. It carries the ids of the unread matches
 *  rather than a bare count, so the store can drop one locally on read and
 *  reconcile the whole set on the next load() (#645). */
export interface SavedSearchWire {
  id: number;
  term: string;
  wholeWord: boolean;
  position: number;
  /** The ids of every unread entry that matches this search. */
  unreadEntryIds: number[];
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
  /** The feed's own description, already plain text and capped by the API. */
  description: string | null;
  /** The image the feed publishes for itself (its logo or banner), https-only,
   *  or null. Not `faviconUrl` — that is the site's icon. */
  imageUrl: string | null;
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

/** The sidebar bootstrap payload: the feed list plus the user-wide favourite,
 *  kept and viewed totals shown as badges on the Favorites/Kept/Recently-read
 *  nav items. */
export interface SubscriptionsResponse {
  subscriptions: SubscriptionDto[];
  favoritesCount: number;
  keptCount: number;
  viewedCount: number;
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
  /** The model's 0-1000 score for this entry (0-100 before #403); present on
   *  for-you results whenever the reason is, because one setting sends both
   *  (#576). Null on rows written before the column existed. */
  recommendationScore?: number | null;
  /** The recommendation run this entry belongs to; set only on for-you results.
   *  Consecutive entries with different runIds mark a run boundary (#348). */
  runId?: number;
  /** When that run generated (ISO, RFC 3339); set only on for-you results. Drives
   *  the run-boundary divider's "Generated ..." label (#348). */
  runGeneratedAt?: string;
}

export interface EntriesPage {
  entries: EntryDto[];
  nextCursor: string | null;
  /** The words the search engine actually matched — present only on a search
   *  response, and even there empty whenever the database LIKE fallback
   *  answered (no engine installed, or the engine was momentarily
   *  unreachable). The typo-tolerant engine can match a row where the
   *  literal typed term appears nowhere in it, so highlighting must prefer
   *  this over splitting the typed term. Absent entirely on the plain entry
   *  list, which carries no search at all. */
  matchedWords?: string[];
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
  url: string | null;
  author: string | null;
  summary: string | null;
  imageUrl: string | null;
  imageWidth: number | null;
  imageHeight: number | null;
  publishedAt: string | null;
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

export type EntryView = 'all' | 'unread' | 'favorites' | 'kept' | 'viewed' | 'for-you';

/** A resolved selection the entry list turns into query params. */
export interface EntryQuery {
  view: EntryView;
  subscription?: number;
  tag?: number;
  /** Presence selects the search endpoint instead of the main list. */
  q?: string;
}

/** The scopes `POST /api/entries/mark-read` accepts, each identified by an
 *  optional id. A search is deliberately NOT one of them: it is identified by
 *  a term, travels its own endpoint, and widening this union would let
 *  `ReaderApi.markRead('search', …)` type-check against a request the backend
 *  rejects. `MarkReadTarget` in query.ts is where the two meet. */
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

/** A picture the backend chose to lead the article, with the dimensions its
 *  source declared. Null width/height mean unknown, so no space is reserved. */
export interface HeroImageDto {
  url: string;
  width: number | null;
  height: number | null;
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
  /** The picture to lead the reader view; null when the extracted body has its
   *  own leading image, or repeats it. Resolved server-side (#592). */
  readerHero: HeroImageDto | null;
  /** The picture to lead the original-feed view, resolved against the feed's
   *  own body by the same server-side rule. */
  originalHero: HeroImageDto | null;
  extractedAt: string;
}

/** Extraction could not produce an article; the client falls back to feed content. */
export interface ReaderFailure {
  status: 'failed';
  url: string | null;
  reason: 'no_url' | 'fetch' | 'unextractable' | 'empty';
  /** Always null: a failed extraction has no body to lead. */
  readerHero: null;
  originalHero: HeroImageDto | null;
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
  /** True only when an advance came back busy and the worker-presence read
   *  was stale: a lock held with nobody beating, rather than a live worker
   *  doing the work. Optional so a response cached from an older backend
   *  does not break the type; treat an absent value as false (#439). */
  readonly waitingForLock?: boolean;
  /** Bytes of the in-flight provider answer received so far this call; 0
   *  between calls, since the server resets the counter when a call ends. */
  streamedChars: number;
  /** Whole seconds the run has been going, computed on the server's clock;
   *  null when there is no run. The client keeps it live between polls with a
   *  local monotonic delta rather than re-subtracting server time. */
  elapsedSeconds: number | null;
  /** Whole seconds the run is still expected to need, weighted by phase from
   *  the account's own history and computed on the server (#638). Null when
   *  there is no run in flight or no completed run to learn from yet; the
   *  client shows a blank then, never a guessed number. Optional so a response
   *  cached from an older backend does not break the type — an absent value
   *  reads the same as null. The client ticks it down between polls with a
   *  local monotonic delta, the same way it keeps `elapsedSeconds` live. */
  readonly etaSeconds?: number | null;
  /** The surviving for-you list's own summary: how many entries it holds, when
   *  it was last generated, and the id of the run that generated it. Describes
   *  the *list*, not this run — a failed latest run still carries the previous
   *  list's timestamp and run id. `newestRunId` lets the reader suppress that
   *  run's boundary divider by identity rather than by timestamp (#348). */
  forYou: { itemCount: number; generatedAt: string | null; newestRunId: number | null };
}

/** One provider call logged during a for-you run: a scored batch or the
 *  final dedup pass. `verdict` is null while the call is still streaming. */
export interface DebugLogEntry {
  id: number;
  /** The run this call belongs to. The log can hold more than one run (a
   *  resumed run keeps appending), so the panel groups rows by it. */
  runId: number;
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
  /** Why the provider stopped generating: `length` when `max_tokens` truncated
   *  the answer, `stop` on a natural end. Null until the provider stamps it. */
  finishReason: string | null;
}

/** The latest for-you run, as the debug log's summary strip shows it: distinct
 *  from the per-row `errorDetail` above, `error` here is the run's own
 *  failure, not any one call's. Null when the user has never run. */
/** One run the debug panel may switch to. The log keeps the last ten runs,
 *  and the panel reads one at a time -- shipping all ten on every two-second
 *  poll would cost ten times what the panel costs today. */
export interface DebugLogRunChoice {
  id: number;
  status: 'pending' | 'running' | 'completed' | 'failed';
  createdAt: string;
}

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

/** What the debug panel's list route answers with. */
export interface DebugLogPayload {
  run: DebugLogRunSummary | null;
  runs: DebugLogRunChoice[];
  entries: DebugLogEntry[];
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
  /** Why the provider stopped generating: `length` when `max_tokens` truncated
   *  the answer, `stop` on a natural end. Null until the provider stamps it. */
  finishReason: string | null;
}

/** One finished (or in-flight) for-you run, as the history card shows it. The
 *  provider and model are the ones the run actually called, copied onto the run
 *  when it started -- not the account's current configuration, which is
 *  editable and would otherwise rename last month's runs. */
export interface RunHistoryRow {
  id: number;
  status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
  /** Null on runs that predate the column, and on one that failed before it
   *  was ever stamped. */
  providerHost: string | null;
  model: string | null;
  createdAt: string;
  completedAt: string | null;
  /** Computed server-side -- the client never subtracts timestamps across
   *  machines. Null while the run has not finished. */
  durationSeconds: number | null;
  promptTokens: number;
  completionTokens: number;
  reasoningTokens: number;
  cachedTokens: number;
  /** What the run cost, in nano-credits (1 credit = 1e9). Null means no call
   *  of the run reported a price -- a local model, say -- which is a different
   *  statement from a cost of zero. */
  costNanoCredits: number | null;
}

/** One month of an account's run history, as its section header shows it.
 *  `costNanoCredits` is null when no run of the month reported a price -- the
 *  same distinction a row and the all-time total already make. Counts and
 *  totals are computed over the whole month, not over the rows on screen, so
 *  a capped section never shows a wrong number. */
export interface RunHistoryMonth {
  month: string;
  runCount: number;
  costNanoCredits: number | null;
}

/** One page of one month's runs. `nextCursor` is the id to pass as `before`
 *  for the next page, or null when the month is exhausted. */
export interface RunHistoryMonthPage {
  month: string;
  runs: RunHistoryRow[];
  nextCursor: number | null;
}

/** What the history route answers with: every month the account has runs in,
 *  the newest month's first page so the card paints in one round trip, and the
 *  account's all-time total. `latest` is null when the account has never run. */
export interface RunHistoryOverview {
  totalCostNanoCredits: number | null;
  months: RunHistoryMonth[];
  latest: RunHistoryMonthPage | null;
}

export interface RestoreCounts {
  tags: number;
  feeds: number;
  subscriptions: number;
  entries: number;
  entryStates: number;
}

export interface RestorePreview {
  backup: { createdAt: string; sourceUrl: string | null; sourceEmail: string | null };
  toLoad: RestoreCounts;
  toDelete: {
    tags: number;
    subscriptions: number;
    entryStates: number;
    recommendationRuns: number;
  };
}

export interface RestoreResult {
  loaded: RestoreCounts;
}
