import { EntryMediumDto } from './entry-medium';
import { LinkCardDto } from './link-card/link-card';
import { EntryDto } from './models';

/** One of the author's own replies folded into the entry (#1668). */
export interface ThreadPostDto {
  url: string | null;
  publishedAt: string | null;
  html: string;
  /** Plain text of `html`, for the list. */
  text: string;
  media: EntryMediumDto[];
  linkCard: LinkCardDto | null;
}

export function threadTotal(entry: EntryDto): number {
  return entry.thread.length + 1;
}
