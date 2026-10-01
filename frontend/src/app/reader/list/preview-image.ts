import { EntryDto, HeroImageDto, ImageRenditionDto } from '../models';

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

/** The renditions as a `srcset`, or null when there are none. Stubbed e2e entries predate
 *  the field and omit it. */
export function renditionSrcset(
  renditions: readonly ImageRenditionDto[] | undefined,
): string | null {
  if (!renditions?.length) return null;
  return renditions.map(({ url, width }) => `${url} ${width}w`).join(', ');
}

/** The widest rendition's width, or null when there are none. */
export function widestRenditionWidth(
  renditions: readonly ImageRenditionDto[] | undefined,
): number | null {
  return renditions?.at(-1)?.width ?? null;
}
