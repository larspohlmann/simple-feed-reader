import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { RouterLink } from '@angular/router';
import { StoryHeadlineComponent } from '../story-headline/story-headline.component';
import { StoryActionsComponent } from '../story-actions/story-actions.component';
import { StoryDatesComponent } from '../story-dates/story-dates.component';
import { StorySourceCountComponent } from '../story-source-count/story-source-count.component';
import { STORY_LIST_QUERY_PARAMS, storyListLink, storySynopsisId, storyTitleId } from '../story';
import { StoryCard } from '../trending.models';

/** A story's event headline, counts, dates and actions: one card on the Trending list, the magazine and the story page, linking to the story's list unless it is on that page (#2329, #2447). */
@Component({
  selector: 'app-story-title',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgTemplateOutlet,
    RouterLink,
    StoryHeadlineComponent,
    StorySourceCountComponent,
    StoryDatesComponent,
    StoryActionsComponent,
  ],
  templateUrl: './story-title.component.html',
  styleUrl: './story-title.component.scss',
})
export class StoryTitleComponent {
  readonly story = input.required<StoryCard>();
  readonly linked = input(true);
  protected readonly storyLink = computed(() => storyListLink(this.story().id));
  protected readonly storyListQueryParams = STORY_LIST_QUERY_PARAMS;
  protected readonly titleId = computed(() => storyTitleId(this.story().id));
  protected readonly synopsisId = computed(() =>
    this.story().synopsis ? storySynopsisId(this.story().id) : null,
  );
}
