import { Directive, inject, InjectionToken, input, Signal, signal } from '@angular/core';

import { TrendingStoryDto } from './trending.models';

export const STORY_CONTEXT = new InjectionToken<Signal<TrendingStoryDto | null>>('STORY_CONTEXT');

@Directive({
  selector: '[appStory]',
  providers: [{ provide: STORY_CONTEXT, useFactory: () => inject(StoryContextDirective).story }],
})
export class StoryContextDirective {
  readonly story = input<TrendingStoryDto | null>(null, { alias: 'appStory' });
}

export function injectStory(): Signal<TrendingStoryDto | null> {
  return inject(STORY_CONTEXT, { optional: true }) ?? signal(null).asReadonly();
}
