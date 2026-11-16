import { inject, Injectable } from '@angular/core';
import { EntryDto } from '../models';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';

/** Follow on a Trending card (#2128): a toggle, shown followed at once and put back if the request fails. */
@Injectable()
export class TrendingFollowAction {
  private readonly entries = inject(EntriesStore);
  private readonly followedStories = inject(FollowedStoriesStore);

  run(entry: EntryDto): void {
    this.entries.setStoryFollowed(entry.id, !entry.story?.followed, {
      onSaved: () => this.followedStories.load(),
    });
  }
}
