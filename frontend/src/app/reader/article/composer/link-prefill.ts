import { SocialNetworkName } from '../../../core/social-network';

/** A Bluesky link post carries its link in the card, so the text starts with the title alone. */
export function linkPrefill(network: SocialNetworkName, title: string, url: string): string {
  if (network === 'bluesky') return title;
  return title === '' ? url : `${title}\n\n${url}`;
}
