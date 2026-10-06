import { DestroyRef, Injectable, inject, signal, untracked } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize } from 'rxjs';
import { SettingsApi } from '../settings-api';
import { DebugLogDetail, DebugLogEntry } from '../settings.models';

/** Which debug-log rows are open and the request/response bodies fetched for
 *  them. Provided per log component, so the cache dies with the log. */
@Injectable()
export class DebugLogDetails {
  private readonly api = inject(SettingsApi);
  private readonly destroyRef = inject(DestroyRef);

  private readonly expanded = signal<ReadonlySet<number>>(new Set());
  private readonly details = signal<ReadonlyMap<number, DebugLogDetail>>(new Map());
  /** A response is kept only while its request is still the one recorded here. */
  private readonly inFlight = new Map<number, symbol>();
  private priorVerdicts = new Map<number, DebugLogEntry['verdict']>();

  isExpanded(id: number): boolean {
    return this.expanded().has(id);
  }

  detailFor(id: number): DebugLogDetail | null {
    return this.details().get(id) ?? null;
  }

  toggle(id: number): void {
    const next = new Set(this.expanded());
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
      this.ensureDetail(id);
    }
    this.expanded.set(next);
  }

  /** Evicts any detail fetched while its call streamed, once a poll settles the call. */
  observe(entries: readonly DebugLogEntry[]): void {
    untracked(() => {
      for (const entry of entries) {
        if (this.priorVerdicts.get(entry.id) === null && entry.verdict !== null) {
          this.evict(entry.id);
        }
      }
      this.priorVerdicts = new Map(entries.map((entry) => [entry.id, entry.verdict]));
    });
  }

  clear(): void {
    this.inFlight.clear();
    this.expanded.set(new Set());
    this.details.set(new Map());
  }

  private evict(id: number): void {
    const wasInFlight = this.inFlight.delete(id);
    if (!wasInFlight && !this.details().has(id)) return;
    const next = new Map(this.details());
    next.delete(id);
    this.details.set(next);
    if (this.isExpanded(id)) this.ensureDetail(id);
  }

  private ensureDetail(id: number): void {
    if (this.details().has(id) || this.inFlight.has(id)) return;
    const request = Symbol(id);
    const isCurrent = (): boolean => this.inFlight.get(id) === request;
    this.inFlight.set(id, request);
    this.api
      .debugLogEntry(id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          if (isCurrent()) this.inFlight.delete(id);
        }),
      )
      .subscribe({
        next: (detail) => {
          if (isCurrent()) this.details.set(new Map(this.details()).set(id, detail));
        },
        error: () => undefined,
      });
  }
}
