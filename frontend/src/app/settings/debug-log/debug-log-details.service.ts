import { DestroyRef, Injectable, inject, signal, untracked } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
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
  /** Guards a rapid open/close/open before the first response lands: no
   *  detail is cached yet, so without it a second request would race the first. */
  private readonly pendingIds = new Set<number>();
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

  /** Takes each poll's entries. A detail cached while its call was still
   *  streaming holds a partial response, so the poll that settles the verdict
   *  evicts it, and refetches it when the row is open. Untracked because the
   *  caller may poll from inside an effect. */
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
    this.details.set(new Map());
  }

  private evict(id: number): void {
    if (!this.details().has(id)) return;
    const next = new Map(this.details());
    next.delete(id);
    this.details.set(next);
    if (this.isExpanded(id)) this.ensureDetail(id);
  }

  private ensureDetail(id: number): void {
    if (this.details().has(id) || this.pendingIds.has(id)) return;
    this.pendingIds.add(id);
    this.api
      .debugLogEntry(id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (detail) => this.details.set(new Map(this.details()).set(id, detail)),
        complete: () => this.pendingIds.delete(id),
        error: () => this.pendingIds.delete(id),
      });
  }
}
