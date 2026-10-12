import { SubscriptionDto } from '../models';

export function isUnrenamedHome(subscription: SubscriptionDto): boolean {
  return subscription.social?.kind === 'home' && subscription.customTitle === null;
}

export function displayTitle(subscription: SubscriptionDto, homeLabel: string): string {
  return isUnrenamedHome(subscription) ? homeLabel : subscription.title;
}
