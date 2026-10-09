import { EntryDto, HeroImageDto, ImageRenditionDto } from '../models';

/** The entry's dek: the server's own plain-text excerpt. */
export function entrySnippet(entry: EntryDto): string {
  return entry.excerpt;
}

/** Same shape as the API's HeroImageDto — one declaration, so a picture the
 *  client derives and a picture the backend resolved cannot drift apart.
 *  Null width/height mean the feed did not say. */
export type EntryImage = HeroImageDto;

/** The entry's persisted image, or null, as wide as the widest file it can be shown at. */
export function entryImage(entry: EntryDto): EntryImage | null {
  if (!entry.imageUrl) return null;
  const image = { url: entry.imageUrl, width: entry.imageWidth, height: entry.imageHeight };
  return atLadderWidth(image, widestRenditionWidth(entry.imageRenditions));
}

/** A lead image without a declared width keeps its unknowns: no height is invented. */
function atLadderWidth(image: EntryImage, widest: number | null): EntryImage {
  if (image.width === null || widest === null || widest <= image.width) return image;
  const height = image.height === null ? null : Math.round((image.height * widest) / image.width);
  return { ...image, width: widest, height };
}

/** Below this ratio a cover is portrait; the margin keeps a near-square image landscape. */
const PORTRAIT_BELOW = 1 / 1.05;
/** The narrowest portrait a cover is cropped to: a vertical video's frame. */
const NARROWEST_PORTRAIT = 9 / 16;

/** The shown picture's width / height: the server's when the file letterboxes it, else the
 *  declared dimensions; null when unknown. */
function coverAspectRatio(entry: EntryDto): number | null {
  if (entry.imageAspectRatio != null) return entry.imageAspectRatio;
  return entry.imageWidth && entry.imageHeight ? entry.imageWidth / entry.imageHeight : null;
}

/** The CSS `aspect-ratio` a portrait cover is cropped to, or null for any other image —
 *  an unknown shape included, since its orientation can't be judged. */
export function portraitCoverAspect(entry: EntryDto): string | null {
  const ratio = coverAspectRatio(entry);
  if (ratio === null || ratio >= PORTRAIT_BELOW) return null;
  return String(Math.max(ratio, NARROWEST_PORTRAIT));
}

/** A Short is marked by a pill only where no image carries its badge. */
export function showsShortPill(entry: EntryDto, imageShown: boolean): boolean {
  return entry.isShort && !imageShown;
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
