import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { relativeTime } from '../../format';
import { LanguageService } from '../../../core/i18n/language.service';
import { ListDividerComponent } from '../list-divider/list-divider.component';

/** The run-boundary divider inside the for-you list, reading "Generated {relative}". One per older run
 *  (#348); the newest run's block shows none, because the list header's "Last refreshed" already does. */
@Component({
  selector: 'app-run-header',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, ListDividerComponent],
  templateUrl: './run-header.component.html',
})
export class RunHeaderComponent {
  readonly generatedAt = input.required<string>();

  private readonly language = inject(LanguageService);
  readonly label = computed(() => relativeTime(this.generatedAt(), this.language.lang()));
}
