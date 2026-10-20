export interface LinkCardDto {
  url: string;
  title: string;
  description: string | null;
  imageUrl: string | null;
  siteName: string;
  /** The server's signature of `url`: what the reader endpoint and `?link=` require (#1725). */
  readerToken: string;
}
