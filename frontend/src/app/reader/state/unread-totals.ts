import { NetworkFeedKind, SocialNetworkName } from '../../core/social-network';
import { UnreadTotalsDto } from '../models';

export type ZeroTarget =
  | 'all'
  | { tag: number }
  | { subscription: number }
  | { network: SocialNetworkName; feedKind?: NetworkFeedKind };

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

export function zeroUnreadTotals(totals: UnreadTotals, target: ZeroTarget): UnreadTotals {
  if (target === 'all') return { ...totals, allItems: 0 };
  if ('tag' in target) return { ...totals, tags: withZero(totals.tags, target.tag) };
  if ('network' in target) return zeroNetwork(totals, target.network, target.feedKind);
  return totals;
}

function zeroNetwork(
  totals: UnreadTotals,
  network: SocialNetworkName,
  feedKind: NetworkFeedKind | undefined,
): UnreadTotals {
  const networkProfiles = withZero(totals.networkProfiles, network);
  if (feedKind === 'profile') return { ...totals, networkProfiles };
  return { ...totals, networks: withZero(totals.networks, network), networkProfiles };
}

function withZero<Key>(counts: ReadonlyMap<Key, number>, key: Key): ReadonlyMap<Key, number> {
  if (!counts.has(key)) return counts;
  return new Map(counts).set(key, 0);
}

function sameCounts<Key>(left: ReadonlyMap<Key, number>, right: ReadonlyMap<Key, number>): boolean {
  if (left.size !== right.size) return false;
  for (const [key, count] of left) if (right.get(key) !== count) return false;
  return true;
}
