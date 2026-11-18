import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { STORY_ICON } from '../story';
import { TrendingStoryDto } from '../trending.models';

/** "7 sources · 3 shares" on a Trending story (#2017, #2229). */
@Component({
  selector: 'app-story-source-count',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './story-source-count.component.html',
  styleUrl: './story-source-count.component.scss',
})
export class StorySourceCountComponent {
  readonly story = input.required<TrendingStoryDto>();
  protected readonly storyIcon = STORY_ICON;
}
