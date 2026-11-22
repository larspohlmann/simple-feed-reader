import { LinkCardDto } from '../../link-card/link-card';
import { ResponseKind } from '../../entry-social';

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
  subject: ComposerSubjectDto | null;
  lemmy: LemmyPostFieldsDto | null;
}

/** What a Lemmy post chooses from: the communities it may go to and the title it starts with. */
export interface LemmyPostFieldsDto {
  communities: { id: number; handle: string }[];
  title: string;
  minTitleCharacters: number;
  maxTitleCharacters: number;
}

export const MASTODON_VISIBILITIES = ['public', 'unlisted', 'private', 'direct'] as const;
export type MastodonVisibility = (typeof MASTODON_VISIBILITIES)[number];

/** The body of every composer `POST`; each network ignores the fields only Mastodon or only Lemmy takes. */
export interface PostDraftDto {
  text: string;
  accountId: number;
  language: string | null;
  idempotencyKey: string;
  visibility?: MastodonVisibility;
  contentWarning?: string;
  title?: string;
  communityId?: number;
}

/** A post the network took: its address in the thread and where a browser shows it. */
export interface PublishedPostDto {
  address: string;
  url: string | null;
}
