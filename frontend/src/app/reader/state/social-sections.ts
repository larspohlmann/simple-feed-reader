import { SubscriptionDto } from '../models';
import { SocialAccountRefDto, SocialNetworkName } from '../social-feed';

export interface SocialAccountGroup {
  readonly accountId: number;
  /** null when the answer named no such account, e.g. one disconnected since the list loaded. */
  readonly handle: string | null;
  readonly subscriptions: readonly SubscriptionDto[];
}

export interface SocialSection {
  readonly network: SocialNetworkName;
  readonly accounts: readonly SocialAccountGroup[];
}

const NETWORKS: readonly SocialNetworkName[] = ['bluesky', 'mastodon'];

const KIND_ORDER: Readonly<Record<'home' | 'list' | 'custom_feed', number>> = {
  home: 0,
  list: 1,
  custom_feed: 2,
};

export function isPrivateFeed(subscription: SubscriptionDto): boolean {
  return Boolean(subscription.social);
}

/** One section per network that holds a private feed; its accounts by id; each account's Home first. */
export function buildSocialSections(
  subscriptions: readonly SubscriptionDto[],
  accounts: readonly SocialAccountRefDto[],
): SocialSection[] {
  const handles = new Map(accounts.map((account) => [account.id, account.handle]));
  return NETWORKS.map((network) => ({
    network,
    accounts: accountGroups(
      subscriptions.filter((subscription) => subscription.social?.network === network),
      handles,
    ),
  })).filter((section) => section.accounts.length > 0);
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
