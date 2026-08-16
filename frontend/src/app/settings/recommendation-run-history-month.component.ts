// src/app/settings/recommendation-run-history-month.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoModule, TranslocoService } from '@jsverse/transloco';
import { formatCost, formatDayInMonth, formatDuration, formatTime } from '../reader/format';
import { RunHistoryRow } from '../reader/models';
import { LanguageService } from '../core/language.service';
import { DisclosureComponent } from '../shared/disclosure/disclosure.component';
import { IconComponent } from '../shared/icon/icon.component';
import { runHistoryStatusIcon } from './run-history-status-icon';

/** One month of the run-history card (#409): a header carrying that month's
 *  own run count and spend, and -- once the parent has fetched them -- its
 *  rows, behind a collapsible `app-disclosure appearance="row"`. Purely
 *  presentational: it renders whatever it is given and asks the parent for
 *  more through its two outputs, never fetching anything itself.
 *
 *  `startOpen` is bound to `runs() !== null`: the newest month arrives with
 *  its rows already loaded and starts open; an older month starts closed and
 *  opening it is the parent's cue to fetch. Angular only writes `[open]`
 *  when that expression's value changes, so a reader who closes a loaded
 *  month is not forced back open by an unrelated re-render (a refetch on
 *  `completedStamp`, say) -- `runs()` stays non-null throughout. */
@Component({
  selector: 'app-recommendation-run-history-month',
  standalone: true,
  imports: [DisclosureComponent, IconComponent, TranslocoModule],
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
  /** Set when this month's first page could not be fetched. Needed on top of
   *  `runs === null`, which a month nobody has opened yet also has: without
   *  it an open, empty section is all the reader gets. */
  readonly failed = input(false);

  /** Fired when a closed month is opened -- the parent's cue to fetch its
   *  first page. */
  readonly opened = output<void>();
  /** Fired by "show more"; the parent fetches the next page and appends it. */
  readonly showMore = output<void>();

  /** "August 2026" -- localised via `Intl` on the active UI language, the
   *  same source `formatCost` reads rather than `LOCALE_ID` (Transloco
   *  switches language at runtime and a static `LOCALE_ID` cannot follow it). */
  readonly monthLabel = computed(() => this.formatMonthLabel(this.month(), this.language.lang()));

  /** The month's own run count and spend, as the header's one line. No
   *  Transloco pluralization plugin is installed in this app, so this follows
   *  the existing `xxxOne`/`xxxOther` key-pair convention (see
   *  `tags-section.component.html`'s `feedCountOne`/`feedCountOther`) rather
   *  than inventing a second mechanism. */
  readonly summary = computed(() => {
    const key =
      this.runCount() === 1
        ? 'settings.ai.recommendations.historyMonthSummaryOne'
        : 'settings.ai.recommendations.historyMonthSummaryOther';
    return this.i18n.translate(key, {
      runs: this.runCount(),
      cost: formatCost(this.costNanoCredits(), this.language.lang()),
    });
  });

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

  /** "Aug 16" -- the section this row lives in is already headed with the
   *  month and year, so repeating either on every row would be noise. */
  day(run: RunHistoryRow): string {
    return formatDayInMonth(run.createdAt, this.language.lang());
  }

  time(run: RunHistoryRow): string {
    return formatTime(run.createdAt);
  }

  /** How long the run took, as `m:ss`. Null while it has not finished -- the
   *  template renders nothing rather than a zero that would read as instant. */
  duration(run: RunHistoryRow): string | null {
    return run.durationSeconds === null ? null : formatDuration(run.durationSeconds);
  }

  /** The Material Symbol standing in for the raw status word on mobile --
   *  shared with the legend's own icon so the two cannot disagree (#409). */
  statusIcon(run: RunHistoryRow): string {
    return runHistoryStatusIcon(run.status);
  }

  showMoreLabel(): string {
    return this.i18n.translate('settings.ai.recommendations.historyShowMore', {
      month: this.monthLabel(),
    });
  }

  /** `month` is a `YYYY-MM` wire value, formatted as the first of the month
   *  at noon UTC -- an arbitrary day-of-month, since only the year and month
   *  are ever rendered. */
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
