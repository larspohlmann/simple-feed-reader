import { EntryMediumDto } from './entry-medium';
import { SocialCountsDto } from './entry-social';
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
  /** The network post the comment is; null for a comment from a feed. */
  post: EntryCommentPostDto | null;
}

export interface EntryCommentPostDto {
  address: string;
  counts: SocialCountsDto;
  isLiked: boolean;
  isReposted: boolean;
  canRepost: boolean;
  canReply: boolean;
  /** Written by one of the reader's connected accounts, so it can be deleted. */
  isOwn: boolean;
}

export type CommentsResponse =
  | { status: 'ok'; comments: EntryCommentDto[]; omitted: number; fetchedAt: string }
  | { status: 'throttled'; retryAfter: number }
  | { status: 'failed' };
