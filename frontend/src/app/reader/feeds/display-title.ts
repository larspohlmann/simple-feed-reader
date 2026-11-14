import { TranslocoService } from '@jsverse/transloco';
import { SubscriptionDto } from '../models';
import { SocialNetworkName } from '../../core/social-network';
import { SocialFeedRefDto } from '../social-feed';
import { SearchSubscription, isSavedFeed, isSearchFeed } from '../state/social-sections';

const HOME_TIMELINE = { home: 'reader.socialHome' } as const;

const TIMELINE_LABEL_KEYS: Readonly<
  Record<SocialNetworkName, Readonly<Partial<Record<SocialFeedRefDto['kind'], string>>>>
> = {
  bluesky: HOME_TIMELINE,
  mastodon: HOME_TIMELINE,
  lemmy: {
    home: 'reader.socialSubscribed',
    local: 'settings.social.importLemmyLocal',
    all: 'settings.social.importLemmyAll',
  },
};

function unrenamedTimelineKey(subscription: SubscriptionDto): string | null {
  const social = subscription.social;
  if (!social || subscription.customTitle !== null) return null;
  return TIMELINE_LABEL_KEYS[social.network][social.kind] ?? null;
}

export function isUnrenamedTimeline(subscription: SubscriptionDto): boolean {
  return unrenamedTimelineKey(subscription) !== null;
}

export function isUnrenamedSearch(
  subscription: SubscriptionDto,
): subscription is SearchSubscription {
  return isSearchFeed(subscription) && subscription.customTitle === null;
}

/** An unrenamed timeline follows the language, an unrenamed search its query. */
export function titleFollowsSource(subscription: SubscriptionDto): boolean {
  return isUnrenamedTimeline(subscription) || isUnrenamedSearch(subscription);
}

/** The title an unrenamed timeline shows in the active language; null for any other feed. */
export function timelineLabel(i18n: TranslocoService, subscription: SubscriptionDto): string | null {
  const key = unrenamedTimelineKey(subscription);
  const network = subscription.social?.network;
  return key === null || network === undefined
    ? null
    : i18n.translate(key, { network: i18n.translate(`reader.socialNetworkShort.${network}`) });
}

export function isUnrenamedSavedFeed(subscription: SubscriptionDto): boolean {
  return isSavedFeed(subscription) && subscription.customTitle === null;
}

export function savedFeedLabel(i18n: TranslocoService, network: SocialNetworkName | null): string {
  return i18n.translate(`reader.savedFrom.${network ?? 'feeds'}`);
}
