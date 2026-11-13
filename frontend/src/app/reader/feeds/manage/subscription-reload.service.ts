import { Injectable, inject } from '@angular/core';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { EntriesStore } from '../../state/entries.store';
import { SubscriptionFlags } from '../../subscription-bulk';

/** What only a reload re-learns: saved-search badges and counts, and the Trending list. */
@Injectable({ providedIn: 'root' })
export class SubscriptionReload {
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly entries = inject(EntriesStore);

  afterWrite(
    written: Pick<SubscriptionFlags, 'includeInSavedSearches' | 'includeInTrending'>,
  ): void {
    this.subscriptions.reloadQuietly();
    if (written.includeInSavedSearches !== undefined) this.savedSearches.load();
    if (written.includeInTrending !== undefined) this.entries.reloadTrending();
  }
}
