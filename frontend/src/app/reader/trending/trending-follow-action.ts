import { inject, Injectable } from '@angular/core';
import { ListContext } from '../state/list-context';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { TrendingStoryDto } from './trending.models';

/** Follow on a Trending card or a story's list (#2128, #2329): a toggle the followed stories show at once and put back if the request
 *  fails, with its list's error banner and retry. */
@Injectable()
export class TrendingFollowAction {
  private readonly entries = inject(ListContext).entries;
  private readonly followedStories = inject(FollowedStoriesStore);

  toggle(story: Pick<TrendingStoryDto, 'id' | 'followed'>): void {
    this.entries.runReported(
      this.followedStories.setFollowing(story.id, !this.followedStories.isFollowed(story)),
      () => this.toggle(story),
    );
  }
}
