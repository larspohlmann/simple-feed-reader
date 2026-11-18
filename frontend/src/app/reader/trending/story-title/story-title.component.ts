import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../../shared/icon/icon.component';
import { STORY_ICON, storyListLink } from '../story';
import { TrendingStoryDto } from '../trending.models';

/** A Trending story's event headline, a card the story's own card tucks over, linking to the story's list (#2329). */
@Component({
  selector: 'app-story-title',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IconComponent, RouterLink],
  templateUrl: './story-title.component.html',
  styleUrl: './story-title.component.scss',
})
export class StoryTitleComponent {
  readonly story = input.required<TrendingStoryDto>();
  protected readonly storyIcon = STORY_ICON;
  protected readonly storyLink = computed(() => storyListLink(this.story().id));
}
