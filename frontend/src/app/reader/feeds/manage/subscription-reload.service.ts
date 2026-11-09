import { Injectable, inject } from '@angular/core';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SubscriptionFlags } from '../../subscription-bulk';

/** The saved-search flag moves saved-search badges and counts, which only a reload re-learns. */
@Injectable({ providedIn: 'root' })
export class SubscriptionReload {
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);

  afterWrite(written: Pick<SubscriptionFlags, 'includeInSavedSearches'>): void {
    this.subscriptions.reloadQuietly();
    if (written.includeInSavedSearches !== undefined) this.savedSearches.load();
  }
}
