import { EntryDto, HeroImageDto } from './models';

/** The entry's dek: the server's own plain-text excerpt. */
export function entrySnippet(entry: EntryDto): string {
  return entry.excerpt;
}

/** Same shape as the API's HeroImageDto — one declaration, so a picture the
 *  client derives and a picture the backend resolved cannot drift apart.
 *  Null width/height mean the feed did not say. */
export type EntryImage = HeroImageDto;

const images = new WeakMap<EntryDto, EntryImage | null>();

/** The entry's persisted image, or null. Memoized per entry object for a
 *  stable reference across repeated reads (the planner asks for every loaded
 *  entry on every plan, #501). */
export function entryImage(entry: EntryDto): EntryImage | null {
  const known = images.get(entry);
  if (known !== undefined) return known;
  const image = resolveEntryImage(entry);
  images.set(entry, image);
  return image;
}

function resolveEntryImage(entry: EntryDto): EntryImage | null {
  if (!entry.imageUrl) return null;
  return { url: entry.imageUrl, width: entry.imageWidth, height: entry.imageHeight };
}
