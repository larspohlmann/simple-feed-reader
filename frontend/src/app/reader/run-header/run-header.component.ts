import { Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { relativeTime } from '../format';
import { LanguageService } from '../../core/language.service';

/** The run-boundary divider inside the for-you list: a quiet, non-sticky rule
 *  reading "Generated {relative}". One per older run (#348); the newest run's
 *  block shows none, because the list header's "Last refreshed" already does. */
@Component({
  selector: 'app-run-header',
  imports: [TranslocoPipe],
  templateUrl: './run-header.component.html',
  styleUrl: './run-header.component.scss',
})
export class RunHeaderComponent {
  readonly generatedAt = input.required<string>();

  private readonly language = inject(LanguageService);
  readonly label = computed(() => relativeTime(this.generatedAt(), this.language.lang()));
}
