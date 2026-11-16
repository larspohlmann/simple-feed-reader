import { inject, Injectable } from '@angular/core';
import { EntryDto } from '../models';
import { EntriesStore } from '../state/entries.store';
import { EntryStateActions } from '../shell/entry-state-actions.service';
import { SubscriptionsStore } from '../state/subscriptions.store';

/** Hide on a Trending card (#2017): the card leaves in place (#2066), comes back if the hide fails. */
@Injectable()
export class TrendingHideAction {
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly rows = inject(EntryStateActions);

  run(entry: EntryDto): void {
    this.entries.hideTrendingStory(entry.id, {
      onStarted: () => this.rows.markLeaving(entry.id),
      onSaved: () => this.subscriptions.reloadCountsQuietly(),
      onFailed: () => this.rows.stayInList(entry.id),
    });
  }
}
