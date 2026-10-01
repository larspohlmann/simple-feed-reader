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

/** The renditions as a `srcset`, narrowest first and one candidate per width, or null
 *  when there are none. Stubbed e2e entries predate the field and omit it. */
export function renditionSrcset(
  renditions: readonly ImageRenditionDto[] | undefined,
): string | null {
  if (!renditions?.length) return null;
  const urlByWidth = new Map<number, string>();
  for (const rendition of renditions) {
    if (!urlByWidth.has(rendition.width)) urlByWidth.set(rendition.width, rendition.url);
  }
  return [...urlByWidth]
    .sort(([left], [right]) => left - right)
    .map(([width, url]) => `${url} ${width}w`)
    .join(', ');
}
