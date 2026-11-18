import { Provider, signal } from '@angular/core';
import { SelectionKind } from '../app/reader/query/query';
import { TrendingStoryDto } from '../app/reader/trending/trending.models';
import { STORY_ACTIONS } from '../app/reader/trending/story-actions/story-actions';

/** What the reader shell gives a Trending card's story actions (#2128): actions on Trending, none elsewhere. */
export function provideStoryActions(kind: SelectionKind = 'trending'): Provider[] {
  const actions =
    kind === 'trending'
      ? {
          follow: jest.fn(),
          hide: jest.fn(),
          isFollowed: jest.fn((story: Pick<TrendingStoryDto, 'id' | 'followed'>) => story.followed),
        }
      : null;
  return [{ provide: STORY_ACTIONS, useValue: signal(actions) }];
}
