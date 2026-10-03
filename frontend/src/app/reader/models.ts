/** One saved search an entry belongs to, as embedded on the entry itself
 *  (#1118). Carries its own `slug` and `term` so a pill needs no lookup
 *  against the sidebar's saved-search list to render or link. */
export interface SavedSearchMembershipDto {
  id: number;
  slug: string;
  term: string;
}

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
  /** Stable URL slug ("<id>-<term slug>"); the reader routes to this search by it. */
  slug: string;
  /** The bare search term — no trailing whole-word space, no wrapping phrase quotes. */
  term: string;
  /** True when the saved search matches whole words only. */
  wholeWord: boolean;
  /** True when the saved search matches one exact phrase (a quoted query). */
  phrase: boolean;
  /** Reserved for a future sidebar reorder; unused in v1. */
  position: number;
  /** Live count of unread entries matching this search. */
  unreadCount: number;
  /** Total entries matching this search, unread or not. */
  memberCount: number;
  /** True when this saved search's matches are included in the email digest. */
  includeInDigest: boolean;
}

/** The API shape of a saved search. It carries the ids of the unread matches
 *  rather than a bare count, so the store can drop one locally on read and
 *  reconcile the whole set on the next load() (#645). */
export interface SavedSearchWire {
  id: number;
  slug: string;
  term: string;
  wholeWord: boolean;
  phrase: boolean;
  position: number;
  /** The ids of every unread entry that matches this search. */
  unreadEntryIds: number[];
  /** Total entries matching this search, unread or not. */
  memberCount: number;
  /** True when this saved search's matches are included in the email digest. */
  includeInDigest: boolean;
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
  /** When the feed last delivered content (ISO), or null if it never has.
   *  With `status` and `consecutiveFailures`, powers the unhealthy-feeds list. */
  lastSuccessfulFetchAt: string | null;
  /** When a fetch last brought back NEW entries (ISO), or null if none ever
   *  have — distinct from `lastSuccessfulFetchAt`, which advances on every 200.
   *  The Organise row's "Updated" and its sort read this. */
  lastNewContentAt: string | null;
  /** When the scheduler may next fetch the feed (ISO), or null when there is no
   *  next run — a `gone` feed. The Organise row shows it and sorts by it. */
  nextFetchAt: string | null;
  /** The feed's current failure streak; 0 when healthy. */
  consecutiveFailures: number;
  /** The raw fetcher error for the last failed attempt, or null. Untranslated,
   *  capped at 1000 chars by the API — shown only in the health-details panel. */
  lastErrorMessage: string | null;
  /** The feed's order in the untagged "Feeds" list (ascending). */
  position: number;
  tags: SubscriptionTagDto[];
  unreadCount: number;
  /** Total entries this feed holds, unread or not. */
  entryCount: number;
  /** False excludes this feed's unread from the All-items badge and list. */
  includeInAllItems: boolean;
  /** False excludes this feed's entries from For-you recommendations. */
  includeInForYou: boolean;
}

/** True when a CDK drag's payload is a feed row, duck-typed on `feedUrl` (the
 *  field only `SubscriptionDto` carries) rather than some other draggable a
 *  shared drop list accepts. Shared by the sidebar and Organise page. */
export function isSubscriptionDrag(data: unknown): data is SubscriptionDto {
  return !!data && typeof data === 'object' && 'feedUrl' in data;
}

/** True when a CDK drag's payload is a tag header — duck-typed on `color`, the
 *  only field `TagDto` carries among this app's draggables. Lets a drop list that
 *  also accepts feed rows (Organise's tag header, #659) tell the drags apart. */
export function isTagDrag(data: unknown): data is TagDto {
  return !!data && typeof data === 'object' && 'color' in data && !isSubscriptionDrag(data);
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

/** The sidebar poll's cheap payload (#720): unread and entry counts per feed
 *  plus the three surface totals, without the feeds, tags or descriptions the
 *  full list carries. A feed absent from `subscriptions` has no entries. */
export interface SubscriptionCountsResponse {
  subscriptions: { id: number; unreadCount: number; entryCount: number }[];
  favoritesCount: number;
  keptCount: number;
  viewedCount: number;
}

/** One visual media item the feed declared for an entry (#906). */
export interface EntryMediumDto {
  url: string;
  kind: 'image' | 'video';
  width?: number;
  height?: number;
  previewImageUrl?: string;
}

/** One playable or downloadable enclosure the feed declared (#906). */
export interface EntryAttachmentDto {
  url: string;
  mimeType?: string;
  durationInSeconds?: number;
  sizeInBytes?: number;
  title?: string;
}

/** One declared-width rendition of an entry's lead picture (#1330). */
export interface ImageRenditionDto {
  url: string;
  width: number;
}

export type CommentsLoad = 'auto' | 'manual';

export interface EntryCommentDto {
  author: string | null;
  authorUrl: string | null;
  url: string | null;
  publishedAt: string | null;
  html: string;
  byEntryAuthor: boolean;
}

export type CommentsResponse =
  | { status: 'ok'; comments: EntryCommentDto[] }
  | { status: 'throttled'; retryAfter: number }
  | { status: 'failed' };

export interface EntryDto {
  id: number;
  title: string;
  url: string | null;
  author: string | null;
  summary: string | null;
  /** Plain-text dek the server derives from the summary or the body — never
   *  null, unlike them (#1100). */
  excerpt: string;
  /** Absolute image URL the feed supplied, or null. Persisted server-side. */
  imageUrl: string | null;
  /** Dimensions AS DECLARED by the feed. Null means unknown, not square. */
  imageWidth: number | null;
  imageHeight: number | null;
  /** The same picture at each width the feed declared, for `srcset` (#1330): one rendition
   *  per URL and per width, narrowest first; empty when the feed declared no ladder. */
  imageRenditions: ImageRenditionDto[];
  /** Visual media the feed declared, lead image first (#906). Always sent by
   *  the API, empty when the feed declared none. Dimensions are as declared;
   *  missing fields are absent. No view consumes it yet. */
  media: EntryMediumDto[];
  /** Playable or downloadable enclosures the feed declared — podcast audio,
   *  video, other files (#906). Always sent by the API, empty when none. */
  attachments: EntryAttachmentDto[];
  /** Feed-declared category labels, in declared order (#953). Always sent by
   *  the API, empty when the feed declared none. */
  categories: string[];
  publishedAt: string | null;
  createdAt: string;
  subscriptionId: number;
  source: string;
  /** Absolute https favicon URL for the entry's feed, or null if unresolved. */
  faviconUrl: string | null;
  isHidden: boolean;
  isFavorite: boolean;
  isKept: boolean;
  /** One-way: the user actively opened this entry at least once (#307). */
  isViewed: boolean;
  /** The entry's discussion page — Reddit thread, HN item — or null. */
  discussionUrl: string | null;
  /** Whether the entry has a comments feed, and whether it loads without a click. */
  comments: CommentsLoad | null;
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
  /** Owned saved searches this entry is a member of, in sidebar order. Always
   *  sent by the API; empty when the entry matches none. Drives the pills. */
  savedSearches?: SavedSearchMembershipDto[];
  /** Other copies of this article the reader also subscribes to, in this
   *  list's scope. Set by the API's collapse; empty for a non-duplicated row. */
  duplicates?: EntryDto[];
}

/** The `/api/entries/{id}` detail shape: every list field plus the full body,
 *  which the list endpoints omit to keep pages small (#1100). */
export interface EntryDetailDto extends EntryDto {
  contentHtml: string | null;
}

export interface EntriesPage {
  entries: EntryDto[];
  nextCursor: string | null;
  /** Words the search engine actually matched — present only on a search response,
   *  empty when the LIKE fallback answered instead (no engine, or it was momentarily
   *  unreachable). The typo-tolerant engine can match rows the literal term never
   *  appears in, so highlighting must prefer this over splitting the typed term. */
  matchedWords?: string[];
}

export interface EntryStateDto {
  entryId: number;
  isHidden: boolean;
  isFavorite: boolean;
  isKept: boolean;
  hiddenAt: string | null;
  isViewed: boolean;
  viewedAt: string | null;
}

/** How far a refresh RUN has got, straight from the server.
 *
 *  Run-wide, across every slice — which is why no client computes it. A slice's own
 *  counters describe that slice, and only the server sees the run (#721). */
export interface RefreshProgress {
  /** Feeds this run has taken to an outcome. */
  done: number;
  /** What the run has to do: `done` plus what is still due. */
  total: number;
}

export interface RefreshReport {
  status: 'busy' | 'partial' | 'completed' | 'aborted';
  progress: RefreshProgress;
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
 * Why the scraper fallback could not turn an HTML page into a feed. Enumerated for
 * editor support, but stays an open string since the backend's reason set is open —
 * `failureText()` renders a generic warning for anything outside the known set.
 */
export type ScrapeFailureReason =
  'blocked' | 'throttled' | 'unreachable' | 'not_scrapable' | (string & {});

/** POST /subscriptions returns either the created subscription or a candidate
 *  list; an empty list may carry the reason the scraper fallback gave up. */
export type SubscribeResult =
  | { subscription: SubscriptionDto }
  | { candidates: FeedCandidate[]; scrapeFailureReason?: ScrapeFailureReason };

export type EntryView =
  'all' | 'unread' | 'favorites' | 'kept' | 'viewed' | 'for-you' | 'saved-searches';

export type ListOrder = 'newest' | 'oldest';

/** A resolved selection the entry list turns into query params. */
export interface EntryQuery {
  view: EntryView;
  subscription?: number;
  tag?: number;
  /** Views that cannot express "only unread" through `view=unread` carry the
   *  refinement as a flag beside their own view or search term. */
  unread?: boolean;
  /** Presence selects the search endpoint instead of the main list. */
  q?: string;
  /** Set only for a single saved search: fetch its members from the membership table. */
  savedSearchId?: number;
  /** Absent means newest first, the API's own default. */
  order?: ListOrder;
}

/** The scopes `POST /api/entries/mark-read` accepts, each identified by an
 *  optional id. A search is deliberately NOT one of them: it has its own
 *  endpoint, and widening this union would let `markRead('search', …)`
 *  type-check against a request the backend rejects. See `MarkReadTarget` in query.ts. */
export type MarkReadScope = 'all' | 'feed' | 'tag';

export interface EntryStatePatch {
  isHidden?: boolean;
  isFavorite?: boolean;
  isKept?: boolean;
  isViewed?: boolean;
}

/** Body for POST /api/tags and PATCH /api/tags/{id}. */
export interface TagInput {
  name: string;
  color: string | null;
  icon: string | null;
}

/** Body for PATCH /api/subscriptions/{id}. Replaces the whole tag set. Omitted
 *  flags leave the server's current value unchanged. */
export interface SubscriptionUpdate {
  customTitle: string | null;
  tagIds: number[];
  includeInAllItems?: boolean;
  includeInForYou?: boolean;
}

/** A drag of one feed between the sidebar's lists: out of `fromTagId` and into
 *  `toTagId` at `position`. A null tag id is the untagged "Feeds" list; a null
 *  position appends. */
export interface MoveFeedToTag {
  fromTagId: number | null;
  toTagId: number | null;
  position: number | null;
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
  /** True when contentHtml is the free preview of a paywalled article (#785). */
  paywalled: boolean;
  /** The picture to lead the original-feed view, resolved against the feed's own
   *  body server-side. The reader view needs no field of its own: its lead
   *  picture is restored into contentHtml during extraction (#681). */
  originalHero: HeroImageDto | null;
  extractedAt: string;
}

/** Extraction could not produce an article; the client falls back to feed content. */
export interface ReaderFailure {
  status: 'failed';
  url: string | null;
  reason: 'no_url' | 'fetch' | 'unextractable' | 'empty' | 'mismatch';
  /** The underlying cause in words when one exists — a fetch carries the HTTP
   *  status or transport message; a reason with no such cause is null. */
  detail: string | null;
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
  /** True only when an advance came back busy and the worker-presence read was
   *  stale: a lock held with nobody beating. Optional for older-backend
   *  responses; treat an absent value as false (#439). */
  readonly waitingForLock?: boolean;
  /** True while a pending run waits for its profile to be built. Optional for older-backend responses. */
  readonly waitingForProfile?: boolean;
  /** True when a failed run would continue on resume; a run that failed before its snapshot, or a Jev
   *  run without a profile, would not. Optional for older-backend responses. */
  readonly resumable?: boolean;
  /** Bytes of the in-flight provider answer received so far this call; 0
   *  between calls, since the server resets the counter when a call ends. */
  streamedChars: number;
  /** True once the first scored batch request has started. The ETA and its
   * time-based bar stay in their honest-start state until this signal arrives
   * from the server (#668). */
  firstBatchStarted?: boolean;
  /** Whole seconds the run has been going, computed on the server's clock;
   *  null when there is no run. The client keeps it live between polls with a
   *  local monotonic delta rather than re-subtracting server time. */
  elapsedSeconds: number | null;
  /** Whole seconds the run is still expected to need, phase-weighted from the
   *  account's history, computed server-side (#638). Null with no run, or no
   *  history to learn from yet — the client shows a blank, never a guess.
   *  Optional for older-backend responses; ticks down locally like `elapsedSeconds`. */
  readonly etaSeconds?: number | null;
  /** The finished batches' share of the predicted run time, phase-weighted like the ETA so a long last step keeps
   *  its weight. Null without history; optional for older-backend responses. */
  readonly finishedShare?: number | null;
  /** The surviving for-you list's own summary: unread count (#724), last-generated
   *  time, and the generating run. `itemCount` keeps its wire name. Describes the
   *  *list* not this run — a failed run still carries the previous list's data;
   *  `newestRunId` lets the reader suppress that run's divider by identity (#348). */
  forYou: {
    itemCount: number;
    totalCount: number;
    generatedAt: string | null;
    newestRunId: number | null;
  };
}

/** The two per-feed inclusion switches, as a bulk change. An omitted field
 *  leaves the stored value alone — the API's null-means-unchanged convention. */
export interface SubscriptionFlags {
  includeInAllItems?: boolean;
  includeInForYou?: boolean;
}

/** One bulk change across many feeds. At most one tag is added and at most one
 *  removed per request; the page never needs more, and a single tag keeps the
 *  confirmation text exact. */
export interface BulkSubscriptionUpdate extends SubscriptionFlags {
  subscriptionIds: number[];
  addTagIds?: number[];
  removeTagIds?: number[];
}
