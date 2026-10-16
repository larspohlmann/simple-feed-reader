/** One visual media item the feed declared for an entry (#906). */
export interface EntryMediumDto {
  url: string;
  kind: 'image' | 'video' | 'animation';
  width?: number;
  height?: number;
  previewImageUrl?: string;
}

const UNKNOWN_RATIO = 16 / 9;

export function mediumRatio(medium: EntryMediumDto): number {
  return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
}
