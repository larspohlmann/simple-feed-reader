// src/app/reader/recommendations.service.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { ToastService } from '../shared/toast/toast.service';
import { ReaderApi } from './reader-api';
import { RecommendationRunReport } from './models';

const BACKOFF_MS = 1500;
const MAX_BUSY_RETRIES = 5;
/** Matches `RecommendationRun::MAX_TRANSPORT_FAILURES`: the server tolerates
 *  three provider failures before it fails the run itself, so the poll loop
 *  must survive three too. One tick past that reads the run's own verdict. */
const MAX_TRANSPORT_RETRIES = 3;

/** How many times in a row the poll loop has been turned away, per cause.
 *  Each cause has its own ceiling, and any progress resets both. */
interface PollAttempts {
  readonly busy: number;
  readonly transport: number;
}

const NO_ATTEMPTS: PollAttempts = { busy: 0, transport: 0 };

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
      next: (r) => this.onReport(r, NO_ATTEMPTS),
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
        this.step(NO_ATTEMPTS);
      },
      error: () => {
        // Boot resume is best-effort; a failed lookup just means no in-app
        // signal for a run that may or may not still be going server-side.
      },
    });
  }

  private step(attempts: PollAttempts): void {
    this.api.tickRecommendations().subscribe({
      next: (r) => this.onReport(r, attempts),
      error: (e: HttpErrorResponse) => this.retryOrStop(e, attempts),
    });
  }

  private onReport(r: RecommendationRunReport, attempts: PollAttempts): void {
    this.report.set(r);
    switch (r.status) {
      case 'pending':
      case 'running':
        this.step(NO_ATTEMPTS);
        break;
      case 'busy':
        this.backOffWhileBusy(attempts);
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
  private backOffWhileBusy(attempts: PollAttempts): void {
    if (attempts.busy >= MAX_BUSY_RETRIES) {
      this.failure.set({ kind: 'busy' });
      this.finish();
      return;
    }
    this.stepLater({ ...attempts, busy: attempts.busy + 1 });
  }

  /** A tick that fails outright has not ended the run: the server keeps it
   *  running and counts the failure against its own ceiling. Throwing the run
   *  away here loses a batch set that is still being built, so the loop
   *  retries until the server is ready to give its own verdict -- a slow
   *  provider call cut short by the web server's request window is exactly
   *  this case, and it costs the user a whole run otherwise. */
  private retryOrStop(e: HttpErrorResponse, attempts: PollAttempts): void {
    if (attempts.transport >= MAX_TRANSPORT_RETRIES) {
      this.stopWithHttpError(e);
      return;
    }
    this.stepLater({ ...attempts, transport: attempts.transport + 1 });
  }

  private stepLater(attempts: PollAttempts): void {
    setTimeout(() => this.step(attempts), BACKOFF_MS);
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
