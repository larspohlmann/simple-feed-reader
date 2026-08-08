// src/app/reader/for-you-debug-panel.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  WritableSignal,
  effect,
  inject,
  signal,
} from '@angular/core';
import { TranslocoModule } from '@jsverse/transloco';
import { ReaderApi } from './reader-api';
import { DebugLogDetail, DebugLogEntry } from './models';
import { RecommendationsService } from './recommendations.service';

const POLL_MS = 2000;

/** The #309 debug panel: what each provider call sent and what streamed
 *  back, ~2 s fresh while a run is in flight. Server-side truth only -- the
 *  panel never talks to the provider; it re-reads the run log the tick is
 *  checkpointing. Self-hiding: no log rows (debug switch off, or no run
 *  yet) means no panel, so the reader area needs no settings lookup. */
@Component({
  selector: 'app-for-you-debug-panel',
  standalone: true,
  imports: [TranslocoModule],
  templateUrl: './for-you-debug-panel.component.html',
  styleUrl: './for-you-debug-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForYouDebugPanelComponent implements OnInit {
  private readonly api = inject(ReaderApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);

  readonly entries = signal<DebugLogEntry[]>([]);
  /** Fetched bodies by entry id; an id maps once, expanding is then local. */
  readonly details = signal<Map<number, DebugLogDetail>>(new Map());
  readonly expandedRequests = signal<ReadonlySet<number>>(new Set());
  readonly expandedResponses = signal<ReadonlySet<number>>(new Set());

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
    return Math.max(1, Math.round(bytes / 1024));
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
    if (this.details().has(id)) return;
    this.api.debugLogEntry(id).subscribe((detail) => {
      const next = new Map(this.details());
      next.set(id, detail);
      this.details.set(next);
    });
  }

  private fetch(): void {
    this.api.debugLog().subscribe({
      next: (r) => this.entries.set(r.entries),
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
