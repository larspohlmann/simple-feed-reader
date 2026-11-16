import { InjectionToken, Provider, Signal, computed, inject } from '@angular/core';
import { EntryDto } from '../../models';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { TrendingFollowAction } from '../trending-follow-action';
import { TrendingHideAction } from '../trending-hide-action';

export interface StoryActions {
  follow(entry: EntryDto): void;
  hide(entry: EntryDto): void;
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
        return computed<StoryActions | null>(() =>
          routeState.selection().kind === 'trending'
            ? { follow: (entry) => follow.run(entry), hide: (entry) => hide.run(entry) }
            : null,
        );
      },
    },
  ];
}
