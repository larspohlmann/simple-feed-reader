import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { IconComponent } from '../../../shared/icon/icon.component';
import { STORY_ICON } from '../story';
import { TrendingStoryDto } from '../trending.models';

/** A Trending story's event headline, a tinted card the story's own card tucks over (#2329). */
@Component({
  selector: 'app-story-title',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IconComponent],
  templateUrl: './story-title.component.html',
  styleUrl: './story-title.component.scss',
})
export class StoryTitleComponent {
  readonly story = input.required<TrendingStoryDto>();
  protected readonly storyIcon = STORY_ICON;
}
