import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { IconComponent } from '../../../shared/icon/icon.component';
import { STORY_ICON, storySynopsisId, storyTitleId } from '../story';

/** A Trending story's glyph, event headline, optional `storyMeta` line and synopsis: the Trending card's face, and the story page's lead. */
@Component({
  selector: 'app-story-headline',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IconComponent],
  templateUrl: './story-headline.component.html',
  styleUrl: './story-headline.component.scss',
})
export class StoryHeadlineComponent {
  readonly storyId = input.required<number>();
  readonly title = input.required<string>();
  readonly synopsis = input<string | null>(null);
  protected readonly storyIcon = STORY_ICON;
  protected readonly titleId = computed(() => storyTitleId(this.storyId()));
  protected readonly synopsisId = computed(() => storySynopsisId(this.storyId()));
}
