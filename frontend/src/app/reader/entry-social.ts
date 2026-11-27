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

export type LikeTarget = SocialNetworkName | 'local';

/** The thumb the reader toggles on an entry. */
export interface LikeToggle {
  isLiked: boolean;
  target: LikeTarget;
}

/** What of an entry says whether the reader likes it. */
export interface LikeFacts {
  social: EntrySocialDto | null;
  isLiked: boolean;
  myVote: number | null;
}

/** On the post's network where the reader can like there, else kept here; null on a voted Lemmy post. */
export function likeToggle(entry: LikeFacts): LikeToggle | null {
  const social = likeableSocial(entry);
  if (social) return { isLiked: social.isLiked, target: social.network };
  return entry.myVote === null ? { isLiked: entry.isLiked, target: 'local' } : null;
}

/** Whether the thumb shows the entry liked. */
export function isEntryLiked(entry: LikeFacts): boolean {
  return likeToggle(entry)?.isLiked ?? entry.isLiked;
}

/** Whether the Liked view holds the entry: a local like, a network like the reader can take back, or an up-vote. */
export function isLikedAnyWay(entry: LikeFacts): boolean {
  return entry.isLiked || likeableSocial(entry)?.isLiked === true || entry.myVote === 1;
}

/** The i18n key under `reader.` and `reader.actionHint.` for toggling a like on `target`. */
export function formatLikeKey(isLiked: boolean, target: LikeTarget): string {
  return `${isLiked ? 'unlike' : 'like'}.${target}`;
}
