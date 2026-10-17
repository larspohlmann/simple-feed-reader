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
