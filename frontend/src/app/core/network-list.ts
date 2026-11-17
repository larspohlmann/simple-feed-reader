import { SOCIAL_NETWORK_NAMES, SocialNetworkName } from './social-network';

/** Names the networks as one phrase in the language's own list form: "Bluesky and Mastodon". */
export function networkList(networks: readonly SocialNetworkName[], language: string): string {
  const names = networks.map((network) => SOCIAL_NETWORK_NAMES[network]);
  return new Intl.ListFormat(language, { type: 'conjunction' }).format(names);
}
