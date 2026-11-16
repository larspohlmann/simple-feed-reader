import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { EntryDto } from '../../models';
import { STORY_ICON } from '../story';

/** "7 sources · 3 shares ·" on a Trending row's attribution line; nothing on any other row (#2017, #2229). */
@Component({
  selector: 'app-story-source-count',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './story-source-count.component.html',
  styleUrl: './story-source-count.component.scss',
})
export class StorySourceCountComponent {
  readonly entry = input.required<EntryDto>();
  protected readonly storyIcon = STORY_ICON;
}
