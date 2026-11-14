export const SOCIAL_NETWORKS = ['bluesky', 'mastodon', 'lemmy'] as const;

export type SocialNetworkName = (typeof SOCIAL_NETWORKS)[number];

export const SOCIAL_NETWORK_NAMES: Record<SocialNetworkName, string> = {
  bluesky: 'Bluesky',
  mastodon: 'Mastodon',
  lemmy: 'Lemmy',
};

export const LINK_POST_NETWORKS: readonly SocialNetworkName[] = ['bluesky', 'mastodon'];

const NETWORK_FEED_KINDS = ['profile', 'search'] as const;

export type NetworkFeedKind = (typeof NETWORK_FEED_KINDS)[number];

export function isNetworkFeedKind(value: string | null): value is NetworkFeedKind {
  return NETWORK_FEED_KINDS.some((kind) => kind === value);
}

export function isSocialNetworkName(value: string | null): value is SocialNetworkName {
  return SOCIAL_NETWORKS.some((network) => network === value);
}
