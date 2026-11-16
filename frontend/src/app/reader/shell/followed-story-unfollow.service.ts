import { Injectable, computed, effect, inject, untracked } from '@angular/core';
import { Router } from '@angular/router';
import { selectedFollowedStoryIdOf, selectionQueryParams } from '../query/query';
import { EntriesStore } from '../state/entries.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { ReaderRouteState } from './reader-route-state.service';

const NOT_FOLLOWED = 'trending_story_not_followed';
const TRENDING = { queryParams: selectionQueryParams({ view: 'trending' }) };

/** Unfollowing a Trending story away from its card (#2128): from the sidebar row's menu or the story's list
 *  header. A story on screen that is no longer followed, as after an unfollow in another tab, gives way to
 *  Trending, as the unfollow does. */
@Injectable()
export class FollowedStoryUnfollow {
  private readonly router = inject(Router);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly entries = inject(EntriesStore);
  private readonly selection = inject(ReaderRouteState).selection;

  readonly activeId = computed(() => selectedFollowedStoryIdOf(this.selection()));
  readonly viewing = computed(() => this.activeId() !== null);

  constructor() {
    effect(() => {
      if (!this.viewing() || this.entries.error()?.type !== NOT_FOLLOWED) return;
      untracked(() => this.leaveStoryNoLongerFollowed());
    });
  }

  unfollowOpen(): void {
    const id = this.activeId();
    if (id !== null) this.unfollow(id);
  }

  unfollow(id: number): void {
    this.followedStories.unfollow(id, () => {
      this.entries.markStoryUnfollowedLocally(id);
      if (this.activeId() === id) void this.router.navigate(['/'], TRENDING);
    });
  }

  private leaveStoryNoLongerFollowed(): void {
    this.entries.dismissError();
    this.followedStories.load();
    void this.router.navigate(['/'], { ...TRENDING, replaceUrl: true });
  }
}
