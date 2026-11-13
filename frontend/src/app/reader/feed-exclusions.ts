import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto, TagInclusion } from './models';
import type { ManageActions } from './feeds/manage/manage-actions.service';

export type ExclusionSurface = 'allItems' | 'forYou' | 'savedSearches' | 'trending';

/** Each surface's own switch, on a feed and on a tag alike, in title order. */
export const INCLUSION_FLAG: Readonly<Record<ExclusionSurface, keyof TagInclusion>> = {
  allItems: 'includeInAllItems',
  forYou: 'includeInForYou',
  savedSearches: 'includeInSavedSearches',
  trending: 'includeInTrending',
};

export const EXCLUSION_SURFACES = Object.keys(INCLUSION_FLAG) as readonly ExclusionSurface[];

export const SURFACE_LABEL_KEYS: Readonly<Record<ExclusionSurface, string>> = {
  allItems: 'reader.allItems',
  forYou: 'reader.forYou',
  savedSearches: 'reader.savedSearches',
  trending: 'reader.trending',
};

/** One per-feed inclusion switch, as every feed menu and sheet offers it. */
export interface FeedFlagToggle {
  readonly surface: ExclusionSurface;
  readonly dataTest: string;
  readonly includeKey: string;
  readonly isIncluded: (subscription: SubscriptionDto) => boolean;
  readonly set: (manage: ManageActions, subscription: SubscriptionDto, included: boolean) => void;
}

function includedIn(surface: ExclusionSurface): (subscription: SubscriptionDto) => boolean {
  return (subscription) => subscription[INCLUSION_FLAG[surface]];
}

export const FEED_FLAG_TOGGLES: readonly FeedFlagToggle[] = [
  {
    surface: 'allItems',
    dataTest: 'toggle-all-items',
    includeKey: 'reader.includeInAllItems',
    isIncluded: includedIn('allItems'),
    set: (manage, subscription, included) => manage.setIncludeInAllItems(subscription, included),
  },
  {
    surface: 'forYou',
    dataTest: 'toggle-for-you',
    includeKey: 'reader.includeInForYou',
    isIncluded: includedIn('forYou'),
    set: (manage, subscription, included) => manage.setIncludeInForYou(subscription, included),
  },
  {
    surface: 'savedSearches',
    dataTest: 'toggle-saved-searches',
    includeKey: 'reader.includeInSavedSearches',
    isIncluded: includedIn('savedSearches'),
    set: (manage, subscription, included) =>
      manage.setIncludeInSavedSearches(subscription, included),
  },
  {
    surface: 'trending',
    dataTest: 'toggle-trending',
    includeKey: 'reader.includeInTrending',
    isIncluded: includedIn('trending'),
    set: (manage, subscription, included) => manage.setIncludeInTrending(subscription, included),
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
  return EXCLUSION_SURFACES.filter((surface) => !inclusion[INCLUSION_FLAG[surface]]);
}

export function exclusionTitle(
  i18n: TranslocoService,
  surfaces: readonly ExclusionSurface[],
): string {
  const names = surfaces.map((surface) => i18n.translate(SURFACE_LABEL_KEYS[surface])).join(', ');
  return i18n.translate('reader.excludedFrom', { surfaces: names });
}
