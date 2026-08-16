// src/app/settings/recommendation-run-history.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  effect,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoModule, TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../reader/reader-api';
import { formatDateOr, formatTime } from '../reader/format';
import { RunHistoryRow } from '../reader/models';
import { RecommendationsService } from '../reader/recommendations.service';
import { LanguageService } from '../core/language.service';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';

/** Nano-credits per credit. The API stores money as an integer -- floats do
 *  not hold money -- and this is the one place it becomes a human figure. */
const NANO_PER_CREDIT = 1_000_000_000;

/** What no reported price renders as. The provider said nothing about cost
 *  (a local model, or a run older than the column), which is a different
 *  statement from a cost of zero -- so it must not render as one. */
const NO_PRICE = '—';

/** What every for-you run has cost (#409): one row per run with the provider
 *  and model it actually called, how long it took, the tokens it consumed and
 *  the price, under the account's all-time total.
 *
 *  Not gated on the debug switch, and not bounded by the debug log's retention
 *  window: the run totals are banked by every call whether or not its
 *  transcript is being kept, which is the whole point of the issue.
 *
 *  Self-hiding, like the debug log below it: an account that has never run has
 *  nothing to show, so the settings page needs no extra lookup to hide it.
 *
 *  No poll loop. A finished run is the only thing that changes this list, and
 *  `completedStamp` already announces exactly that. */
@Component({
  selector: 'app-recommendation-run-history',
  standalone: true,
  imports: [SettingsCardComponent, TranslocoModule],
  templateUrl: './recommendation-run-history.component.html',
  styleUrl: './recommendation-run-history.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationRunHistoryComponent {
  private readonly api = inject(ReaderApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  readonly runs = signal<RunHistoryRow[]>([]);
  readonly totalCostNanoCredits = signal<number | null>(null);

  /** Fetched on creation and again whenever a run completes -- the only event
   *  that adds a row or moves the total. */
  private readonly refetchOnCompletion = effect(() => {
    this.recs.completedStamp();
    this.fetch();
  });

  /** Credits with four decimals, or an em dash when the provider reported no
   *  price at all. Four decimals is the granularity a single run is worth
   *  reading at; a run cheaper than that reads as 0.0000, which is honest.
   *
   *  Through `Intl` on the active UI language for the same reason the dates
   *  below are: `toFixed` always writes a `.`, and a German card showing
   *  `22. Juli 2026` beside `0.0412` is two locales in one line. The unit
   *  belongs to the labels, not to every value. */
  cost(nanoCredits: number | null): string {
    if (nanoCredits === null) return NO_PRICE;
    return new Intl.NumberFormat(this.language.lang(), {
      minimumFractionDigits: 4,
      maximumFractionDigits: 4,
    }).format(nanoCredits / NANO_PER_CREDIT);
  }

  /** The provider and model the run called, as one line. Falls back to the
   *  translated "unknown" for a run that was never stamped. */
  provider(run: RunHistoryRow): string {
    const host: string =
      run.providerHost ?? this.i18n.translate('settings.ai.recommendations.historyUnknown');
    return run.model === null ? host : `${host} · ${run.model}`;
  }

  /** The active UI language drives the date format (via Intl), not `LOCALE_ID`
   *  -- Transloco switches language at runtime and a static `LOCALE_ID` cannot
   *  follow it. */
  day(run: RunHistoryRow): string {
    return formatDateOr(run.createdAt, this.language.lang(), '');
  }

  time(run: RunHistoryRow): string {
    return formatTime(run.createdAt);
  }

  private fetch(): void {
    this.api
      .runHistory()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (payload) => {
          this.runs.set(payload.runs);
          this.totalCostNanoCredits.set(payload.totalCostNanoCredits);
        },
        error: () => {
          // The card is a spending record, not a control: a failed fetch
          // leaves the rows it already has standing rather than blanking them
          // or claiming an error. Handled at all because the effect re-fires
          // on every completed run, so an endpoint that stays broken would
          // otherwise throw once per run for as long as the page is open.
        },
      });
  }
}
