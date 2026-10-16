/** One visual media item the feed declared for an entry (#906). */
export interface EntryMediumDto {
  url: string;
  kind: 'image' | 'video' | 'animation';
  width?: number;
  height?: number;
  previewImageUrl?: string;
  alt?: string;
}

const UNKNOWN_RATIO = 16 / 9;

export function mediumRatio(medium: EntryMediumDto): number {
  return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
}

/** A looping clip, or an animated picture that names the still it shows while it does not play. */
export function isGif(medium: EntryMediumDto): boolean {
  return (
    medium.kind === 'animation' || (medium.kind === 'image' && Boolean(medium.previewImageUrl))
  );
}
