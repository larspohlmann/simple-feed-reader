import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { TrendingFollowAction } from '../trending-follow-action';
import { TrendingHideAction } from '../trending-hide-action';

/** Follow and Hide for a Trending card's story (#2017, #2128), on the story's own line, apart from the post's actions. */
@Component({
  selector: 'app-story-actions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, IconComponent, IconButtonDirective, FlagToggleDirective],
  templateUrl: './story-actions.component.html',
  styleUrl: './story-actions.component.scss',
})
export class StoryActionsComponent {
  readonly entry = input.required<EntryDto>();

  // Optional: the reader shell provides all three, and a card rendered outside it offers no story actions.
  private readonly routeState = inject(ReaderRouteState, { optional: true });
  private readonly follow = inject(TrendingFollowAction, { optional: true });
  private readonly hide = inject(TrendingHideAction, { optional: true });

  protected readonly actions = computed(() => {
    const story = this.entry().story;
    if (!story || !this.follow || !this.hide) return null;
    if (this.routeState?.selection().kind !== 'trending') return null;
    return { story, follow: this.follow, hide: this.hide };
  });
}
