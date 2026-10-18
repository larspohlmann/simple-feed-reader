import { SocialNetworkName } from '../../core/social-network';
import { UnreadTotalsDto } from '../models';

export interface UnreadTotals {
  readonly allItems: number;
  readonly tags: ReadonlyMap<number, number>;
  readonly networks: ReadonlyMap<SocialNetworkName, number>;
  readonly networkProfiles: ReadonlyMap<SocialNetworkName, number>;
}

export const NO_UNREAD_TOTALS: UnreadTotals = {
  allItems: 0,
  tags: new Map(),
  networks: new Map(),
  networkProfiles: new Map(),
};

export function unreadTotalsFrom(dto: UnreadTotalsDto): UnreadTotals {
  return {
    allItems: dto.allItems,
    tags: new Map(dto.tags.map((tag) => [tag.id, tag.unreadCount])),
    networks: new Map(dto.networks.map((network) => [network.network, network.unreadCount])),
    networkProfiles: new Map(
      dto.networks.map((network) => [network.network, network.profilesUnreadCount]),
    ),
  };
}

export function sameUnreadTotals(left: UnreadTotals, right: UnreadTotals): boolean {
  return (
    left.allItems === right.allItems &&
    sameCounts(left.tags, right.tags) &&
    sameCounts(left.networks, right.networks) &&
    sameCounts(left.networkProfiles, right.networkProfiles)
  );
}

function sameCounts<Key>(left: ReadonlyMap<Key, number>, right: ReadonlyMap<Key, number>): boolean {
  if (left.size !== right.size) return false;
  for (const [key, count] of left) if (right.get(key) !== count) return false;
  return true;
}
