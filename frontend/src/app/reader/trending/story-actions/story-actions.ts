import { InjectionToken, Provider, Signal, computed, inject } from '@angular/core';
import { FollowedStoriesStore } from '../../state/followed-stories.store';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { TrendingFollowAction } from '../trending-follow-action';
import { TrendingHideAction } from '../trending-hide-action';
import { StoryRef } from '../trending.models';

export interface StoryActions {
  follow(story: StoryRef): void;
  hide(story: StoryRef): void;
  isFollowed(story: StoryRef): boolean;
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
                follow: (story) => follow.toggle(story),
                hide: (story) => hide.run(story),
                isFollowed: (story) => followedStories.isFollowed(story),
              }
            : null,
        );
      },
    },
  ];
}
