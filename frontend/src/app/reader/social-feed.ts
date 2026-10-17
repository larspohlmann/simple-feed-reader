import { SocialNetworkName } from '../core/social-network';

/** Where a social feed comes from: the network, which of its feeds, and the connected account a private feed is read through. */
export type SocialFeedRefDto = { network: SocialNetworkName } & (
  | { kind: 'profile'; accountId: null }
  | { kind: 'saved'; accountId: null }
  | { kind: 'home' | 'list' | 'custom_feed'; accountId: number }
);

export type PrivateFeedRefDto = Extract<SocialFeedRefDto, { accountId: number }>;

/** A connected account, named once per subscriptions answer for the sidebar's account labels. */
export interface SocialAccountRefDto {
  id: number;
  network: SocialNetworkName;
  handle: string;
}
