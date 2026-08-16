// src/app/reader/feed-hero-image.ts
import { EntryDto } from './models';

/** The feed's own picture, ready to lead an article that has none of its own. */
export interface FeedHeroImage {
  url: string;
  /** As declared by the feed. Null means unknown, so no space is reserved. */
  width: number | null;
  height: number | null;
}

/** Any image tag, however the feed cased or closed it. */
const IMG_TAG = /<img[\s/>]/i;

/**
 * The feed's picture to show above an article, or null to show none.
 *
 * A missing scraped lead image does not mean the page had no picture: the
 * extractor suppresses its own lead image whenever the extracted body already
 * holds one (`ArticleExtractor::leadImage`). So the decision is made against
 * the body that will actually render — an illustrated article must not gain a
 * second, redundant hero on top of the pictures it already shows.
 *
 * Only the persisted `imageUrl` qualifies. The inline-image fallback the list
 * surfaces use is fine for a thumbnail but not for a full-width hero, where a
 * tracking pixel or a byline avatar would be conspicuous.
 */
export function feedHeroImage(entry: EntryDto | null, bodyHtml: string): FeedHeroImage | null {
  if (!entry?.imageUrl) return null;
  if (IMG_TAG.test(bodyHtml)) return null;
  return { url: entry.imageUrl, width: entry.imageWidth, height: entry.imageHeight };
}
