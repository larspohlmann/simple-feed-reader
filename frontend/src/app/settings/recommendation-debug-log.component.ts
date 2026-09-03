import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  WritableSignal,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoModule } from '@jsverse/transloco';
import { ReaderApi } from '../reader/reader-api';
import { bytesToKb, formatDayInMonth, formatTime } from '../reader/format';
import { LanguageService } from '../core/language.service';
import {
  DebugLogDetail,
  DebugLogEntry,
  DebugLogRunChoice,
  DebugLogRunSummary,
} from '../reader/models';
import { RecommendationsService } from '../reader/recommendations.service';

const POLL_MS = 2000;

/** The calls of one run, as the panel groups them: a header line plus the
 *  rows, newest run first. */
export interface RunGroup {
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
  imports: [TranslocoModule],
  templateUrl: './recommendation-debug-log.component.html',
  styleUrl: './recommendation-debug-log.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationDebugLogComponent implements OnInit {
  private readonly api = inject(ReaderApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly language = inject(LanguageService);

  readonly entries = signal<DebugLogEntry[]>([]);
  /** The entries clustered by run, newest first. A resumed run keeps
   *  appending to the log, so a flat list would mix runs; grouping keeps each
   *  run's calls under one header. Rows arrive ordered by id and are already
   *  contiguous -- this only marks boundaries and flips the run order. */
  readonly groups = computed<RunGroup[]>(() => {
    const groups: RunGroup[] = [];
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
  /** Fetched bodies by entry id; an id maps once and expanding is then
   *  local -- except a detail cached while its verdict was still null,
   *  which the next poll evicts once the call settles (see
   *  `refreshDetailAfterCompletion`). */
  readonly details = signal<Map<number, DebugLogDetail>>(new Map());
  readonly expandedRequests = signal<ReadonlySet<number>>(new Set());
  readonly expandedResponses = signal<ReadonlySet<number>>(new Set());

  private timer: ReturnType<typeof setInterval> | null = null;
  /** Ids with a `debugLogEntry` request already in flight. Guards against a
   *  rapid open/close/open before the first response lands: `details()` is
   *  still empty at that point, so without this a second request would fire
   *  and race the first. */
  private readonly pendingDetailIds = new Set<number>();

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

  toggleRequest(id: number): void {
    this.toggle(this.expandedRequests, id);
  }

  toggleResponse(id: number): void {
    this.toggle(this.expandedResponses, id);
  }

  /** The row grid's single expander: opens (or closes) both bodies together,
   *  so one click reveals the full request/response pair. Built purely from
   *  the two existing toggles -- their lazy-fetch and dedup behaviour is
   *  untouched, this only drives both from one control. */
  toggleRow(id: number): void {
    this.toggleRequest(id);
    this.toggleResponse(id);
  }

  isRowExpanded(id: number): boolean {
    return this.expandedRequests().has(id);
  }

  copy(text: string): void {
    void navigator.clipboard.writeText(text);
  }

  requestText(entry: DebugLogEntry): string | null {
    return this.details().get(entry.id)?.requestBody ?? null;
  }

  responseText(entry: DebugLogEntry): string | null {
    if (entry.verdict === null) return entry.streamingText;
    return this.details().get(entry.id)?.responseText ?? null;
  }

  kb(bytes: number): number {
    return bytesToKb(bytes);
  }

  /** Date and clock time together, e.g. "21 Aug 22:54": the debug log spans
   *  several days of runs, so the day is shown beside every time (#541). */
  time(iso: string): string {
    return `${formatDayInMonth(iso, this.language.lang())} ${formatTime(iso)}`;
  }

  /** Seconds a settled call took, or null while it is still streaming --
   *  `finishedAt` is null then, and rendering a duration from a moving
   *  target would show a nonsensical or negative figure. Clamped at 0 for
   *  the same reason: a clock skew must never surface as a negative time. */
  durationSeconds(entry: DebugLogEntry): number | null {
    if (entry.finishedAt === null) return null;
    const elapsedMs = new Date(entry.finishedAt).getTime() - new Date(entry.createdAt).getTime();
    return Math.max(0, Math.round(elapsedMs / 1000));
  }

  /** When a run group's first call went out. */
  groupStart(group: RunGroup): string {
    return this.time(group.entries[0].createdAt);
  }

  /** When a run group's last call settled, or null while one is still
   *  streaming -- the header then shows an open-ended range. */
  groupEnd(group: RunGroup): string | null {
    const finishedAt = group.entries.at(-1)?.finishedAt ?? null;
    return finishedAt === null ? null : this.time(finishedAt);
  }

  private toggle(expanded: WritableSignal<ReadonlySet<number>>, id: number): void {
    const next = new Set(expanded());
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
      this.ensureDetail(id);
    }
    expanded.set(next);
  }

  private ensureDetail(id: number): void {
    if (this.details().has(id) || this.pendingDetailIds.has(id)) return;
    this.pendingDetailIds.add(id);
    this.api
      .debugLogEntry(id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (detail) => {
          const next = new Map(this.details());
          next.set(id, detail);
          this.details.set(next);
        },
        complete: () => this.pendingDetailIds.delete(id),
        error: () => this.pendingDetailIds.delete(id),
      });
  }

  /** Switches the panel to another retained run. Selecting the newest is the
   *  same as following it, so it clears the selection rather than pinning it —
   *  otherwise the panel would stop tracking the next run that starts. */
  selectRun(runId: number): void {
    const newest = this.runs()[0]?.id ?? null;
    this.selectedRunId.set(runId === newest ? null : runId);
    this.details.set(new Map());
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
        next: (r) => {
          this.run.set(r.run);
          this.runs.set(r.runs);
          this.applyEntries(r.entries);
        },
        error: () => {
          // The panel is best-effort diagnostics; a failed poll shows stale
          // rows rather than an error state of its own.
        },
      });
  }

  /** A detail cached while its call was still streaming holds a partial
   *  response; the poll that later flips `verdict` to a real value must not
   *  leave that partial text on display, so this evicts the stale cache
   *  entry and re-fetches (only when the row is expanded).
   *
   *  `untracked()` wraps the prior-state read because `fetch()` runs inside
   *  the completion `effect`, and a tracked read of `entries()` would
   *  re-trigger that effect the instant this method calls `entries.set()`. */
  private applyEntries(entries: DebugLogEntry[]): void {
    const priorVerdictById = new Map(
      untracked(this.entries).map((entry) => [entry.id, entry.verdict]),
    );
    this.entries.set(entries);

    for (const entry of entries) {
      if (priorVerdictById.get(entry.id) === null && entry.verdict !== null) {
        this.refreshDetailAfterCompletion(entry.id);
      }
    }
  }

  private refreshDetailAfterCompletion(id: number): void {
    if (!this.details().has(id)) return;

    const next = new Map(this.details());
    next.delete(id);
    this.details.set(next);

    if (this.expandedRequests().has(id) || this.expandedResponses().has(id)) {
      this.ensureDetail(id);
    }
  }

  private stopPolling(): void {
    if (this.timer !== null) clearInterval(this.timer);
  }
}
