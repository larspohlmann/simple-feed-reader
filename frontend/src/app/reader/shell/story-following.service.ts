import { Injectable, computed, inject } from '@angular/core';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { OpenStory } from '../state/open-story';
import { TrendingFollowAction } from '../trending/trending-follow-action';
import { StoryOverlay } from './story-overlay.service';

/** Following the open Trending story from its layer's bar (#2128, #2329). */
@Injectable()
export class StoryFollowing {
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly openStory = inject(OpenStory);
  private readonly followAction = inject(TrendingFollowAction);
  private readonly storyId = inject(StoryOverlay).storyId;

  readonly following = computed(() => {
    const id = this.storyId();
    return id !== null && this.followedStories.isFollowed(this.openStoryOf(id));
  });

  toggleOpen(): void {
    const id = this.storyId();
    if (id !== null) this.followAction.toggle(this.openStoryOf(id));
  }

  private openStoryOf(id: number): { id: number; followed: boolean } {
    return { id, followed: this.openStory.followedOnServer(id) };
  }
}
