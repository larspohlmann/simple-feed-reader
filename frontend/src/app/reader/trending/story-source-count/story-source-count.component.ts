import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';

/** "7 sources ·" on a Trending row's attribution line; nothing on any other row (#2017). */
@Component({
  selector: 'app-story-source-count',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './story-source-count.component.html',
  styleUrl: './story-source-count.component.scss',
})
export class StorySourceCountComponent {
  readonly entry = input.required<EntryDto>();
}
