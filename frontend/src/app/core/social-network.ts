export const SOCIAL_NETWORKS = ['bluesky', 'mastodon'] as const;

export type SocialNetworkName = (typeof SOCIAL_NETWORKS)[number];

export const SOCIAL_NETWORK_NAMES: Record<SocialNetworkName, string> = {
  bluesky: 'Bluesky',
  mastodon: 'Mastodon',
};

export const ACCOUNT_NETWORKS = [...SOCIAL_NETWORKS, 'lemmy'] as const;

export type AccountNetworkName = (typeof ACCOUNT_NETWORKS)[number];

const NETWORK_FEED_KINDS = ['profile', 'search'] as const;

export type NetworkFeedKind = (typeof NETWORK_FEED_KINDS)[number];

export function isNetworkFeedKind(value: string | null): value is NetworkFeedKind {
  return NETWORK_FEED_KINDS.some((kind) => kind === value);
}

export function isSocialNetworkName(value: string | null): value is SocialNetworkName {
  return SOCIAL_NETWORKS.some((network) => network === value);
}
