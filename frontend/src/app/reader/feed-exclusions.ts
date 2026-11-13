import { computed } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import type { ActionSheetCheck } from '../shared/action-sheet/action-sheet.component';
import { EntryView, SubscriptionDto, TagInclusion } from './models';
import type { ManageActions } from './feeds/manage/manage-actions.service';

export type ExclusionSurface = 'allItems' | 'forYou' | 'savedSearches' | 'trending';

/** One surface a feed, a tag or a network can be switched out of. */
export interface SurfaceDescriptor {
  readonly surface: ExclusionSurface;
  /** The surface's own switch, on a feed and on a tag alike. */
  readonly flag: keyof TagInclusion;
  readonly includeKey: string;
  readonly labelKey: string;
  readonly dataTest: string;
  /** The entry list a switch changes, reloaded after a write while it shows; null where no list waits on it. */
  readonly listView: EntryView | null;
  /** A private feed is out of All items and For you by design. */
  readonly privateFeedsIncluded: boolean;
}

/** In title order: menus, sheets, markers and the Visibility matrix all list the surfaces in this order. */
export const SURFACE_DESCRIPTORS: readonly SurfaceDescriptor[] = [
  {
    surface: 'allItems',
    flag: 'includeInAllItems',
    includeKey: 'reader.includeInAllItems',
    labelKey: 'reader.allItems',
    dataTest: 'toggle-all-items',
    listView: null,
    privateFeedsIncluded: false,
  },
  {
    surface: 'forYou',
    flag: 'includeInForYou',
    includeKey: 'reader.includeInForYou',
    labelKey: 'reader.forYou',
    dataTest: 'toggle-for-you',
    listView: null,
    privateFeedsIncluded: false,
  },
  {
    surface: 'savedSearches',
    flag: 'includeInSavedSearches',
    includeKey: 'reader.includeInSavedSearches',
    labelKey: 'reader.savedSearches',
    dataTest: 'toggle-saved-searches',
    listView: null,
    privateFeedsIncluded: true,
  },
  {
    surface: 'trending',
    flag: 'includeInTrending',
    includeKey: 'reader.includeInTrending',
    labelKey: 'reader.trending',
    dataTest: 'toggle-trending',
    listView: 'trending',
    privateFeedsIncluded: true,
  },
];

export function bySurface<T>(
  pick: (descriptor: SurfaceDescriptor) => T,
): Record<ExclusionSurface, T> {
  return Object.fromEntries(
    SURFACE_DESCRIPTORS.map((descriptor) => [descriptor.surface, pick(descriptor)]),
  ) as Record<ExclusionSurface, T>;
}

export const SURFACE_DESCRIPTOR: Readonly<Record<ExclusionSurface, SurfaceDescriptor>> = bySurface(
  (descriptor) => descriptor,
);

export const INCLUSION_FLAG: Readonly<Record<ExclusionSurface, keyof TagInclusion>> = bySurface(
  ({ flag }) => flag,
);

export const EXCLUSION_SURFACES: readonly ExclusionSurface[] = SURFACE_DESCRIPTORS.map(
  ({ surface }) => surface,
);

export const SURFACE_LABEL_KEYS: Readonly<Record<ExclusionSurface, string>> = bySurface(
  ({ labelKey }) => labelKey,
);

export function isIncludedIn(inclusion: TagInclusion, surface: ExclusionSurface): boolean {
  return inclusion[INCLUSION_FLAG[surface]];
}

/** The surfaces a flag change touched, as a bulk body or an edit carries it. */
export function surfacesIn(flags: Partial<TagInclusion>): ExclusionSurface[] {
  return SURFACE_DESCRIPTORS.filter(({ flag }) => flags[flag] !== undefined).map(
    ({ surface }) => surface,
  );
}

export function flipFeedFlag(
  manage: ManageActions,
  surface: ExclusionSurface,
  subscription: SubscriptionDto,
): void {
  manage.setInclusion(subscription, surface, !isIncludedIn(subscription, surface));
}

/** The surfaces a feed's or a tag's own switches leave it out of. */
export function ownExcludedSurfaces(inclusion: TagInclusion): ExclusionSurface[] {
  return EXCLUSION_SURFACES.filter((surface) => !isIncludedIn(inclusion, surface));
}

export function exclusionTitle(
  i18n: TranslocoService,
  surfaces: readonly ExclusionSurface[],
): string {
  const names = surfaces.map((surface) => i18n.translate(SURFACE_LABEL_KEYS[surface])).join(', ');
  return i18n.translate('reader.excludedFrom', { surfaces: names });
}

/** One row's surface switches as sheet checks; `isIncluded` is read live, so a tick shows at once. */
export interface SurfaceSwitchRow {
  readonly isIncluded: (surface: ExclusionSurface) => boolean;
  readonly flip: (surface: ExclusionSurface) => void;
}

export function surfaceChecks(i18n: TranslocoService, row: SurfaceSwitchRow): ActionSheetCheck[] {
  return SURFACE_DESCRIPTORS.map(({ surface, includeKey }) => ({
    id: surface,
    label: i18n.translate(includeKey),
    checked: computed(() => row.isIncluded(surface)),
    toggle: () => row.flip(surface),
  }));
}
