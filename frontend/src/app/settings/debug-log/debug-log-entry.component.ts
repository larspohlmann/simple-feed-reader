import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { bytesToKb } from '../../reader/format';
import { DebugLogDetail, DebugLogEntry } from '../settings.models';
import { debugLogTime } from './debug-log-time';

/** One logged provider call: the row, its notes and, once expanded, the request
 *  and response bodies the parent fetched. Shared by the profile and the
 *  recommendation debug logs. */
@Component({
  selector: 'app-debug-log-entry',
  imports: [TranslocoPipe],
  templateUrl: './debug-log-entry.component.html',
  styleUrl: './debug-log-entry.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DebugLogEntryComponent {
  private readonly language = inject(LanguageService);

  readonly entry = input.required<DebugLogEntry>();
  readonly expanded = input(false);
  readonly detail = input<DebugLogDetail | null>(null);
  readonly toggled = output();

  readonly time = computed(() => debugLogTime(this.entry().createdAt, this.language.lang()));
  readonly requestKb = computed(() => bytesToKb(this.entry().requestBytes));
  readonly responseKb = computed(() => bytesToKb(this.entry().responseBytes));
  readonly wireKb = computed(() => bytesToKb(this.entry().wireBytes));

  /** Seconds a settled call took, or null while it is still streaming --
   *  `finishedAt` is null then, and rendering a duration from a moving
   *  target would show a nonsensical or negative figure. Clamped at 0 for
   *  the same reason: a clock skew must never surface as a negative time. */
  readonly durationSeconds = computed(() => {
    const entry = this.entry();
    if (entry.finishedAt === null) return null;
    const elapsedMs = new Date(entry.finishedAt).getTime() - new Date(entry.createdAt).getTime();
    return Math.max(0, Math.round(elapsedMs / 1000));
  });

  copy(text: string): void {
    void navigator.clipboard.writeText(text);
  }
}
