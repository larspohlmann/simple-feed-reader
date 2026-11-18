import { InjectionToken, Provider, Signal, computed, inject } from '@angular/core';
import { EntryDto } from '../../models';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { TrendingFollowAction } from '../trending-follow-action';
import { TrendingHideAction } from '../trending-hide-action';
import { TrendingStoryDto } from '../trending.models';

export interface StoryActions {
  follow(entry: EntryDto): void;
  hide(entry: EntryDto): void;
  isFollowed(story: Pick<TrendingStoryDto, 'id' | 'followed'>): boolean;
}

export const STORY_ACTIONS = new InjectionToken<Signal<StoryActions | null>>('STORY_ACTIONS');

export function provideTrendingStoryActions(): Provider[] {
  return [
    TrendingFollowAction,
    TrendingHideAction,
    {
      provide: STORY_ACTIONS,
      useFactory: () => {
        const routeState = inject(ReaderRouteState);
        const follow = inject(TrendingFollowAction);
        const hide = inject(TrendingHideAction);
        const followedStories = inject(FollowedStoriesStore);
        return computed<StoryActions | null>(() =>
          routeState.selection().kind === 'trending'
            ? {
                follow: (entry) => follow.run(entry),
                hide: (entry) => hide.run(entry),
                isFollowed: (story) => followedStories.isFollowed(story),
              }
            : null,
        );
      },
    },
  ];
}
