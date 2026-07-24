// src/app/reader/refresh.service.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { RefreshReport } from './models';

const BUSY_BACKOFF_MS = 1500;
const MAX_BUSY_RETRIES = 5;

@Injectable({ providedIn: 'root' })
export class RefreshService {
  private readonly api = inject(ReaderApi);

  readonly running = signal(false);
  readonly report = signal<RefreshReport | null>(null);
  readonly error = signal<Problem | null>(null);

  readonly progress = computed(() => {
    const r = this.report();
    if (!r || r.total <= 0) return 0;
    return Math.min(1, Math.max(0, (r.total - r.remaining) / r.total));
  });

  /** Pass feedId to populate a single feed (e.g. one just added); omit it to
   *  sweep all the caller's due feeds. The scope holds across the poll loop. */
  run(onDone?: () => void, feedId?: number): void {
    if (this.running()) return;
    this.running.set(true);
    this.report.set(null);
    this.error.set(null);
    this.step(0, onDone, feedId);
  }

  private step(busyRetries: number, onDone?: () => void, feedId?: number): void {
    this.api.refresh(feedId).subscribe({
      next: (r) => {
        this.report.set(r);
        if (r.status === 'partial' && r.remaining > 0) {
          this.step(0, onDone, feedId);
        } else if (r.status === 'busy') {
          if (busyRetries >= MAX_BUSY_RETRIES) {
            this.finish(onDone);
          } else {
            setTimeout(() => this.step(busyRetries + 1, onDone, feedId), BUSY_BACKOFF_MS);
          }
        } else {
          this.finish(onDone); // completed | aborted
        }
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.finish(onDone);
      },
    });
  }

  private finish(onDone?: () => void): void {
    this.running.set(false);
    onDone?.();
  }
}
