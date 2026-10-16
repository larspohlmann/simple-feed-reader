/** One visual media item the feed declared for an entry (#906). */
export interface EntryMediumDto {
  url: string;
  kind: 'image' | 'video' | 'animation';
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

/** A looping clip, or a picture the API marks as animated. */
export function isGif(medium: EntryMediumDto): boolean {
  return medium.kind === 'animation' || medium.animated === true;
}
