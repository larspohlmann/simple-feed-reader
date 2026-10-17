import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto } from '../../models';
import { isSavedFeed } from '../../state/social-sections';
import { savedFeedLabel } from '../display-title';

export function unsubscribeMessage(i18n: TranslocoService, subscription: SubscriptionDto): string {
  if (isSavedFeed(subscription)) {
    return i18n.translate('manage.unsubscribeSavedMessage', { title: subscription.title });
  }
  const moves = i18n.translate('manage.savedPostsMove', {
    saved: savedFeedLabel(i18n, subscription.social?.network ?? null),
  });
  return `${i18n.translate('manage.unsubscribeMessage', { title: subscription.title })} ${moves}`;
}

/** What a bulk unsubscribe adds to its confirmation: where saved posts go, which saved feeds are deleted. */
export function bulkSavedNote(
  i18n: TranslocoService,
  subscriptions: readonly SubscriptionDto[],
): string {
  const savedOnes = subscriptions.filter(isSavedFeed);
  const destinations = new Set(
    subscriptions
      .filter((subscription) => !isSavedFeed(subscription))
      .map((subscription) => subscription.social?.network ?? null),
  );
  const parts: string[] = [];
  if (destinations.size === 1) {
    const [network] = destinations;
    const movesToNewFeed = savedOnes.some(
      (subscription) => (subscription.social?.network ?? null) === network,
    );
    parts.push(
      i18n.translate(movesToNewFeed ? 'manage.savedPostsMoveNew' : 'manage.savedPostsMove', {
        saved: savedFeedLabel(i18n, network),
      }),
    );
  } else if (destinations.size > 1) {
    parts.push(i18n.translate('manage.bulk.savedPostsMoveMany'));
  }
  if (savedOnes.length > 0) {
    parts.push(
      i18n.translate('manage.bulk.savedPostsDeleted', {
        named: savedOnes.map((subscription) => subscription.title).join(', '),
      }),
    );
  }
  return parts.map((part) => ` ${part}`).join('');
}
