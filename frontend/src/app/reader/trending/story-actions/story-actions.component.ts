import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { StoryRef } from '../trending.models';
import { STORY_ACTIONS } from './story-actions';

/** Follow and Hide for a Trending story (#2017, #2128) on its title card, a Trending card's or its page's (#2442). */
@Component({
  selector: 'app-story-actions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, IconComponent, IconButtonDirective, FlagToggleDirective],
  templateUrl: './story-actions.component.html',
  styleUrl: './story-actions.component.scss',
})
export class StoryActionsComponent {
  readonly story = input.required<StoryRef>();

  private readonly provided = inject(STORY_ACTIONS, { optional: true });

  protected readonly actions = computed(() => {
    const actions = this.provided?.();
    if (!actions) return null;
    return { followed: actions.isFollowed(this.story()), actions };
  });
}
