import { inject, Injectable } from '@angular/core';
import { ListContext } from '../state/list-context';
import { isStory, leadOf } from '../state/list-item';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { StoryRef } from './trending.models';

/** Hide on a Trending story (#2017): its card, when the list holds it, leaves in place (#2066) and comes back if
 *  the hide fails. */
@Injectable()
export class TrendingHideAction {
  private readonly list = inject(ListContext);
  private readonly subscriptions = inject(SubscriptionsStore);

  run(story: StoryRef): void {
    const card = this.list.entries.items().find((item) => isStory(item) && item.id === story.id);
    const cardId = card ? leadOf(card).id : null;
    this.list.entries.hideTrendingStory(story, {
      onStarted: () => {
        if (cardId !== null) this.list.markLeaving(cardId);
      },
      onSaved: () => this.subscriptions.reloadCountsQuietly(),
      onFailed: () => {
        if (cardId !== null) this.list.stayInList(cardId);
      },
    });
  }
}
