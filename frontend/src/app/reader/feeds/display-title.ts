import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto } from '../models';
import { SocialNetworkName } from '../../core/social-network';

export function isUnrenamedHome(subscription: SubscriptionDto): boolean {
  return subscription.social?.kind === 'home' && subscription.customTitle === null;
}

export function homeLabel(i18n: TranslocoService, network: SocialNetworkName): string {
  return i18n.translate('reader.socialHome', {
    network: i18n.translate(`reader.socialNetworkShort.${network}`),
  });
}
