import { SocialNetworkName } from '../../../core/social-network';

/** What the composer writes about a post. */
export type ResponseKind = 'reply' | 'quote';

/** A reply or quote of the entry's own post, or of a reply in its thread named by `address`. */
export interface ResponseRequest {
  kind: ResponseKind;
  entryId: number;
  network: SocialNetworkName;
  address: string | null;
}

/** A post of a URL the reader shares, by the title it goes by, on a network the reader has an account on. */
export interface LinkRequest {
  kind: 'link';
  network: SocialNetworkName;
  url: string;
  title: string;
  /** The reader's selection the composer starts from; null shares the link alone. */
  quote: string | null;
}

/** What a link post shares, before a network is chosen. */
export type SharedLinkDto = Omit<LinkRequest, 'kind' | 'network'>;

/** What the composer is asked to write. */
export type ComposeRequest = ResponseRequest | LinkRequest;

/** What the composer sends: a link post that names a post on its network quotes it. */
export type ComposerKind = ComposeRequest['kind'];

/** Whether the post may go to every network the reader is connected to, not only the one it was opened for. */
export function crossposts(request: ComposeRequest): boolean {
  return request.kind === 'link';
}
