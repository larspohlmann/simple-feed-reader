import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto, TagInclusion } from './models';
import type { ManageActions } from './feeds/manage/manage-actions.service';

export type ExclusionSurface = 'allItems' | 'forYou' | 'savedSearches';

export const SURFACE_LABEL_KEYS: Readonly<Record<ExclusionSurface, string>> = {
  allItems: 'reader.allItems',
  forYou: 'reader.forYou',
  savedSearches: 'reader.savedSearches',
};

/** One per-feed inclusion switch, as every feed menu and sheet offers it. */
export interface FeedFlagToggle {
  readonly surface: ExclusionSurface;
  readonly dataTest: string;
  readonly includeKey: string;
  readonly isIncluded: (subscription: SubscriptionDto) => boolean;
  readonly set: (manage: ManageActions, subscription: SubscriptionDto, included: boolean) => void;
}

export const FEED_FLAG_TOGGLES: readonly FeedFlagToggle[] = [
  {
    surface: 'allItems',
    dataTest: 'toggle-all-items',
    includeKey: 'reader.includeInAllItems',
    isIncluded: (subscription) => subscription.includeInAllItems,
    set: (manage, subscription, included) => manage.setIncludeInAllItems(subscription, included),
  },
  {
    surface: 'forYou',
    dataTest: 'toggle-for-you',
    includeKey: 'reader.includeInForYou',
    isIncluded: (subscription) => subscription.includeInForYou,
    set: (manage, subscription, included) => manage.setIncludeInForYou(subscription, included),
  },
  {
    surface: 'savedSearches',
    dataTest: 'toggle-saved-searches',
    includeKey: 'reader.includeInSavedSearches',
    isIncluded: (subscription) => subscription.includeInSavedSearches,
    set: (manage, subscription, included) =>
      manage.setIncludeInSavedSearches(subscription, included),
  },
];

export function flipFeedFlag(
  manage: ManageActions,
  toggle: FeedFlagToggle,
  subscription: SubscriptionDto,
): void {
  toggle.set(manage, subscription, !toggle.isIncluded(subscription));
}

/** The surfaces a feed's or a tag's own switches leave it out of. */
export function ownExcludedSurfaces(inclusion: TagInclusion): ExclusionSurface[] {
  const surfaces: ExclusionSurface[] = [];
  if (!inclusion.includeInAllItems) surfaces.push('allItems');
  if (!inclusion.includeInForYou) surfaces.push('forYou');
  if (!inclusion.includeInSavedSearches) surfaces.push('savedSearches');
  return surfaces;
}

export function exclusionTitle(
  i18n: TranslocoService,
  surfaces: readonly ExclusionSurface[],
): string {
  const names = surfaces.map((surface) => i18n.translate(SURFACE_LABEL_KEYS[surface])).join(', ');
  return i18n.translate('reader.excludedFrom', { surfaces: names });
}
