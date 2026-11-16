import { inject, Injectable } from '@angular/core';
import { EntryDto } from '../models';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';

/** Follow on a Trending card (#2128): a toggle, shown followed at once and put back if the request fails. A new
 *  follow reloads the followed stories for its headline and count; an unfollow only drops its row. */
@Injectable()
export class TrendingFollowAction {
  private readonly entries = inject(EntriesStore);
  private readonly followedStories = inject(FollowedStoriesStore);

  run(entry: EntryDto): void {
    const story = entry.story;
    if (!story) return;
    const following = !story.followed;
    this.entries.setStoryFollowed(entry.id, following, {
      onSaved: () =>
        following ? this.followedStories.load() : this.followedStories.removeUnfollowed(story.id),
    });
  }
}
