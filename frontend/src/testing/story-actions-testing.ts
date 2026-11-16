import { Provider, signal } from '@angular/core';
import { SelectionKind } from '../app/reader/query/query';
import { ReaderRouteState } from '../app/reader/shell/reader-route-state.service';
import { TrendingFollowAction } from '../app/reader/trending/trending-follow-action';
import { TrendingHideAction } from '../app/reader/trending/trending-hide-action';

/** What the reader shell gives a Trending card's story actions (#2128): the list on screen and the two actions. */
export function provideStoryActions(kind: SelectionKind = 'trending'): Provider[] {
  return [
    { provide: ReaderRouteState, useValue: { selection: signal({ kind }) } },
    { provide: TrendingFollowAction, useValue: { run: jest.fn() } },
    { provide: TrendingHideAction, useValue: { run: jest.fn() } },
  ];
}
