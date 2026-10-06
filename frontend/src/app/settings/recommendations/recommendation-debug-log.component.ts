import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoModule } from '@jsverse/transloco';
import { DebugLogDetails } from '../debug-log/debug-log-details.service';
import { DebugLogEntryComponent } from '../debug-log/debug-log-entry.component';
import { debugLogTime } from '../debug-log/debug-log-time';
import { SettingsApi } from '../settings-api';
import { LanguageService } from '../../core/i18n/language.service';
import { DebugLogEntry, DebugLogRunChoice, DebugLogRunSummary } from '../settings.models';
import { RecommendationsService } from '../../reader/state/recommendations.service';

const POLL_MS = 2000;

/** The calls of one run, as the panel groups them: a header line plus the
 *  rows, newest run first. */
export interface DebugLogRunGroup {
  runId: number;
  entries: DebugLogEntry[];
}

/** The #309 debug log: what each provider call sent and what streamed
 *  back, ~2 s fresh while a run is in flight. Server-side truth only -- the
 *  panel re-reads the run log rather than talking to the provider.
 *  Self-hiding: no log rows (debug off, or no run yet) means no panel.
 *  Sits under AI settings, below the switch that produces it -- so the
 *  common case is no run in flight, and the initial fetch on creation
 *  (not gated on `running()`) is what renders the previous run's log. */
@Component({
  selector: 'app-recommendation-debug-log',
  standalone: true,
  imports: [TranslocoModule, DebugLogEntryComponent],
  providers: [DebugLogDetails],
  templateUrl: './recommendation-debug-log.component.html',
  styleUrl: './recommendation-debug-log.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationDebugLogComponent implements OnInit {
  private readonly api = inject(SettingsApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly language = inject(LanguageService);
  readonly details = inject(DebugLogDetails);

  readonly entries = signal<DebugLogEntry[]>([]);
  /** The entries clustered by run, newest first. A resumed run keeps
   *  appending to the log, so a flat list would mix runs; grouping keeps each
   *  run's calls under one header. Rows arrive ordered by id and are already
   *  contiguous -- this only marks boundaries and flips the run order. */
  readonly groups = computed<DebugLogRunGroup[]>(() => {
    const groups: DebugLogRunGroup[] = [];
    for (const entry of this.entries()) {
      const current = groups.at(-1);
      if (current && current.runId === entry.runId) {
        current.entries.push(entry);
      } else {
        groups.push({ runId: entry.runId, entries: [entry] });
      }
    }
    return groups.reverse();
  });
  /** The latest run's own summary; null when the user has never run. Drives
   *  the panel's summary strip, distinct from any one row's `errorDetail`. */
  readonly run = signal<DebugLogRunSummary | null>(null);
  /** The retained runs, newest first — what the picker offers (#401). */
  readonly runs = signal<DebugLogRunChoice[]>([]);
  /** The run the panel is reading, or null for "whatever is newest". A null
   *  selection keeps following the newest run as new ones start; an explicit
   *  one stays where the user put it. */
  readonly selectedRunId = signal<number | null>(null);
  /** The picker is worth showing only once there is somewhere else to go. */
  readonly hasOlderRuns = computed(() => this.runs().length > 1);

  private timer: ReturnType<typeof setInterval> | null = null;

  /** Fetches on creation and again whenever a run completes, so the last
   *  call's verdict and final text replace the mid-stream snapshot the last
   *  interval poll saw. */
  private readonly refetchOnCompletion = effect(() => {
    this.recs.completedStamp();
    this.fetch();
  });

  ngOnInit(): void {
    this.timer = setInterval(() => {
      // An older run is finished by definition, so polling it would re-fetch
      // an unchanging payload every two seconds.
      if (this.recs.running() && this.isViewingNewestRun()) this.fetch();
    }, POLL_MS);
    this.destroyRef.onDestroy(() => this.stopPolling());
  }

  time(iso: string): string {
    return debugLogTime(iso, this.language.lang());
  }

  /** When a run group's first call went out. */
  groupStart(group: DebugLogRunGroup): string {
    return this.time(group.entries[0].createdAt);
  }

  /** When a run group's last call settled, or null while one is still
   *  streaming -- the header then shows an open-ended range. */
  groupEnd(group: DebugLogRunGroup): string | null {
    const finishedAt = group.entries.at(-1)?.finishedAt ?? null;
    return finishedAt === null ? null : this.time(finishedAt);
  }

  /** Switches the panel to another retained run. Selecting the newest is the
   *  same as following it, so it clears the selection rather than pinning it —
   *  otherwise the panel would stop tracking the next run that starts. */
  selectRun(runId: number): void {
    const newest = this.runs()[0]?.id ?? null;
    this.selectedRunId.set(runId === newest ? null : runId);
    this.details.clear();
    this.fetch();
  }

  protected onRunPicked(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    this.selectRun(Number(value));
  }

  private isViewingNewestRun(): boolean {
    return this.selectedRunId() === null;
  }

  private fetch(): void {
    this.api
      .debugLog(this.selectedRunId() ?? undefined)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.run.set(response.run);
          this.runs.set(response.runs);
          this.details.observe(response.entries);
          this.entries.set(response.entries);
        },
        error: () => {
          // The panel is best-effort diagnostics; a failed poll shows stale
          // rows rather than an error state of its own.
        },
      });
  }

  private stopPolling(): void {
    if (this.timer !== null) clearInterval(this.timer);
  }
}
