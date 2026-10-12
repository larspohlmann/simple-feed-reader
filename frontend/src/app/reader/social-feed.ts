import { SocialNetworkName } from '../core/social-network';

/** Where a social feed comes from: the network, the connected account a private feed is read through (null for a profile feed), which of its feeds. */
export interface SocialFeedRefDto {
  network: SocialNetworkName;
  accountId: number | null;
  kind: 'home' | 'list' | 'custom_feed' | 'profile';
}

/** A connected account, named once per subscriptions answer for the sidebar's account labels. */
export interface SocialAccountRefDto {
  id: number;
  network: SocialNetworkName;
  handle: string;
}
