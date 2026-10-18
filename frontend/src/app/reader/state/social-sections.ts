import { SubscriptionDto } from '../models';
import { PrivateFeedRefDto, SocialAccountRefDto } from '../social-feed';
import { SOCIAL_NETWORKS, SocialNetworkName } from '../../core/social-network';

export interface SocialAccountGroup {
  readonly accountId: number;
  readonly handle: string | null;
  readonly subscriptions: readonly SubscriptionDto[];
}

export interface SocialProfiles {
  /** Alphabetically: the people followed rather than an account's own feeds. */
  readonly subscriptions: readonly SubscriptionDto[];
  readonly unreadCount: number;
  readonly entryCount: number;
}

export interface SocialSection {
  readonly network: SocialNetworkName;
  readonly accounts: readonly SocialAccountGroup[];
  /** Every connected account of the network, by id: the head's menu opens each one's picker. */
  readonly networkAccounts: readonly SocialAccountRefDto[];
  readonly profiles: SocialProfiles;
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
  list: 1,
  custom_feed: 2,
};

export function isSocialFeed(subscription: SubscriptionDto): boolean {
  return Boolean(subscription.social);
}

export function isProfileFeed(subscription: SubscriptionDto): boolean {
  return subscription.social?.kind === 'profile';
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

/** One section per network holding a social feed: private feeds per account by id, each Home first, then the profiles. */
export function buildSocialSections(
  subscriptions: readonly SubscriptionDto[],
  connectedAccounts: readonly SocialAccountRefDto[],
): SocialSection[] {
  const handles = new Map(connectedAccounts.map((account) => [account.id, account.handle]));
  return SOCIAL_NETWORKS.map((network) => {
    const ofNetwork = subscriptions.filter((subscription) => networkOf(subscription) === network);
    const accounts = accountGroups(ofNetwork.filter(isPrivateFeed), handles);
    return {
      network,
      accounts,
      networkAccounts: connectedAccounts
        .filter((account) => account.network === network)
        .sort((left, right) => left.id - right.id),
      profiles: socialProfiles(
        ofNetwork.filter(
          (subscription) => !isPrivateFeed(subscription) && !isSavedFeed(subscription),
        ),
      ),
      saved:
        ofNetwork.find(
          (subscription) => isSavedFeed(subscription) && !isEmptySavedFeed(subscription),
        ) ?? null,
      unreadCount: sum(ofNetwork, (subscription) => subscription.unreadCount),
      entryCount: sum(ofNetwork, (subscription) => subscription.entryCount),
      showsHandles: accounts.length > 1,
    };
  }).filter(
    (section) =>
      section.accounts.length > 0 ||
      section.networkAccounts.length > 0 ||
      section.profiles.subscriptions.length > 0 ||
      section.saved !== null,
  );
}

export function socialProfiles(subscriptions: readonly SubscriptionDto[]): SocialProfiles {
  const sorted = [...subscriptions].sort((left, right) => left.title.localeCompare(right.title));
  return {
    subscriptions: sorted,
    unreadCount: sum(sorted, (subscription) => subscription.unreadCount),
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
