import { Provider, signal, WritableSignal } from '@angular/core';

import { STORY_CONTEXT } from '../app/reader/trending/story-context';
import { TrendingStoryDto } from '../app/reader/trending/trending.models';

export function provideStoryContext(story: TrendingStoryDto | null): {
  provider: Provider;
  story: WritableSignal<TrendingStoryDto | null>;
} {
  const current = signal(story);
  return {
    provider: { provide: STORY_CONTEXT, useValue: current.asReadonly() },
    story: current,
  };
}
