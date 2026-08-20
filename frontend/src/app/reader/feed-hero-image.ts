// src/app/reader/feed-hero-image.ts
import { EntryDto } from './models';

/** The feed's own picture, ready to lead an article that has none of its own. */
export interface FeedHeroImage {
  url: string;
  /** As declared by the feed. Null means unknown, so no space is reserved. */
  width: number | null;
  height: number | null;
}

/** The src of every <img>, whatever case or quote style the body used. */
const IMG_SRC = /<img\b[^>]*?\bsrc\s*=\s*(?:"([^"]*)"|'([^']*)')/gi;

/**
 * A CDN-agnostic identity for an image: the path basename without its extension
 * or query string, lowercased. Size-variant URLs of one photo (`/hero.jpg` vs
 * `/hero.webp?width=960`) share it; a different photo does not. Mirrors the
 * backend's `LeadImageSelector` so both ends make the same duplicate call.
 */
function imageIdentity(url: string): string {
  let path = url;
  try {
    path = new URL(url, 'https://feed-hero.invalid').pathname;
  } catch {
    // A malformed URL keeps its raw form; it only ever matches itself.
  }
  const basename = path.split('/').pop() ?? '';
  const stem = basename.replace(/\.[^.]+$/, '');
  return (stem !== '' ? stem : url).toLowerCase();
}

/** Whether the body already shows the same picture as the feed hero. */
function bodyShowsSameImage(bodyHtml: string, heroUrl: string): boolean {
  const heroIdentity = imageIdentity(heroUrl);
  for (const match of bodyHtml.matchAll(IMG_SRC)) {
    const src = match[1] || match[2];
    if (src && imageIdentity(src) === heroIdentity) return true;
  }
  return false;
}

/**
 * The feed's picture to show above an article, or null to show none.
 *
 * A missing scraped lead image does not mean the page had no picture: the
 * extractor suppresses its own lead image whenever the extracted body already
 * shows that same image (`ArticleExtractor` / `LeadImageSelector`). So the
 * decision is made against the body that will actually render — an illustrated
 * article must not gain a second, redundant hero on top of the *same* picture it
 * already shows, but it keeps the hero when the body picture is a different one.
 *
 * Only the persisted `imageUrl` qualifies. The inline-image fallback the list
 * surfaces use is fine for a thumbnail but not for a full-width hero, where a
 * tracking pixel or a byline avatar would be conspicuous.
 */
export function feedHeroImage(entry: EntryDto | null, bodyHtml: string): FeedHeroImage | null {
  if (!entry?.imageUrl) return null;
  if (bodyShowsSameImage(bodyHtml, entry.imageUrl)) return null;
  return { url: entry.imageUrl, width: entry.imageWidth, height: entry.imageHeight };
}
