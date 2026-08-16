// src/app/settings/recommendation-run-history-month.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoModule, TranslocoService } from '@jsverse/transloco';
import { formatCost, formatDateOr, formatDuration, formatTime } from '../reader/format';
import { RunHistoryRow } from '../reader/models';
import { LanguageService } from '../core/language.service';
import { DisclosureComponent } from '../shared/disclosure/disclosure.component';

/** One month of the run-history card (#409): a header carrying that month's
 *  own run count and spend, and -- once the parent has fetched them -- its
 *  rows. Purely presentational: it renders whatever it is given and asks the
 *  parent for more through its two outputs, never fetching anything itself.
 *
 *  `runs === null` (not yet opened) renders as a closed `app-disclosure`,
 *  since `DisclosureComponent` has no input to start `<details>` open. Once
 *  `runs` arrives the header becomes a plain, always-expanded heading instead
 *  -- there is nothing left to toggle, and nothing to close back down: this
 *  is a spending record, not a control, the same reasoning the parent's own
 *  error handling rests on. */
@Component({
  selector: 'app-recommendation-run-history-month',
  standalone: true,
  imports: [DisclosureComponent, TranslocoModule],
  templateUrl: './recommendation-run-history-month.component.html',
  styleUrl: './recommendation-run-history-month.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationRunHistoryMonthComponent {
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  readonly month = input.required<string>();
  readonly runCount = input.required<number>();
  readonly costNanoCredits = input.required<number | null>();
  /** Null until this month has been opened and its first page has arrived. */
  readonly runs = input<RunHistoryRow[] | null>(null);
  readonly nextCursor = input<number | null>(null);
  readonly loading = input(false);

  /** Fired when a closed month is opened -- the parent's cue to fetch its
   *  first page. */
  readonly opened = output<void>();
  /** Fired by "show more"; the parent fetches the next page and appends it. */
  readonly showMore = output<void>();

  /** "August 2026" -- localised via `Intl` on the active UI language, the
   *  same source `formatCost` reads rather than `LOCALE_ID` (Transloco
   *  switches language at runtime and a static `LOCALE_ID` cannot follow it). */
  readonly monthLabel = computed(() => this.formatMonthLabel(this.month(), this.language.lang()));

  /** The month's own run count and spend, as the header's one line. */
  readonly summary = computed(() =>
    this.i18n.translate('settings.ai.recommendations.historyMonthSummary', {
      runs: this.runCount(),
      cost: formatCost(this.costNanoCredits(), this.language.lang()),
    }),
  );

  /** What each row's price renders as. Shared with the total and the month
   *  header rather than re-rounded per row -- a second copy would drift. */
  cost(nanoCredits: number | null): string {
    return formatCost(nanoCredits, this.language.lang());
  }

  /** The provider and model the run called, as one line. Falls back to the
   *  translated "unknown" for a run that was never stamped. */
  provider(run: RunHistoryRow): string {
    const host: string =
      run.providerHost ?? this.i18n.translate('settings.ai.recommendations.historyUnknown');
    return run.model === null ? host : `${host} · ${run.model}`;
  }

  day(run: RunHistoryRow): string {
    return formatDateOr(run.createdAt, this.language.lang(), '');
  }

  time(run: RunHistoryRow): string {
    return formatTime(run.createdAt);
  }

  /** How long the run took, as `m:ss`. Null while it has not finished -- the
   *  template renders nothing rather than a zero that would read as instant. */
  duration(run: RunHistoryRow): string | null {
    return run.durationSeconds === null ? null : formatDuration(run.durationSeconds);
  }

  showMoreLabel(): string {
    return this.i18n.translate('settings.ai.recommendations.historyShowMore', {
      month: this.monthLabel(),
    });
  }

  /** `month` is a `YYYY-MM` wire value. Noon UTC keeps the `Intl` call clear
   *  of any DST edge at midnight -- the day-of-month is discarded below
   *  anyway, only the year and the month matter. */
  private formatMonthLabel(month: string, locale: string): string {
    const [year, monthNumber] = month.split('-').map(Number);
    const firstOfMonth = new Date(Date.UTC(year, monthNumber - 1, 1, 12));
    return new Intl.DateTimeFormat(locale, {
      month: 'long',
      year: 'numeric',
      timeZone: 'UTC',
    }).format(firstOfMonth);
  }
}
