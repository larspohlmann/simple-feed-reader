import { Injectable, computed, effect, inject, untracked } from '@angular/core';
import { NavigationExtras, Router } from '@angular/router';
import { ListPreferences } from '../list/list-preferences.service';
import { Selection, SelectionOf, sameSelection } from '../query/query';
import { queryFromSelection } from '../query/entry-query';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { STORY_ENTRIES } from '../state/story-entries';
import { ReaderRouteState } from './reader-route-state.service';

const NOT_FOUND = 'trending_story_not_found';
const ONTO_THE_LIST: NavigationExtras = { queryParamsHandling: 'preserve' };

/** A Trending story's list open over the list the reader was on, as an article is (#2329). The list underneath
 *  keeps its rows and its place; closing drops the story's path and keeps that list's URL. */
@Injectable()
export class StoryOverlay {
  private readonly router = inject(Router);
  private readonly storyList = inject(STORY_ENTRIES);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly listPreferences = inject(ListPreferences);

  readonly storyId = inject(ReaderRouteState).storyId;
  readonly open = computed(() => this.storyId() !== null);
  readonly selection = computed(
    (): SelectionOf<'trending-story'> | null => {
      const id = this.storyId();
      if (id === null) return null;
      const applied = this.listPreferences.appliedTo({ kind: 'trending-story', id, unread: false });
      return applied.kind === 'trending-story' ? applied : null;
    },
    { equal: (left, right) => (left && right ? sameSelection(left, right) : left === right) },
  );

  constructor() {
    effect(() => {
      const selection = this.selection();
      untracked(() => this.show(selection));
    });
    effect(() => {
      if (!this.open() || this.storyList.error()?.type !== NOT_FOUND) return;
      untracked(() => this.leaveStoryThatIsGone());
    });
  }

  close(): void {
    void this.router.navigate(['/'], ONTO_THE_LIST);
  }

  private show(selection: Selection | null): void {
    this.storyList.load(selection === null ? null : queryFromSelection(selection));
  }

  private leaveStoryThatIsGone(): void {
    this.storyList.dismissError();
    this.followedStories.load();
    void this.router.navigate(['/'], { ...ONTO_THE_LIST, replaceUrl: true });
  }
}
