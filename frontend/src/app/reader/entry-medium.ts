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

export function isGif(medium: EntryMediumDto): boolean {
  return medium.animated === true;
}
