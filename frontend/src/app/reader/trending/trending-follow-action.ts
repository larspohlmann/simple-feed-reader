import { inject, Injectable } from '@angular/core';
import { EntryDto } from '../models';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';

/** Follow on a Trending card (#2128): a toggle the followed stories show at once and put back if the request
 *  fails, with the error banner and its retry. */
@Injectable()
export class TrendingFollowAction {
  private readonly entries = inject(EntriesStore);
  private readonly followedStories = inject(FollowedStoriesStore);

  run(entry: EntryDto): void {
    const story = entry.story;
    if (!story) return;
    this.entries.runReported(
      this.followedStories.setFollowing(story.id, !this.followedStories.isFollowed(story)),
      () => this.run(entry),
    );
  }
}
