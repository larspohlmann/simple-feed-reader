import { SocialNetworkName } from '../core/social-network';

/** `canLike`: the reader has an account on the post's network (#1615). */
export interface EntrySocialDto {
  network: SocialNetworkName;
  isLiked: boolean;
  canLike: boolean;
  /** Whether one of the reader's own accounts wrote the post (#1972). */
  isOwn: boolean;
}

/** The answer of `PUT`/`DELETE /api/entries/{id}/like`. */
export interface EntryLikeDto {
  isLiked: boolean;
}

/** The answer of liking or unliking a post: its like count as the network reports it, null when unknown. */
export interface EntryPostLikeDto extends EntryLikeDto {
  likeCount: number | null;
}

/** The answer of `DELETE /api/entries/{id}/post`: whether the network still had the post. */
export interface PostDeletionDto {
  outcome: 'deleted' | 'alreadyGone';
}

/** The problem type of the viewer answer for a post deleted on its network and removed here (#1975). */
export const SOCIAL_POST_REMOVED = 'social_post_removed';

/** The answer of `GET /api/entries/{id}/social/viewer`: what the reader has done to the post on its network. */
export interface SocialViewerDto {
  isLiked: boolean;
  isReposted: boolean;
  canRepost: boolean;
  canReply: boolean;
  canQuote: boolean;
}

/** The answer of `PUT`/`DELETE /api/entries/{id}/repost` and of a reply's repost. */
export interface EntryRepostDto {
  isReposted: boolean;
}

/** What the reader can do to the post beyond liking it, and has done. */
export type PostActionsView = Omit<SocialViewerDto, 'isLiked'>;

/** What the composer writes about its target post. */
export type ResponseKind = 'reply' | 'quote';

/**
 * A reply or quote of the entry's own post, or of a reply in its thread named by `address`; `network` names the
 * composer before the server answers.
 */
export interface ResponseTargetDto {
  kind: ResponseKind;
  entryId: number;
  network: SocialNetworkName;
  address: string | null;
}

/** A post of a URL the reader shares, by the title it goes by, on a network the reader has an account on. */
export interface LinkTargetDto {
  kind: 'link';
  network: SocialNetworkName;
  url: string;
  title: string;
  /** The reader's selection the composer starts from; null shares the link alone. */
  quote: string | null;
}

/** What a link post shares, before a network is chosen. */
export type SharedLinkDto = Omit<LinkTargetDto, 'kind' | 'network'>;

/** What the composer writes: a response to a post, or a post of a shared URL. */
export type PostTargetDto = ResponseTargetDto | LinkTargetDto;

/** The answer of `GET /api/entries/{id}/social`; `quotes` is null on Mastodon. */
export interface SocialCountsDto {
  likes: number;
  reposts: number;
  replies: number;
  quotes: number | null;
}

/** The entry's social data when the reader can like it, else null. */
export function likeableSocial(entry: { social: EntrySocialDto | null }): EntrySocialDto | null {
  return entry.social?.canLike ? entry.social : null;
}
