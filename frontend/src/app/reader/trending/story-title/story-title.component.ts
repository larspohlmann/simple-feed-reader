import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { StoryHeadlineComponent } from '../story-headline/story-headline.component';
import { storyListLink, storySynopsisId, storyTitleId } from '../story';
import { TrendingStoryDto } from '../trending.models';

/** A Trending story's event headline, a card the story's own card tucks over, linking to the story's list (#2329). */
@Component({
  selector: 'app-story-title',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, StoryHeadlineComponent],
  templateUrl: './story-title.component.html',
  styleUrl: './story-title.component.scss',
})
export class StoryTitleComponent {
  readonly story = input.required<TrendingStoryDto>();
  protected readonly storyLink = computed(() => storyListLink(this.story().id));
  protected readonly titleId = computed(() => storyTitleId(this.story().id));
  protected readonly synopsisId = computed(() =>
    this.story().synopsis ? storySynopsisId(this.story().id) : null,
  );
}
