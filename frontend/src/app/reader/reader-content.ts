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

type ExtractionFailureReason = 'no_url' | 'fetch' | 'unextractable' | 'empty' | 'mismatch';

/** Extraction could not produce an article; the client falls back to feed content. */
export interface ReaderFailure {
  status: 'failed';
  url: string | null;
  reason: ExtractionFailureReason | 'player_page' | 'feed_body_is_post';
  /** The underlying cause in words when one exists — a fetch carries the HTTP
   *  status or transport message; a reason with no such cause is null. */
  detail: string | null;
  originalHero: HeroImageDto | null;
}

export type ReaderContent = ReaderArticle | ReaderFailure;
