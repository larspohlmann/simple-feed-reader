import { Injectable, inject } from '@angular/core';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { EntriesStore } from '../../state/entries.store';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { ExclusionSurface } from '../../feed-exclusions';

/** What only a reload re-learns: saved-search badges and counts, and a list a switch changed, and the followed Trending stories after a Trending switch. */
@Injectable({ providedIn: 'root' })
export class SubscriptionReload {
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly entries = inject(EntriesStore);
  private readonly followedStories = inject(FollowedStoriesStore);

  afterWrite(changed: readonly ExclusionSurface[]): void {
    this.subscriptions.reloadQuietly();
    if (changed.includes('savedSearches')) this.savedSearches.load();
    if (changed.includes('trending')) this.followedStories.load();
    changed.forEach((surface) => this.entries.reloadIfShowing(surface));
  }
}
