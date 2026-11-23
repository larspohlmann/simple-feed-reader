import { SocialNetworkName } from '../../core/social-network';
import { EntryDto } from '../models';
import { OwnInstanceLinks } from './own-instance-links.service';

type LinkedEntry = Pick<EntryDto, 'social' | 'community' | 'discussionHomeUrl'>;
type OpenedEntry = LinkedEntry & Pick<EntryDto, 'url' | 'discussionUrl'>;

export function entryLinkNetwork(entry: LinkedEntry): SocialNetworkName | null {
  if (entry.social) return entry.social.network;
  return entry.community !== null || entry.discussionHomeUrl !== null ? 'lemmy' : null;
}

export function entryOpenUrl(entry: OpenedEntry, links: OwnInstanceLinks): string | null {
  const network = entryLinkNetwork(entry);
  if (entry.url !== null) return links.open(entry.url, { network });
  if (entry.discussionUrl === null) return null;
  return links.open(entry.discussionUrl, { network, homeUrl: entry.discussionHomeUrl });
}
