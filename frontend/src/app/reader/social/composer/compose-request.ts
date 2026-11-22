import { WritableSignal } from '@angular/core';
import { SOCIAL_NETWORKS, SocialNetworkName } from '../../../core/social-network';

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

/** A post of text alone. */
export interface PostRequest {
  kind: 'post';
  network: SocialNetworkName;
}

/** The networks a post of text alone goes to: Lemmy's posts need a title and a community. */
export const TEXT_POST_NETWORKS: readonly SocialNetworkName[] = ['bluesky', 'mastodon'];

/** What a link post shares, before a network is chosen. */
export type SharedLinkDto = Omit<LinkRequest, 'kind' | 'network'>;

/** What the composer is asked to write. */
export type ComposeRequest = (ResponseRequest | LinkRequest | PostRequest) & {
  /** Text that outlives the composer: it starts from it and mirrors every edit into it. */
  draft?: WritableSignal<string>;
};

/** What the composer sends: a link post that names a post on its network quotes it. */
export type ComposerKind = ComposeRequest['kind'];

/** The networks the post may go to besides the one it was opened for, where the reader is connected; none for an answer. */
export function crosspostNetworks(request: ComposeRequest): readonly SocialNetworkName[] {
  switch (request.kind) {
    case 'link':
      return SOCIAL_NETWORKS;
    case 'post':
      return TEXT_POST_NETWORKS;
    default:
      return [];
  }
}
