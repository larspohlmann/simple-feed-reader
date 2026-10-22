import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto } from './models';
import { SocialNetworkName } from '../core/social-network';

export type ExclusionSurface = 'allItems' | 'forYou' | 'savedSearches';

const SURFACE_LABEL_KEYS: Readonly<Record<ExclusionSurface, string>> = {
  allItems: 'reader.allItems',
  forYou: 'reader.forYou',
  savedSearches: 'reader.savedSearches',
};

/** A feed of a network the user has not included counts as switched off for saved searches. */
export function excludedSurfaces(
  subscription: SubscriptionDto,
  includedNetworks: ReadonlySet<SocialNetworkName>,
): ExclusionSurface[] {
  const network = subscription.social?.network ?? null;
  const networkLeftOut = network !== null && !includedNetworks.has(network);
  const surfaces: ExclusionSurface[] = [];
  if (!subscription.includeInAllItems) surfaces.push('allItems');
  if (!subscription.includeInForYou) surfaces.push('forYou');
  if (!subscription.includeInSavedSearches || networkLeftOut) surfaces.push('savedSearches');
  return surfaces;
}

export function exclusionTitle(
  i18n: TranslocoService,
  surfaces: readonly ExclusionSurface[],
): string {
  const names = surfaces.map((surface) => i18n.translate(SURFACE_LABEL_KEYS[surface])).join(', ');
  return i18n.translate('reader.excludedFrom', { surfaces: names });
}
