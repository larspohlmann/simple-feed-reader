import { inject, Injectable } from '@angular/core';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { TrendingStoryDto } from './trending.models';

/** Follow on a Trending card (#2128): a toggle the followed stories show at once and put back if the request
 *  fails, with the error banner and its retry. */
@Injectable()
export class TrendingFollowAction {
  private readonly entries = inject(EntriesStore);
  private readonly followedStories = inject(FollowedStoriesStore);

  toggle(story: TrendingStoryDto): void {
    this.entries.runReported(
      this.followedStories.setFollowing(story.id, !this.followedStories.isFollowed(story)),
      () => this.toggle(story),
    );
  }
}
