import { SubscriptionDto } from '../models';
import {
  FeedAccountRefDto,
  PrivateFeedRefDto,
  SocialAccountRefDto,
  SocialFeedRefDto,
} from '../social-feed';
import { NetworkFeedKind, SOCIAL_NETWORKS, SocialNetworkName } from '../../core/social-network';
import type { UnreadTotals } from './unread-totals';

export interface SocialAccountGroup {
  readonly accountId: number;
  readonly handle: string | null;
  readonly subscriptions: readonly SubscriptionDto[];
}

/** The people followed, or the searches, of every account together. */
export interface SocialFeedGroup {
  /** Alphabetically. */
  readonly subscriptions: readonly SubscriptionDto[];
  readonly unreadCount: number;
  readonly entryCount: number;
}

/** How the sidebar and the list heading show a network feed kind. */
export interface NetworkFeedKindDescriptor {
  readonly labelKey: string;
  readonly toggleKey: string;
  readonly glyph: string;
  readonly testId: string;
  readonly storageName: string;
  readonly order: number;
}

export const NETWORK_FEED_KIND_DESCRIPTORS: Readonly<
  Record<NetworkFeedKind, NetworkFeedKindDescriptor>
> = {
  search: {
    labelKey: 'reader.socialSearches',
    toggleKey: 'reader.toggleSocialSearches',
    glyph: 'search',
    testId: 'sidebar-social-searches',
    storageName: 'searches',
    order: 0,
  },
  profile: {
    labelKey: 'reader.socialFeeds',
    toggleKey: 'reader.toggleSocialFeeds',
    glyph: 'group',
    testId: 'sidebar-social-feeds',
    storageName: 'profiles',
    order: 1,
  },
};

export const NETWORK_FEED_KINDS_IN_ORDER: readonly NetworkFeedKind[] = (
  Object.keys(NETWORK_FEED_KIND_DESCRIPTORS) as NetworkFeedKind[]
).sort(
  (left, right) =>
    NETWORK_FEED_KIND_DESCRIPTORS[left].order - NETWORK_FEED_KIND_DESCRIPTORS[right].order,
);

export interface SocialSection {
  readonly network: SocialNetworkName;
  readonly accounts: readonly SocialAccountGroup[];
  /** Every connected account of the network, by id: the head's menu opens each one's picker. */
  readonly networkAccounts: readonly FeedAccountRefDto[];
  readonly groups: Readonly<Record<NetworkFeedKind, SocialFeedGroup>>;
  /** Shown only while it holds posts. */
  readonly saved: SubscriptionDto | null;
  /** Every feed of the network, one excluded from All items too, as a tag counts. */
  readonly unreadCount: number;
  readonly entryCount: number;
  /** Several accounts of the network: each is headed by its handle, and a Home says whose it is. */
  readonly showsHandles: boolean;
}

type PrivateSubscription = SubscriptionDto & { social: PrivateFeedRefDto };

const KIND_ORDER: Readonly<Record<PrivateFeedRefDto['kind'], number>> = {
  home: 0,
  trending: 1,
  list: 2,
  custom_feed: 3,
  tag: 4,
  search: 5,
};

export function isSocialFeed(subscription: SubscriptionDto): boolean {
  return Boolean(subscription.social);
}

export function isProfileFeed(subscription: SubscriptionDto): boolean {
  return subscription.social?.kind === 'profile';
}

export type SearchSubscription = SubscriptionDto & {
  social: Extract<SocialFeedRefDto, { kind: 'search' }>;
};

export function isSearchFeed(subscription: SubscriptionDto): subscription is SearchSubscription {
  return subscription.social?.kind === 'search';
}

/** A "Saved from …" feed: favourites and kept posts of feeds the user left. */
export function isSavedFeed(subscription: SubscriptionDto): boolean {
  return subscription.sourceFormat === 'saved';
}

/** A saved feed shows only while it holds posts. */
export function isEmptySavedFeed(subscription: SubscriptionDto): boolean {
  return isSavedFeed(subscription) && subscription.entryCount === 0;
}

export function networkOf(subscription: SubscriptionDto): SocialNetworkName | null {
  return subscription.social?.network ?? null;
}

/** A timeline, list or custom feed read through a connected account; a profile feed is ordinary RSS. */
export function isPrivateFeed(subscription: SubscriptionDto): subscription is PrivateSubscription {
  return isSocialFeed(subscription) && !isProfileFeed(subscription) && !isSavedFeed(subscription);
}

function isAccountFeed(subscription: SubscriptionDto): subscription is PrivateSubscription {
  return isPrivateFeed(subscription) && !isSearchFeed(subscription);
}

/** A social feed other than Home is shown by its picture when it has one; today only profile feeds get one. */
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

/** One section per network holding a social feed: private feeds per account by id, each Home first, then the searches and the profiles. */
export function buildSocialSections(
  subscriptions: readonly SubscriptionDto[],
  socialAccounts: readonly SocialAccountRefDto[],
  totals: UnreadTotals,
): SocialSection[] {
  const handles = new Map(socialAccounts.map((account) => [account.id, account.handle]));
  return SOCIAL_NETWORKS.map((network) => {
    const ofNetwork = subscriptions.filter((subscription) => networkOf(subscription) === network);
    const searches = ofNetwork.filter(isSearchFeed);
    const accounts = accountGroups(ofNetwork.filter(isAccountFeed), handles);
    return {
      network,
      accounts,
      networkAccounts: socialAccounts
        .filter((account): account is FeedAccountRefDto => account.network === network)
        .sort((left, right) => left.id - right.id),
      groups: {
        search: socialFeedGroup(
          searches,
          sum(searches, (subscription) => subscription.unreadCount),
        ),
        profile: socialFeedGroup(
          ofNetwork.filter(
            (subscription) => !isPrivateFeed(subscription) && !isSavedFeed(subscription),
          ),
          totals.networkProfiles.get(network) ?? 0,
        ),
      },
      saved:
        ofNetwork.find(
          (subscription) => isSavedFeed(subscription) && !isEmptySavedFeed(subscription),
        ) ?? null,
      unreadCount: totals.networks.get(network) ?? 0,
      entryCount: sum(ofNetwork, (subscription) => subscription.entryCount),
      showsHandles: accounts.length > 1,
    };
  }).filter(
    (section) =>
      section.accounts.length > 0 ||
      section.networkAccounts.length > 0 ||
      Object.values(section.groups).some((group) => group.subscriptions.length > 0) ||
      section.saved !== null,
  );
}

export function socialFeedGroup(
  subscriptions: readonly SubscriptionDto[],
  unreadCount: number,
): SocialFeedGroup {
  const sorted = [...subscriptions].sort((left, right) => left.title.localeCompare(right.title));
  return {
    subscriptions: sorted,
    unreadCount,
    entryCount: sum(sorted, (subscription) => subscription.entryCount),
  };
}

function sum(
  subscriptions: readonly SubscriptionDto[],
  count: (subscription: SubscriptionDto) => number,
): number {
  return subscriptions.reduce((total, subscription) => total + count(subscription), 0);
}

function accountGroups(
  subscriptions: readonly PrivateSubscription[],
  handles: ReadonlyMap<number, string>,
): SocialAccountGroup[] {
  const byAccount = new Map<number, PrivateSubscription[]>();
  for (const subscription of subscriptions) {
    const feeds = byAccount.get(subscription.social.accountId);
    if (feeds) feeds.push(subscription);
    else byAccount.set(subscription.social.accountId, [subscription]);
  }
  return [...byAccount.entries()]
    .sort(([left], [right]) => left - right)
    .map(([accountId, feeds]) => ({
      accountId,
      handle: handles.get(accountId) ?? null,
      subscriptions: feeds.sort(byKindThenTitle),
    }));
}

function byKindThenTitle(left: PrivateSubscription, right: PrivateSubscription): number {
  return (
    KIND_ORDER[left.social.kind] - KIND_ORDER[right.social.kind] ||
    left.title.localeCompare(right.title)
  );
}
