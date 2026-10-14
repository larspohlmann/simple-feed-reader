/** One visual media item the feed declared for an entry (#906). */
export interface EntryMediumDto {
  url: string;
  kind: 'image' | 'video';
  width?: number;
  height?: number;
  previewImageUrl?: string;
}
