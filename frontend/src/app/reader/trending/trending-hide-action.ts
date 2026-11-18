import { inject, Injectable } from '@angular/core';
import { EntriesStore } from '../state/entries.store';
import { EntryStateActions } from '../shell/entry-state-actions.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { TrendingStoryDto } from './trending.models';

/** Hide on a Trending card (#2017): the card leaves in place (#2066), comes back if the hide fails. */
@Injectable()
export class TrendingHideAction {
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly rows = inject(EntryStateActions);

  run(story: TrendingStoryDto): void {
    this.entries.hideTrendingStory(story, {
      onStarted: () => this.rows.markLeaving(story.leadEntryId),
      onSaved: () => this.subscriptions.reloadCountsQuietly(),
      onFailed: () => this.rows.stayInList(story.leadEntryId),
    });
  }
}
