import { Injectable, computed, inject } from '@angular/core';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { OpenStory } from '../state/open-story';
import { TrendingFollowAction } from '../trending/trending-follow-action';
import { StoryListContext } from './story-layer/story-list-context';

/** Following the open Trending story from its layer's bar (#2128, #2329). */
@Injectable()
export class StoryFollowing {
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly openStory = inject(OpenStory);
  private readonly followAction = inject(TrendingFollowAction);
  private readonly selection = inject(StoryListContext).selection;

  readonly following = computed(() =>
    this.followedStories.isFollowed(this.openStoryOf(this.selection().id)),
  );

  toggleOpen(): void {
    this.followAction.toggle(this.openStoryOf(this.selection().id));
  }

  private openStoryOf(id: number): { id: number; followed: boolean } {
    return { id, followed: this.openStory.followedOnServer(id) };
  }
}
