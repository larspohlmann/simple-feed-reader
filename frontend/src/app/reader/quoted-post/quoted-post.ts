import { EntryMediumDto } from '../entry-medium';
import { LinkCardDto } from '../link-card/link-card';

/** The post a quote share quotes (#1612): plain text with its line breaks, and its own media. */
export interface QuotedPostDto {
  url: string;
  authorName: string;
  authorHandle: string;
  authorAvatarUrl: string | null;
  postedAt: string | null;
  text: string;
  media: EntryMediumDto[];
  /** The quoted post's own quote, shown as one line; its handle is unknown when the network answered it shallow. */
  nestedQuote: { url: string; handle: string | null } | null;
  linkCard: LinkCardDto | null;
}
