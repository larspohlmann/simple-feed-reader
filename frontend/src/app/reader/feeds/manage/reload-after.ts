import { Observable } from 'rxjs';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SubscriptionFlags } from '../../subscription-bulk';

/** Reloads whether the write landed or not, so the store shows what the server kept. */
export function reloadAfter(write$: Observable<unknown>, reload: () => void): void {
  write$.subscribe({ next: reload, error: reload });
}

/** The saved-search flag moves saved-search badges and counts, which only a reload re-learns. */
export function reloadAfterFlagWrite(
  flags: SubscriptionFlags,
  subscriptions: SubscriptionsStore,
  savedSearches: SavedSearchesStore,
): void {
  subscriptions.reloadQuietly();
  if (flags.includeInSavedSearches !== undefined) savedSearches.load();
}
