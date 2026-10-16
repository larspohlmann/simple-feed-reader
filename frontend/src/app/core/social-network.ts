export const SOCIAL_NETWORKS = ['bluesky', 'mastodon'] as const;

export type SocialNetworkName = (typeof SOCIAL_NETWORKS)[number];

export const SOCIAL_NETWORK_NAMES: Record<SocialNetworkName, string> = {
  bluesky: 'Bluesky',
  mastodon: 'Mastodon',
};

export function isSocialNetworkName(value: string | null): value is SocialNetworkName {
  return SOCIAL_NETWORKS.some((network) => network === value);
}
