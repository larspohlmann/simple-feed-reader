import { SocialNetworkName } from '../core/social-network';

/** Where a social feed comes from: the network, which of its feeds, and the connected account a private feed is read through. */
export type SocialFeedRefDto = { network: SocialNetworkName } & (
  | { kind: 'profile'; accountId: null }
  | { kind: 'saved'; accountId: null }
  | { kind: 'home' | 'trending' | 'list' | 'custom_feed' | 'tag'; accountId: number }
);

export type PrivateFeedRefDto = Extract<SocialFeedRefDto, { accountId: number }>;

export type SocialAccountStatus = 'connected' | 'needs_reconnect';

/** A connected account, named once per subscriptions answer for the sidebar's account labels and menus. */
export interface SocialAccountRefDto {
  id: number;
  network: SocialNetworkName;
  handle: string;
  status: SocialAccountStatus;
}
