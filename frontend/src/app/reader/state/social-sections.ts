import { SubscriptionDto } from '../models';
import { SocialAccountRefDto } from '../social-feed';
import { SOCIAL_NETWORKS, SocialNetworkName } from '../../core/social-network';

export interface SocialAccountGroup {
  readonly accountId: number;
  readonly handle: string | null;
  readonly subscriptions: readonly SubscriptionDto[];
}

export interface SocialSection {
  readonly network: SocialNetworkName;
  readonly accounts: readonly SocialAccountGroup[];
  /** Profile feeds of the network, alphabetically: the people followed rather than an account's own feeds. */
  readonly people: readonly SubscriptionDto[];
  readonly unreadCount: number;
}

export interface NetworkCounts {
  readonly unreadCount: number;
  readonly entryCount: number;
}

const KIND_ORDER: Readonly<Record<NonNullable<SubscriptionDto['social']>['kind'], number>> = {
  home: 0,
  list: 1,
  custom_feed: 2,
  profile: 3,
};

export function isSocialFeed(subscription: SubscriptionDto): boolean {
  return Boolean(subscription.social);
}

/** A timeline, list or custom feed read through a connected account; a profile feed is ordinary RSS. */
export function isPrivateFeed(subscription: SubscriptionDto): boolean {
  return isSocialFeed(subscription) && subscription.social?.kind !== 'profile';
}

/** A person, list or custom feed is shown by its picture; Home keeps the network's favicon, an ordinary feed its own. */
export function feedAvatarUrl(subscription: SubscriptionDto | null): string | null {
  if (
    subscription === null ||
    !isSocialFeed(subscription) ||
    subscription.social?.kind === 'home'
  ) {
    return null;
  }
  return subscription.imageUrl;
}

/** One section per network holding a social feed: private feeds per account by id, each Home first, then the people. */
export function buildSocialSections(
  subscriptions: readonly SubscriptionDto[],
  accounts: readonly SocialAccountRefDto[],
): SocialSection[] {
  const handles = new Map(accounts.map((account) => [account.id, account.handle]));
  return SOCIAL_NETWORKS.map((network) => {
    const ofNetwork = subscriptions.filter(
      (subscription) => subscription.social?.network === network,
    );
    return {
      network,
      accounts: accountGroups(ofNetwork.filter(isPrivateFeed), handles),
      people: ofNetwork
        .filter((subscription) => !isPrivateFeed(subscription))
        .sort((left, right) => left.title.localeCompare(right.title)),
      unreadCount: networkCounts(ofNetwork, network).unreadCount,
    };
  }).filter((section) => section.accounts.length > 0 || section.people.length > 0);
}

/** What a network's view holds: every feed of the network, a feed excluded from All items too, as a tag counts. */
export function networkCounts(
  subscriptions: readonly SubscriptionDto[],
  network: SocialNetworkName,
): NetworkCounts {
  return subscriptions
    .filter((subscription) => subscription.social?.network === network)
    .reduce(
      (counts, subscription) => ({
        unreadCount: counts.unreadCount + subscription.unreadCount,
        entryCount: counts.entryCount + subscription.entryCount,
      }),
      { unreadCount: 0, entryCount: 0 },
    );
}

function accountGroups(
  subscriptions: readonly SubscriptionDto[],
  handles: ReadonlyMap<number, string>,
): SocialAccountGroup[] {
  const byAccount = new Map<number, SubscriptionDto[]>();
  for (const subscription of subscriptions) {
    const accountId = subscription.social?.accountId ?? 0;
    byAccount.set(accountId, [...(byAccount.get(accountId) ?? []), subscription]);
  }
  return [...byAccount.entries()]
    .sort(([left], [right]) => left - right)
    .map(([accountId, feeds]) => ({
      accountId,
      handle: handles.get(accountId) ?? null,
      subscriptions: [...feeds].sort(byKindThenTitle),
    }));
}

function byKindThenTitle(left: SubscriptionDto, right: SubscriptionDto): number {
  return kindRank(left) - kindRank(right) || left.title.localeCompare(right.title);
}

function kindRank(subscription: SubscriptionDto): number {
  return KIND_ORDER[subscription.social?.kind ?? 'home'];
}
