import { Injectable, computed, signal } from '@angular/core';
import { SocialNetworkName } from '../../core/social-network';
import { SubscriptionDto } from '../models';

/**
 * The user's subscriptions, held once. SubscriptionsStore loads and patches them; a component that only reads a
 * feed injects this instead, and so does without the store's HTTP dependencies.
 */
@Injectable({ providedIn: 'root' })
export class SubscriptionRows {
  readonly all = signal<SubscriptionDto[]>([]);

  private readonly byId = computed(
    () => new Map(this.all().map((subscription) => [subscription.id, subscription])),
  );

  find(subscriptionId: number): SubscriptionDto | null {
    return this.byId().get(subscriptionId) ?? null;
  }

  networkOf(subscriptionId: number): SocialNetworkName | null {
    return this.find(subscriptionId)?.social?.network ?? null;
  }
}
