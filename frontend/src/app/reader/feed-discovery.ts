export type NoPreviewReason = 'hidden_profile' | 'connect_bluesky_account' | (string & {});

/** A feed offered by POST /feeds/discover. */
export interface FeedCandidate {
  url: string;
  title: string | null;
  /** 'rss', 'atom' or 'feed', else a source format such as 'scraped' or 'bluesky-api'; an open set. */
  format: string;
  /** How the dialog names the candidate's format ("RSS", "WordPress"). */
  label: string;
  /** The `format` subscribe and preview must send; absent when the server works it out again. */
  storedFormat?: string;
  /** Discovery already read this feed, so the card needs no preview request. */
  preview?: FeedPreview;
  noPreviewReason?: NoPreviewReason;
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
  'blocked' | 'throttled' | 'unreachable' | 'not_scrapable' | 'too_large' | (string & {});

/** POST /feeds/discover; an empty list may carry the reason the scraper fallback gave up. */
export interface DiscoverResult {
  candidates: FeedCandidate[];
  scrapeFailureReason?: ScrapeFailureReason;
}
