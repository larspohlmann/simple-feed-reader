// src/app/reader/recommendations.service.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { ToastService } from '../shared/toast/toast.service';
import { ReaderApi } from './reader-api';
import { RecommendationRunReport } from './models';

const BUSY_BACKOFF_MS = 1500;
const MAX_BUSY_RETRIES = 5;

/** Why a recommendation run ended without producing a fresh for-you list. */
export type RecommendationFailure =
  | { kind: 'busy' } // another run holds the lock, and outlasted our retries
  | { kind: 'failed'; error: string | null } // the backend gave up on the run itself
  | { kind: 'http'; problem: Problem };

/** Drives a for-you recommendation run to completion: starts it, ticks the
 *  poll loop, and resumes one left in flight by an earlier session. Modeled
 *  on `RefreshService` -- same shape, same busy-backoff, same "the server
 *  owes us progress" posture -- but the source is a batch run rather than a
 *  feed sweep, so completion and failure are worth telling the user about
 *  directly: this service owns the toast and the "go look" navigation. */
@Injectable({ providedIn: 'root' })
export class RecommendationsService {
  private readonly api = inject(ReaderApi);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly router = inject(Router);

  readonly running = signal(false);
  readonly report = signal<RecommendationRunReport | null>(null);
  /** Null while a run is doing its job. Set exactly once per run, on the
   *  paths that end it without a completed batch set. */
  readonly failure = signal<RecommendationFailure | null>(null);

  /** Increments once per completed run. Consumers watch this to refetch the
   *  for-you list rather than polling `report` themselves. */
  readonly completedStamp = signal(0);

  readonly progress = computed(() => {
    const r = this.report();
    if (!r || !r.batchesTotal) return 0;
    return Math.min(1, Math.max(0, r.batchesDone / r.batchesTotal));
  });

  /** Starts a new run and polls it to completion. */
  start(): void {
    if (this.running()) return;
    this.running.set(true);
    this.report.set(null);
    this.failure.set(null);
    this.api.startRecommendations().subscribe({
      next: (r) => this.onReport(r, 0),
      error: (e: HttpErrorResponse) => this.stopWithHttpError(e),
    });
  }

  /** Best-effort resume on boot: pick up a run left in flight by an earlier
   *  session. Anything other than pending/running -- including a fetch
   *  failure -- is silently ignored; there's nothing to tell the user about a
   *  run they didn't start this session. */
  resume(): void {
    this.api.currentRecommendations().subscribe({
      next: (r) => {
        if (r.status !== 'pending' && r.status !== 'running') return;
        this.running.set(true);
        this.report.set(r);
        this.failure.set(null);
        this.step(0);
      },
      error: () => {
        // Boot resume is best-effort; a failed lookup just means no in-app
        // signal for a run that may or may not still be going server-side.
      },
    });
  }

  private step(busyRetries: number): void {
    this.api.tickRecommendations().subscribe({
      next: (r) => this.onReport(r, busyRetries),
      error: (e: HttpErrorResponse) => this.stopWithHttpError(e),
    });
  }

  private onReport(r: RecommendationRunReport, busyRetries: number): void {
    this.report.set(r);
    switch (r.status) {
      case 'pending':
      case 'running':
        this.step(0);
        break;
      case 'busy':
        this.backOffWhileBusy(busyRetries);
        break;
      case 'completed':
        this.completedStamp.update((n) => n + 1);
        this.finish();
        this.toast.show({
          message: this.i18n.translate('reader.forYouReady'),
          actionLabel: this.i18n.translate('reader.forYouView'),
          action: () => this.navigateToForYou(),
        });
        break;
      case 'failed':
        this.failure.set({ kind: 'failed', error: r.error });
        this.finish();
        this.toast.show({ message: this.i18n.translate('reader.forYouFailed') });
        break;
      case 'none':
        // Nothing running and nothing to report -- reachable only from an
        // unexpected server response, since this service never asks about a
        // run it didn't just start or find already in flight.
        this.finish();
        break;
    }
  }

  /** Another run holds the lock. Wait and ask again -- but only so long: a
   *  CLI-triggered run can hold it well past the patience anyone has for a
   *  spinner. Retrying longer is not the fix; telling the user is. */
  private backOffWhileBusy(busyRetries: number): void {
    if (busyRetries >= MAX_BUSY_RETRIES) {
      this.failure.set({ kind: 'busy' });
      this.finish();
      return;
    }
    setTimeout(() => this.step(busyRetries + 1), BUSY_BACKOFF_MS);
  }

  private stopWithHttpError(e: HttpErrorResponse): void {
    this.failure.set({ kind: 'http', problem: parseProblem(e) });
    this.finish();
  }

  private finish(): void {
    this.running.set(false);
  }

  private navigateToForYou(): void {
    void this.router.navigate(['/'], {
      queryParams: { view: 'for-you', tag: null, subscription: null, entry: null },
    });
  }
}
