export interface LinkCardDto {
  url: string;
  title: string;
  description: string | null;
  imageUrl: string | null;
  siteName: string;
}

/** A card stored on an entry, root or reply; `position` is what the reader endpoint and `?link=` name (#1668). */
export interface PlacedLinkCardDto extends LinkCardDto {
  position: number;
}
