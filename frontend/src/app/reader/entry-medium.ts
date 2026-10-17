import { EntryDto } from './models';

/** One visual media item the feed declared for an entry (#906). */
export interface EntryMediumDto {
  url: string;
  kind: 'image' | 'video';
  width?: number;
  height?: number;
  previewImageUrl?: string;
  alt?: string;
  animated?: boolean;
}

const UNKNOWN_RATIO = 16 / 9;

/** The most media a post shows: what Bluesky and Mastodon attach, and what `app-post-media` lays out. */
export const MAX_SHARED_MEDIA = 4;

/** The media without the pictures that are a video's poster or a GIF's still. */
export function withoutPosters(media: EntryMediumDto[]): EntryMediumDto[] {
  const posters = new Set(media.map((medium) => medium.previewImageUrl));
  return media.filter((medium) => medium.kind === 'video' || !posters.has(medium.url));
}

export function mediumRatio(medium: EntryMediumDto): number {
  return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
}

export function entryMediumRatio(entry: EntryDto, medium: EntryMediumDto): number {
  if (medium.url === entry.imageUrl && entry.imageAspectRatio) return entry.imageAspectRatio;
  return mediumRatio(medium);
}

export function isGif(medium: EntryMediumDto): boolean {
  return medium.animated === true;
}
