import { inject, Injectable } from '@angular/core';
import { ListContext } from '../state/list-context';
import { leadOf } from '../state/list-item';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { TrendingStoryDto } from './trending.models';

/** Hide on a Trending card (#2017): the card leaves in place (#2066), comes back if the hide fails. */
@Injectable()
export class TrendingHideAction {
  private readonly list = inject(ListContext);
  private readonly subscriptions = inject(SubscriptionsStore);

  run(story: TrendingStoryDto): void {
    this.list.entries.hideTrendingStory(story, {
      onStarted: () => this.list.markLeaving(leadOf(story).id),
      onSaved: () => this.subscriptions.reloadCountsQuietly(),
      onFailed: () => this.list.stayInList(leadOf(story).id),
    });
  }
}
