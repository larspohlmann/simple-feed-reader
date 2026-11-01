import { SocialNetworkName } from '../core/social-network';

/** `canLike`: the reader has an account on the post's network (#1615). */
export interface EntrySocialDto {
  network: SocialNetworkName;
  isLiked: boolean;
  canLike: boolean;
}

/** The answer of `PUT`/`DELETE /api/entries/{id}/like`. */
export interface EntryLikeDto {
  isLiked: boolean;
}

/** The answer of `GET /api/entries/{id}/social/viewer`: what the reader has done to the post on its network. */
export interface SocialViewerDto {
  isLiked: boolean;
  isReposted: boolean;
  canRepost: boolean;
}

/** The answer of `PUT`/`DELETE /api/entries/{id}/repost` and of a reply's repost. */
export interface EntryRepostDto {
  isReposted: boolean;
}

export interface RepostView {
  isReposted: boolean;
  canRepost: boolean;
}

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
