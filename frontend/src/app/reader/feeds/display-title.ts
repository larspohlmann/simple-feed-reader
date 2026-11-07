import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto } from '../models';
import { SocialNetworkName } from '../../core/social-network';
import { SearchSubscription, isSavedFeed, isSearchFeed } from '../state/social-sections';

export function isUnrenamedHome(subscription: SubscriptionDto): boolean {
  return subscription.social?.kind === 'home' && subscription.customTitle === null;
}

export function isUnrenamedSearch(
  subscription: SubscriptionDto,
): subscription is SearchSubscription {
  return isSearchFeed(subscription) && subscription.customTitle === null;
}

/** An unrenamed Home follows the language, an unrenamed search its query. */
export function titleFollowsSource(subscription: SubscriptionDto): boolean {
  return isUnrenamedHome(subscription) || isUnrenamedSearch(subscription);
}

export function homeLabel(i18n: TranslocoService, network: SocialNetworkName): string {
  return i18n.translate('reader.socialHome', {
    network: i18n.translate(`reader.socialNetworkShort.${network}`),
  });
}

export function isUnrenamedSavedFeed(subscription: SubscriptionDto): boolean {
  return isSavedFeed(subscription) && subscription.customTitle === null;
}

export function savedFeedLabel(i18n: TranslocoService, network: SocialNetworkName | null): string {
  return i18n.translate(`reader.savedFrom.${network ?? 'feeds'}`);
}
