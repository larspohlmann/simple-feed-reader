import { SocialNetworkName } from '../core/social-network';
import { LinkCardDto } from './link-card/link-card';

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

/** A link card as the composer shows it: the one a post carries, or the page a link post shares. */
export type ComposerCardDto = Omit<LinkCardDto, 'readerToken'>;

/** The post a reply or quote answers, or a link post quotes: who wrote it, how it starts, and the card it carries. */
export interface PostComposerSubjectDto {
  kind: 'post';
  authorName: string | null;
  authorHandle: string | null;
  excerpt: string;
  card: ComposerCardDto | null;
}

/** The page a link post shares, by its own card, else by its host. */
export interface LinkComposerSubjectDto {
  kind: 'link';
  card: ComposerCardDto;
}

/** What the composer shows above the text: what the post answers, quotes or shares. */
export type ComposerSubjectDto = PostComposerSubjectDto | LinkComposerSubjectDto;

/** What the composer writes about its target post. */
export type ResponseKind = 'reply' | 'quote';

/** What the composer sends: a link post that names a post on its network quotes it. */
export type ComposerKind = ResponseKind | 'link';

/**
 * The answer of `GET /api/entries/{id}/social/composer` and `GET /api/social/link-composer`: what the composer
 * sends and shows, whether the author lets the reader reply or quote, as asked, as whom, and where the text starts.
 * `visibility` is null on Bluesky.
 */
export interface ComposerContextDto {
  kind: ComposerKind;
  accounts: { id: number; handle: string }[];
  accountId: number;
  canSend: boolean;
  maxCharacters: number;
  visibility: MastodonVisibility | null;
  text: string;
  target: ComposerSubjectDto;
}

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
}

/** What the composer writes: a response to a post, or a post of a shared URL. */
export type PostTargetDto = ResponseTargetDto | LinkTargetDto;

export const MASTODON_VISIBILITIES = ['public', 'unlisted', 'private', 'direct'] as const;
export type MastodonVisibility = (typeof MASTODON_VISIBILITIES)[number];

/** The body of every composer `POST`; the Mastodon-only fields are ignored on Bluesky. */
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
