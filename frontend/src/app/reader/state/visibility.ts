import { SocialNetworkName } from '../../core/social-network';
import { ExclusionSurface } from '../feed-exclusions';
import { SubscriptionDto, TagDto, TagInclusion } from '../models';

/** What, besides its own flag, keeps a feed out of a surface. */
export type ExclusionCause =
  { kind: 'network'; network: SocialNetworkName } | { kind: 'tag'; tag: TagDto };

/** Every stored switch that reaches a feed from outside it. */
export interface VisibilitySwitches {
  readonly tags: readonly TagDto[];
  readonly excludedNetworks: Readonly<Record<ExclusionSurface, ReadonlySet<SocialNetworkName>>>;
}

export const INCLUSION_FLAG: Readonly<Record<ExclusionSurface, keyof TagInclusion>> = {
  allItems: 'includeInAllItems',
  forYou: 'includeInForYou',
  savedSearches: 'includeInSavedSearches',
  trending: 'includeInTrending',
};

/** The network first, then each carried tag followed by its ancestors, every switched-off one once. */
export function inheritedExclusions(
  subscription: SubscriptionDto,
  surface: ExclusionSurface,
  switches: VisibilitySwitches,
): ExclusionCause[] {
  const network = subscription.social?.network;
  const networkCauses: ExclusionCause[] =
    network !== undefined && switches.excludedNetworks[surface].has(network)
      ? [{ kind: 'network', network }]
      : [];
  return [...networkCauses, ...excludingTags(subscription, surface, switches)];
}

/** The client copy of the backend's scope rule for one surface. */
export function isVisibleIn(
  subscription: SubscriptionDto,
  surface: ExclusionSurface,
  switches: VisibilitySwitches,
): boolean {
  return (
    subscription[INCLUSION_FLAG[surface]] &&
    inheritedExclusions(subscription, surface, switches).length === 0
  );
}

function excludingTags(
  subscription: SubscriptionDto,
  surface: ExclusionSurface,
  switches: VisibilitySwitches,
): ExclusionCause[] {
  const byId = new Map(switches.tags.map((tag) => [tag.id, tag]));
  const reaching = new Map<number, TagDto>();
  for (const carried of subscription.tags) {
    for (const tag of withAncestors(byId.get(carried.id) ?? carried, byId)) {
      reaching.set(tag.id, tag);
    }
  }
  return [...reaching.values()]
    .filter((tag) => !tag[INCLUSION_FLAG[surface]])
    .map((tag) => ({ kind: 'tag', tag }));
}

/** The tag first, then its parent, its parent's parent and so on. */
export function withAncestors(tag: TagDto, byId: ReadonlyMap<number, TagDto>): TagDto[] {
  const chain = [tag];
  let parent = tag.parentId === null ? undefined : byId.get(tag.parentId);
  while (parent !== undefined && !chain.includes(parent)) {
    chain.push(parent);
    parent = parent.parentId === null ? undefined : byId.get(parent.parentId);
  }
  return chain;
}
