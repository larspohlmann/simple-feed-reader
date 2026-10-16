import { EntryMediumDto } from './entry-medium';
import { LinkCardDto } from './link-card/link-card';
import { QuotedPostDto } from './quoted-post/quoted-post';

export interface EntryCommentDto {
  author: string | null;
  authorHandle: string | null;
  authorUrl: string | null;
  authorAvatarUrl: string | null;
  url: string | null;
  publishedAt: string | null;
  html: string;
  byEntryAuthor: boolean;
  depth: number;
  media: EntryMediumDto[];
  linkCard: LinkCardDto | null;
  quote: QuotedPostDto | null;
}

export type CommentsResponse =
  | { status: 'ok'; comments: EntryCommentDto[]; omitted: number; fetchedAt: string }
  | { status: 'throttled'; retryAfter: number }
  | { status: 'failed' };
