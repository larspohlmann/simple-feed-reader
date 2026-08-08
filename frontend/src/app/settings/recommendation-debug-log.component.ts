// src/app/settings/recommendation-debug-log.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  WritableSignal,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoModule } from '@jsverse/transloco';
import { ReaderApi } from '../reader/reader-api';
import { bytesToKb, formatTime } from '../reader/format';
import { DebugLogDetail, DebugLogEntry, DebugLogRunSummary } from '../reader/models';
import { RecommendationsService } from '../reader/recommendations.service';
import { DisclosureComponent } from '../shared/disclosure/disclosure.component';

const POLL_MS = 2000;

/** The #309 debug log: what each provider call sent and what streamed
 *  back, ~2 s fresh while a run is in flight. Server-side truth only -- the
 *  panel never talks to the provider; it re-reads the run log the tick is
 *  checkpointing. Self-hiding: no log rows (debug switch off, or no run
 *  yet) means no panel, so the settings page needs no extra lookup to hide
 *  it. Sits under AI settings, directly below the switch that produces it,
 *  rather than in the reader's "For you" list -- so the common case here is
 *  no run in flight: rows live until the next run starts, and the initial
 *  fetch on creation (not gated on `running()`) is what renders that
 *  previous run's log. */
@Component({
  selector: 'app-recommendation-debug-log',
  standalone: true,
  imports: [DisclosureComponent, TranslocoModule],
  templateUrl: './recommendation-debug-log.component.html',
  styleUrl: './recommendation-debug-log.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationDebugLogComponent implements OnInit {
  private readonly api = inject(ReaderApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);

  readonly entries = signal<DebugLogEntry[]>([]);
  /** The latest run's own summary; null when the user has never run. Drives
   *  the panel's summary strip, distinct from any one row's `errorDetail`. */
  readonly run = signal<DebugLogRunSummary | null>(null);
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
      if (this.recs.running()) this.fetch();
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

  time(iso: string): string {
    return formatTime(iso);
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

  private fetch(): void {
    this.api
      .debugLog()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (r) => {
          this.run.set(r.run);
          this.applyEntries(r.entries);
        },
        error: () => {
          // The panel is best-effort diagnostics; a failed poll shows stale
          // rows rather than an error state of its own.
        },
      });
  }

  /** A detail cached while its call was still streaming holds a partial
   *  response: the poll that later flips `verdict` from null to a real
   *  value must not leave that partial text on display forever. Evicting
   *  the stale cache entry and re-fetching (only when the row is actually
   *  expanded) replaces it with the finished transcript.
   *
   *  The prior-state read is wrapped in `untracked()`: `fetch()` runs inside
   *  the completion `effect`, and an untracked read of `entries()` keeps
   *  that effect from re-triggering itself the instant this method calls
   *  `entries.set()` below. */
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
