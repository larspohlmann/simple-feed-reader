import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto } from './models';
import { SocialNetworkName } from '../core/social-network';
import type { ManageActions } from './feeds/manage/manage-actions.service';

export type ExclusionSurface = 'allItems' | 'forYou' | 'savedSearches';

const SURFACE_LABEL_KEYS: Readonly<Record<ExclusionSurface, string>> = {
  allItems: 'reader.allItems',
  forYou: 'reader.forYou',
  savedSearches: 'reader.savedSearches',
};

/** One per-feed inclusion switch, as every feed menu and sheet offers it. */
export interface FeedFlagToggle {
  readonly surface: ExclusionSurface;
  readonly dataTest: string;
  readonly excludeKey: string;
  readonly includeKey: string;
  readonly isIncluded: (subscription: SubscriptionDto) => boolean;
  readonly set: (manage: ManageActions, subscription: SubscriptionDto, included: boolean) => void;
}

export const FEED_FLAG_TOGGLES: readonly FeedFlagToggle[] = [
  {
    surface: 'allItems',
    dataTest: 'toggle-all-items',
    excludeKey: 'reader.excludeFromAllItems',
    includeKey: 'reader.includeInAllItems',
    isIncluded: (subscription) => subscription.includeInAllItems,
    set: (manage, subscription, included) => manage.setIncludeInAllItems(subscription, included),
  },
  {
    surface: 'forYou',
    dataTest: 'toggle-for-you',
    excludeKey: 'reader.excludeFromForYou',
    includeKey: 'reader.includeInForYou',
    isIncluded: (subscription) => subscription.includeInForYou,
    set: (manage, subscription, included) => manage.setIncludeInForYou(subscription, included),
  },
  {
    surface: 'savedSearches',
    dataTest: 'toggle-saved-searches',
    excludeKey: 'reader.excludeFromSavedSearches',
    includeKey: 'reader.includeInSavedSearches',
    isIncluded: (subscription) => subscription.includeInSavedSearches,
    set: (manage, subscription, included) =>
      manage.setIncludeInSavedSearches(subscription, included),
  },
];

/** An included feed offers to exclude it, an excluded one to include it. */
export function feedFlagLabelKey(toggle: FeedFlagToggle, subscription: SubscriptionDto): string {
  return toggle.isIncluded(subscription) ? toggle.excludeKey : toggle.includeKey;
}

export function flipFeedFlag(
  manage: ManageActions,
  toggle: FeedFlagToggle,
  subscription: SubscriptionDto,
): void {
  toggle.set(manage, subscription, !toggle.isIncluded(subscription));
}

/** The surfaces the feed's own switches leave it out of. */
export function ownExcludedSurfaces(subscription: SubscriptionDto): ExclusionSurface[] {
  const surfaces: ExclusionSurface[] = [];
  if (!subscription.includeInAllItems) surfaces.push('allItems');
  if (!subscription.includeInForYou) surfaces.push('forYou');
  if (!subscription.includeInSavedSearches) surfaces.push('savedSearches');
  return surfaces;
}

/** A feed of a network the user has not included counts as switched off for saved searches. */
export function excludedSurfaces(
  subscription: SubscriptionDto,
  includedNetworks: ReadonlySet<SocialNetworkName>,
): ExclusionSurface[] {
  const network = subscription.social?.network ?? null;
  const networkLeftOut = network !== null && !includedNetworks.has(network);
  const surfaces = ownExcludedSurfaces(subscription);
  if (networkLeftOut && !surfaces.includes('savedSearches')) surfaces.push('savedSearches');
  return surfaces;
}

export function exclusionTitle(
  i18n: TranslocoService,
  surfaces: readonly ExclusionSurface[],
): string {
  const names = surfaces.map((surface) => i18n.translate(SURFACE_LABEL_KEYS[surface])).join(', ');
  return i18n.translate('reader.excludedFrom', { surfaces: names });
}
