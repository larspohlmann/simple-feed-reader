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

  run(onDone?: () => void): void {
    if (this.running()) return;
    this.running.set(true);
    this.report.set(null);
    this.error.set(null);
    this.step(0, onDone);
  }

  private step(busyRetries: number, onDone?: () => void): void {
    this.api.refresh().subscribe({
      next: (r) => {
        this.report.set(r);
        if (r.status === 'partial' && r.remaining > 0) {
          this.step(0, onDone);
        } else if (r.status === 'busy') {
          if (busyRetries >= MAX_BUSY_RETRIES) {
            this.finish(onDone);
          } else {
            setTimeout(() => this.step(busyRetries + 1, onDone), BUSY_BACKOFF_MS);
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
