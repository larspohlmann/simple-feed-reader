import { Injectable, computed, effect, inject, untracked } from '@angular/core';
import { Router } from '@angular/router';
import { selectedStoryIdOf, selectionQueryParams } from '../query/query';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { OpenStory } from '../state/open-story';
import { TrendingFollowAction } from '../trending/trending-follow-action';
import { ReaderRouteState } from './reader-route-state.service';

const NOT_FOUND = 'trending_story_not_found';
const TRENDING = { queryParams: selectionQueryParams({ view: 'trending' }) };

/** Following a Trending story away from its card (#2128, #2329): the story list header's toggle, and unfollowing
 *  from the sidebar row's menu. A story on screen that is gone gives way to Trending. */
@Injectable()
export class StoryFollowing {
  private readonly router = inject(Router);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly openStory = inject(OpenStory);
  private readonly followAction = inject(TrendingFollowAction);
  private readonly entries = inject(EntriesStore);
  private readonly selection = inject(ReaderRouteState).selection;

  readonly activeId = computed(() => selectedStoryIdOf(this.selection()));
  readonly viewing = computed(() => this.activeId() !== null);
  readonly following = computed(() => {
    const id = this.activeId();
    return id !== null && this.followedStories.isFollowed(this.openStoryOf(id));
  });

  constructor() {
    effect(() => {
      if (!this.viewing() || this.entries.error()?.type !== NOT_FOUND) return;
      untracked(() => this.leaveStoryThatIsGone());
    });
  }

  toggleOpen(): void {
    const id = this.activeId();
    if (id !== null) this.followAction.toggle(this.openStoryOf(id));
  }

  unfollow(id: number): void {
    this.followedStories.unfollow(id);
  }

  private openStoryOf(id: number): { id: number; followed: boolean } {
    return { id, followed: this.openStory.followedOnServer(id) };
  }

  private leaveStoryThatIsGone(): void {
    this.entries.dismissError();
    this.followedStories.load();
    void this.router.navigate(['/'], { ...TRENDING, replaceUrl: true });
  }
}
