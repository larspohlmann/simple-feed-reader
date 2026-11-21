import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subscription } from 'rxjs';
import { TranslocoModule } from '@jsverse/transloco';
import { SettingsApi } from '../settings-api';
import { formatCost } from '../../reader/format';
import {
  AiRunKind,
  RunHistoryMonth,
  RunHistoryMonthPage,
  RunHistoryOverview,
  RunHistoryRow,
} from '../settings.models';
import { RecommendationsService } from '../../reader/state/recommendations.service';
import { LanguageService } from '../../core/i18n/language.service';
import { IconComponent } from '../../shared/icon/icon.component';
import { SegmentedChoiceComponent } from '../../shared/segmented-choice/segmented-choice.component';
import { RecommendationRunHistoryMonthComponent } from './recommendation-run-history-month.component';
import { runKey } from './run-key';
import {
  RUN_HISTORY_STATUSES,
  runHistoryStatusIcon,
  RunHistoryStatus,
} from './run-history-status-icon';

/** One month section as the card renders it. `runs` stays null until the
 *  month is opened and its first page arrives -- that null (not a separate
 *  flag) is what `RecommendationRunHistoryMonthComponent` binds `startOpen`
 *  to.
 *
 *  `failed` needs its own flag: an unopened month and a failed-fetch month
 *  are both `runs === null` with nothing loading, but only the second has
 *  something to tell the reader. */
interface MonthSection {
  month: string;
  runCount: number;
  costNanoCredits: number | null;
  runs: RunHistoryRow[] | null;
  nextCursor: string | null;
  loading: boolean;
  failed: boolean;
}

/** The filter's options as `app-segmented-choice` labels them: each is the
 *  translation key under `settings.ai.` that names it, so the kind labels the
 *  rows already use serve the filter too. */
const KIND_FILTERS: readonly { option: string; kind: AiRunKind | null }[] = [
  { option: 'recommendations.historyKindAll', kind: null },
  { option: 'runKind.for_you', kind: 'for_you' },
  { option: 'runKind.profile', kind: 'profile' },
  { option: 'runKind.story', kind: 'story' },
];

/** What every AI run has cost (#409, #2355): one collapsible section per month
 *  with its own run count and spend, under the account's all-time total. The
 *  newest month arrives expanded (rows come free with the overview); older
 *  months fetch their first page once opened, and "show more" pages the rest.
 *
 *  Not gated on the debug switch or bounded by the debug log's retention:
 *  totals are banked by every call whether or not its transcript is kept --
 *  the whole point of the issue. Self-hiding, like the debug log: an account
 *  that never ran has nothing to show. No poll loop: a finished run is the
 *  only thing that changes this card, and `completedStamp` announces it. */
@Component({
  selector: 'app-recommendation-run-history',
  standalone: true,
  imports: [
    RecommendationRunHistoryMonthComponent,
    SegmentedChoiceComponent,
    IconComponent,
    TranslocoModule,
  ],
  templateUrl: './recommendation-run-history.component.html',
  styleUrl: './recommendation-run-history.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationRunHistoryComponent {
  private readonly api = inject(SettingsApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly language = inject(LanguageService);

  /** Read once and sent on every call -- the server buckets months in it. */
  private readonly timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

  readonly sections = signal<MonthSection[]>([]);
  readonly totalCostNanoCredits = signal<number | null>(null);

  /** Null means every kind. */
  readonly kind = signal<AiRunKind | null>(null);
  /** Keeps the card, and the filter in it, on screen once the reader has used
   *  the filter -- a kind with no runs, or the moment between two kinds, would
   *  otherwise empty `sections` and take the control away. */
  readonly filterUsed = signal(false);
  readonly kindOptions: readonly string[] = KIND_FILTERS.map((filter) => filter.option);
  readonly selectedKindOption = computed(
    () => KIND_FILTERS.find((filter) => filter.kind === this.kind())!.option,
  );

  private overviewRequest: Subscription | null = null;
  /** Dropped on a kind switch: a late page for the previous kind would
   *  otherwise land in the list the reader just switched to. */
  private monthRequests = new Subscription();

  /** The five statuses the mobile legend spells out, in a fixed order --
   *  read once here rather than re-derived per render. */
  readonly legendStatuses: readonly RunHistoryStatus[] = RUN_HISTORY_STATUSES;

  /** Fetched on creation and again whenever a run completes -- the only event
   *  that adds a row or moves the total. */
  private readonly refetchOnCompletion = effect(() => {
    this.recs.completedStamp();
    this.fetchOverview(this.kind());
  });

  /** A different kind is a different list: every loaded month goes, and the
   *  effect above refetches the overview for it. */
  pickKind(option: string): void {
    const picked = KIND_FILTERS.find((filter) => filter.option === option)!.kind;
    if (picked === this.kind()) return;

    this.monthRequests.unsubscribe();
    this.monthRequests = new Subscription();
    this.filterUsed.set(true);
    this.sections.set([]);
    this.kind.set(picked);
  }

  /** The account's spend, and each month's, as the provider writes it. The
   *  formatting itself lives in `format.ts` -- each month section renders the
   *  same figure and a second copy of the rounding would drift. */
  cost(nanoCredits: number | null): string {
    return formatCost(nanoCredits, this.language.lang());
  }

  readonly statusIcon = runHistoryStatusIcon;

  /** A month section was opened. Rows already loaded (newest month from the
   *  overview, or an older month opened earlier) means nothing to fetch:
   *  `DisclosureComponent.opened` fires only closed-to-open, but a
   *  close/re-open or a fast double-open before the response lands still
   *  needs the `loading` half of the guard. */
  onOpened(month: string): void {
    const section = this.findSection(month);
    if (!section || section.runs !== null || section.loading) return;

    this.startFetch(month);
    this.monthRequests.add(
      this.api
        .runHistoryMonth(month, this.timeZone, { kind: this.kind() ?? undefined })
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (page) => this.replaceRows(month, page.runs, page.nextCursor),
          // Without a message this reads as a month with no runs, not a failed
          // fetch -- and closing and re-opening retries, but only if the
          // reader knows there's something to retry.
          error: () => this.markFailed(month),
        }),
    );
  }

  /** "Show more" for a month that already has rows: appends the next page and
   *  replaces the cursor, never replacing the rows already on screen. */
  onShowMore(month: string): void {
    const section = this.findSection(month);
    if (!section || section.nextCursor === null || section.loading) return;

    this.startFetch(month);
    this.monthRequests.add(
      this.api
        .runHistoryMonth(month, this.timeZone, {
          kind: this.kind() ?? undefined,
          before: section.nextCursor,
        })
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (page) => this.appendRows(month, page.runs, page.nextCursor),
          // Unlike a first page, this one has rows on screen already: they stay,
          // and the button comes back enabled so the reader can try again.
          error: () => this.stopFetch(month),
        }),
    );
  }

  /** A *tracked* read of `sections()` -- safe only because `onOpened` and
   *  `onShowMore` come from template event bindings, never from inside
   *  `refetchOnCompletion`. Calling it from `applyOverview` would reintroduce
   *  the self-triggering loop `untracked()` exists to prevent there. */
  private findSection(month: string): MonthSection | undefined {
    return this.sections().find((section) => section.month === month);
  }

  /** A retry clears the last failure as it starts, so a second attempt is not
   *  read against the first one's message. */
  private startFetch(month: string): void {
    this.updateSection(month, (section) => ({ ...section, loading: true, failed: false }));
  }

  private stopFetch(month: string): void {
    this.updateSection(month, (section) => ({ ...section, loading: false }));
  }

  private markFailed(month: string): void {
    this.updateSection(month, (section) => ({ ...section, loading: false, failed: true }));
  }

  private replaceRows(month: string, runs: RunHistoryRow[], nextCursor: string | null): void {
    this.updateSection(month, (section) => ({ ...section, runs, nextCursor, loading: false }));
  }

  private appendRows(month: string, runs: RunHistoryRow[], nextCursor: string | null): void {
    this.updateSection(month, (section) => ({
      ...section,
      runs: [...(section.runs ?? []), ...runs],
      nextCursor,
      loading: false,
    }));
  }

  private updateSection(month: string, update: (section: MonthSection) => MonthSection): void {
    this.sections.update((sections) =>
      sections.map((section) => (section.month === month ? update(section) : section)),
    );
  }

  /** Replaces any overview still in flight: a late answer for the previous
   *  kind would otherwise overwrite the list the reader just switched to. */
  private fetchOverview(kind: AiRunKind | null): void {
    this.overviewRequest?.unsubscribe();
    this.overviewRequest = this.api
      .runHistory(this.timeZone, kind ?? undefined)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (overview) => this.applyOverview(overview),
        error: () => {
          // A spending record, not a control: a failed fetch leaves existing
          // sections standing. Caught here only so a broken endpoint doesn't
          // throw once per run for as long as the page stays open.
        },
      });
  }

  /** Month summaries and the total are replaced wholesale; the newest month's
   *  rows come from `latest`. Every other month keeps what it already loaded --
   *  a completed run can only land in the current month.
   *
   *  `untracked()` wraps the prior-state read because this runs inside the
   *  completion `effect`; a tracked read of `sections()` would re-trigger it
   *  the instant this calls `sections.set()` (same pattern as
   *  recommendation-debug-log.component.ts's `applyEntries`). */
  private applyOverview(overview: RunHistoryOverview): void {
    const priorByMonth = new Map(
      untracked(this.sections).map((section) => [section.month, section]),
    );
    const latestMonth = overview.latest?.month ?? null;

    this.sections.set(
      overview.months.map((month) =>
        month.month === latestMonth
          ? this.latestSection(month, overview.latest!, priorByMonth.get(month.month))
          : this.staleSection(month, priorByMonth.get(month.month)),
      ),
    );
    this.totalCostNanoCredits.set(overview.totalCostNanoCredits);
  }

  /** The newest month refreshes from `latest`, but a reader who pressed "show
   *  more" keeps what they paged into: the overview always answers with the
   *  first page, so rows below it would otherwise vanish at the exact moment
   *  the reader is watching -- a run finishing. */
  private latestSection(
    month: RunHistoryMonth,
    latest: RunHistoryMonthPage,
    prior: MonthSection | undefined,
  ): MonthSection {
    const carried = this.carriedTail(latest.runs, prior?.runs ?? null);

    return {
      month: month.month,
      runCount: month.runCount,
      costNanoCredits: month.costNanoCredits,
      runs: [...latest.runs, ...carried],
      // With rows carried over, the list still ends where the prior one did,
      // so the prior cursor is still the right `before` -- including when it
      // is null, which means the reader had already paged to the month's end.
      nextCursor: carried.length === 0 ? latest.nextCursor : (prior?.nextCursor ?? null),
      loading: false,
      failed: false,
    };
  }

  /** The prior rows the fresh first page does not already carry. The page is
   *  a prefix of the month's order, so what it lacks is exactly the tail --
   *  which makes the concatenation above ordered and duplicate-free. */
  private carriedTail(fresh: RunHistoryRow[], prior: RunHistoryRow[] | null): RunHistoryRow[] {
    if (prior === null) return [];

    const freshKeys = new Set(fresh.map(runKey));
    return prior.filter((run) => !freshKeys.has(runKey(run)));
  }

  private staleSection(month: RunHistoryMonth, prior: MonthSection | undefined): MonthSection {
    return {
      month: month.month,
      runCount: month.runCount,
      costNanoCredits: month.costNanoCredits,
      runs: prior?.runs ?? null,
      nextCursor: prior?.nextCursor ?? null,
      // An overview refetch can land while this month's own first page is
      // still in flight. Clearing the flag there would drop the "Loading…"
      // line and disarm the `loading` half of `onOpened`'s re-entrancy guard,
      // letting a close/re-open fire a second identical GET.
      loading: prior?.loading ?? false,
      failed: prior?.failed ?? false,
    };
  }
}
