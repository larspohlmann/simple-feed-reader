import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { TrendingStoryDto } from '../trending.models';
import { STORY_ACTIONS } from './story-actions';

/** Follow and Hide for a Trending card's story (#2017, #2128), on the story's own line, apart from the post's actions. */
@Component({
  selector: 'app-story-actions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, IconComponent, IconButtonDirective, FlagToggleDirective],
  templateUrl: './story-actions.component.html',
  styleUrl: './story-actions.component.scss',
})
export class StoryActionsComponent {
  readonly story = input.required<TrendingStoryDto>();

  private readonly provided = inject(STORY_ACTIONS, { optional: true });

  protected readonly actions = computed(() => {
    const actions = this.provided?.();
    if (!actions) return null;
    return { followed: actions.isFollowed(this.story()), actions };
  });
}
