export type SocialNetworkName = 'bluesky' | 'mastodon';

/** Where a private feed comes from: the network, the connected account it is read through, which of its feeds. */
export interface SocialFeedRefDto {
  network: SocialNetworkName;
  accountId: number;
  kind: 'home' | 'list' | 'custom_feed';
}

/** A connected account, named once per subscriptions answer for the sidebar's account labels. */
export interface SocialAccountRefDto {
  id: number;
  network: SocialNetworkName;
  handle: string;
}
