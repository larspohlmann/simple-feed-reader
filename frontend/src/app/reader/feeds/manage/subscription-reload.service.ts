import { Injectable, inject } from '@angular/core';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { EntriesStore } from '../../state/entries.store';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { ExclusionSurface } from '../../feed-exclusions';
import { LabelsStore } from '../../labels/labels.store';

/** Reloads what a subscription write changed. */
@Injectable({ providedIn: 'root' })
export class SubscriptionReload {
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly entries = inject(EntriesStore);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly labels = inject(LabelsStore);

  afterWrite(changed: readonly ExclusionSurface[]): void {
    this.subscriptions.reloadQuietly();
    if (changed.includes('savedSearches')) this.savedSearches.load();
    if (changed.includes('trending')) this.followedStories.load();
    changed.forEach((surface) => this.entries.reloadIfShowing(surface));
  }

  /** A subscribe or unsubscribe moves which favorites and kept posts count, the label tallies' among them. */
  afterMembershipChange(): void {
    this.subscriptions.reloadQuietly();
    this.labels.reload();
  }
}
