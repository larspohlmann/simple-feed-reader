import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { EntryDto } from '../../models';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { StorySourcesComponent } from '../../trending/story-sources/story-sources.component';
import { injectStory } from '../../trending/story-context';

@Component({
  selector: 'app-more-sources',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [EntryDuplicatesComponent, StorySourcesComponent],
  templateUrl: './more-sources.component.html',
  styleUrl: './more-sources.component.scss',
})
export class MoreSourcesComponent {
  readonly entry = input.required<EntryDto>();
  protected readonly story = injectStory();

  protected readonly hasDuplicates = computed(() => (this.entry().duplicates?.length ?? 0) > 0);
}
