import { Injectable, computed, inject } from '@angular/core';
import { NavigationExtras, Router } from '@angular/router';
import { ListPreferences } from '../list/list-preferences.service';
import { SelectionOf, sameSelection, trendingStorySelection } from '../query/query';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { ReaderRouteState } from './reader-route-state.service';

const ONTO_THE_LIST: NavigationExtras = { queryParamsHandling: 'preserve' };

/** A Trending story's list open over the list the reader was on, as an article is (#2329). The list underneath
 *  keeps its rows and its place; closing drops the story's path and keeps that list's URL. */
@Injectable()
export class StoryOverlay {
  private readonly router = inject(Router);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly listPreferences = inject(ListPreferences);

  readonly storyId = inject(ReaderRouteState).storyId;
  readonly open = computed(() => this.storyId() !== null);
  readonly selection = computed(
    (): SelectionOf<'trending-story'> | null => {
      const id = this.storyId();
      if (id === null) return null;
      const applied = this.listPreferences.appliedTo(trendingStorySelection(id));
      return applied.kind === 'trending-story' ? applied : null;
    },
    { equal: (left, right) => (left && right ? sameSelection(left, right) : left === right) },
  );

  close(): void {
    void this.router.navigate(['/'], ONTO_THE_LIST);
  }

  /** The story is gone from the server: the list beneath takes its place, and the sidebar drops it. */
  leaveStoryThatIsGone(): void {
    this.followedStories.load();
    void this.router.navigate(['/'], { ...ONTO_THE_LIST, replaceUrl: true });
  }
}
