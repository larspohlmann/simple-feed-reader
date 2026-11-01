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
  canReply: boolean;
  canQuote: boolean;
}

/** The answer of `PUT`/`DELETE /api/entries/{id}/repost` and of a reply's repost. */
export interface EntryRepostDto {
  isReposted: boolean;
}

/** What the reader can do to the post beyond liking it, and has done. */
export type PostActionsView = Omit<SocialViewerDto, 'isLiked'>;

/**
 * The answer of `GET /api/entries/{id}/social/composer`: whether the author lets the reader reply or quote, as
 * asked, as whom, and where the text starts. `visibility` is null on Bluesky.
 */
export interface ComposerContextDto {
  accounts: { id: number; handle: string }[];
  accountId: number;
  canSend: boolean;
  maxCharacters: number;
  visibility: MastodonVisibility | null;
  mentions: string[];
}

/** What the composer writes about its target post. */
export type ResponseKind = 'reply' | 'quote';

/** A reply or quote of the entry's own post, or of a reply in its thread named by `address`. */
export interface ResponseTargetDto {
  kind: ResponseKind;
  entryId: number;
  address: string | null;
}

/** A post of the entry's own link on a network the reader has an account on. */
export interface LinkTargetDto {
  kind: 'link';
  entryId: number;
  network: SocialNetworkName;
}

/** What the composer writes: a response to a post, or a post of the entry's link. */
export type PostTargetDto = ResponseTargetDto | LinkTargetDto;

export const MASTODON_VISIBILITIES = ['public', 'unlisted', 'private', 'direct'] as const;
export type MastodonVisibility = (typeof MASTODON_VISIBILITIES)[number];

/** The body of every composer `POST` (`social/replies`, `social/quotes`, `social/links`); the Mastodon-only fields are ignored on Bluesky. */
export interface PostDraftDto {
  text: string;
  accountId: number;
  language: string | null;
  idempotencyKey: string;
  visibility?: MastodonVisibility;
  contentWarning?: string;
}

/** A reply the network took: its address in the thread and where a browser shows it. */
export interface PublishedPostDto {
  address: string;
  url: string | null;
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
