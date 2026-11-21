import { Provider, inject, signal } from '@angular/core';
import { STORY_ACTIONS, StoryActions } from '../../trending/story-actions/story-actions';
import { TrendingHideAction } from '../../trending/trending-hide-action';
import { StoryFollowing } from '../story-following.service';
import { StoryOverlay } from '../story-overlay.service';

/** Follow and Hide on the open story's title card (#2442). Hide closes the layer and runs on the list beneath, so
 *  a Trending card of the story leaves there. */
export function provideStoryLayerActions(): Provider {
  return {
    provide: STORY_ACTIONS,
    useFactory: () => {
      const following = inject(StoryFollowing);
      const overlay = inject(StoryOverlay);
      const hide = inject(TrendingHideAction);
      return signal<StoryActions>({
        follow: () => following.toggleOpen(),
        hide: (story) => {
          overlay.close();
          hide.run(story);
        },
        isFollowed: () => following.following(),
      }).asReadonly();
    },
  };
}
