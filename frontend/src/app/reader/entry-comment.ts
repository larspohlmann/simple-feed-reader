import { EntryMediumDto } from './entry-medium';
import { SocialCountsDto } from './entry-social';
import { LinkCardDto } from './link-card/link-card';
import { VoteValue } from './vote-pill/vote';
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
  /** A Lemmy comment's score; null for any other source. */
  score: number | null;
  media: EntryMediumDto[];
  linkCard: LinkCardDto | null;
  quote: QuotedPostDto | null;
  /** The network post the comment is; null for a comment from a feed. */
  post: EntryCommentPostDto | null;
  /** The Lemmy comment the linked account votes on and replies to; null for any other source or without an account. */
  vote: EntryCommentVoteDto | null;
}

export interface EntryCommentVoteDto {
  address: string;
  myVote: VoteValue;
  canReply: boolean;
}

export interface EntryCommentPostDto {
  address: string;
  counts: SocialCountsDto;
  isLiked: boolean;
  isReposted: boolean;
  canRepost: boolean;
  canReply: boolean;
  canQuote: boolean;
  /** Written by one of the reader's connected accounts, so it can be deleted. */
  isOwn: boolean;
}

export type CommentsResponse =
  | { status: 'ok'; comments: EntryCommentDto[]; omitted: number; fetchedAt: string }
  | { status: 'throttled'; retryAfter: number }
  | { status: 'failed' };
