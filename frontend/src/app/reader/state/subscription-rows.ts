import { Injectable, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import {
  isUnrenamedSavedFeed,
  isUnrenamedSearch,
  savedFeedLabel,
  timelineLabel,
} from '../feeds/display-title';
import { SubscriptionDto } from '../models';
import { networkOf } from './social-sections';

/**
 * The user's subscriptions, held once. SubscriptionsStore loads and patches them; a component that only reads a
 * feed injects this instead, and so does without the store's HTTP dependencies.
 */
@Injectable({ providedIn: 'root' })
export class SubscriptionRows {
  private readonly i18n = inject(TranslocoService);
  private readonly activeLang = toSignal(this.i18n.langChanges$, {
    initialValue: this.i18n.getActiveLang(),
  });

  /** As the server sent them: the rows an optimistic edit patches. */
  readonly stored = signal<SubscriptionDto[]>([]);

  /**
   * The rows every surface shows: an unrenamed timeline or saved feed is titled in the active language, an unrenamed
   * search by its current query.
   */
  readonly all = computed(() => {
    // translate() is one-shot: reading the language re-resolves the titles on a switch.
    this.activeLang();
    return this.stored().map((subscription) => this.withDisplayTitle(subscription));
  });

  private readonly byId = computed(
    () => new Map(this.all().map((subscription) => [subscription.id, subscription])),
  );

  find(subscriptionId: number): SubscriptionDto | null {
    return this.byId().get(subscriptionId) ?? null;
  }

  private withDisplayTitle(subscription: SubscriptionDto): SubscriptionDto {
    if (isUnrenamedSearch(subscription))
      return { ...subscription, title: subscription.social.query };
    if (isUnrenamedSavedFeed(subscription)) {
      return { ...subscription, title: savedFeedLabel(this.i18n, networkOf(subscription)) };
    }
    const timelineTitle = timelineLabel(this.i18n, subscription);
    return timelineTitle === null ? subscription : { ...subscription, title: timelineTitle };
  }
}
