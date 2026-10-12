import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto } from '../models';
import { SocialNetworkName } from '../social-feed';

export function isUnrenamedHome(subscription: SubscriptionDto): boolean {
  return subscription.social?.kind === 'home' && subscription.customTitle === null;
}

export function homeLabel(i18n: TranslocoService, network: SocialNetworkName): string {
  return i18n.translate('reader.socialHome', {
    network: i18n.translate(`reader.socialNetworkShort.${network}`),
  });
}

export function displayTitle(
  subscription: SubscriptionDto,
  homeLabelOf: (network: SocialNetworkName) => string,
): string {
  const social = subscription.social;
  return social && isUnrenamedHome(subscription) ? homeLabelOf(social.network) : subscription.title;
}
