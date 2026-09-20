import { EntryDto, HeroImageDto } from './models';

/** The entry's dek: the server's own plain-text excerpt. */
export function entrySnippet(entry: EntryDto): string {
  return entry.excerpt;
}

/** Same shape as the API's HeroImageDto — one declaration, so a picture the
 *  client derives and a picture the backend resolved cannot drift apart.
 *  Null width/height mean the feed did not say. */
export type EntryImage = HeroImageDto;

/** The entry's persisted image, or null. */
export function entryImage(entry: EntryDto): EntryImage | null {
  if (!entry.imageUrl) return null;
  return { url: entry.imageUrl, width: entry.imageWidth, height: entry.imageHeight };
}
