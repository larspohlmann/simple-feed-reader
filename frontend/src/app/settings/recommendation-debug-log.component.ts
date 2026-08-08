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
import { bytesToKb } from '../reader/format';
import { DebugLogDetail, DebugLogEntry } from '../reader/models';
import { RecommendationsService } from '../reader/recommendations.service';

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
  imports: [TranslocoModule],
  templateUrl: './recommendation-debug-log.component.html',
  styleUrl: './recommendation-debug-log.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationDebugLogComponent implements OnInit {
  private readonly api = inject(ReaderApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);

  readonly entries = signal<DebugLogEntry[]>([]);
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
        next: (r) => this.applyEntries(r.entries),
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
