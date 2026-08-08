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
/** How long the client waits before its next tick once a background worker
 *  owns execution. A deferred tick returns instantly rather than blocking for
 *  the length of a batch, so the tight recursive loop tuned for a
 *  minutes-long server call would otherwise hammer the endpoint: 4s ≈ 15
 *  requests/min against the `ai_recommendations` limiter's 90 per 5 minutes. */
const BACKGROUND_POLL_MS = 4000;
/** How long the client waits before retrying a tick that hit the
 *  `ai_recommendations` limiter (90 requests / 5 minutes per user, a sliding
 *  window -- see `backend/config/packages/rate_limiter.yaml`). A 429 means
 *  the bucket is full, not that the server is unhealthy: retrying at
 *  `BACKOFF_MS` would just spend another token against the same window and
 *  never let it drain. The window's own average allowed rate is one request
 *  every ~3.3s (90/300s); 15s keeps a retrying tab well under that even while
 *  it shares the bucket with the ordinary background cadence and, worst
 *  case, a second tab open on the same account. */
const RATE_LIMIT_POLL_MS = 15000;
/** How many consecutive 429s the loop rides out before giving up. At
 *  `RATE_LIMIT_POLL_MS` that ceiling is 5 minutes -- exactly the limiter's
 *  own window -- long enough for an honest two-tab session to fully drain
 *  the bucket and recover, while still guaranteeing the loop terminates
 *  against a server that keeps rejecting for good. */
const MAX_RATE_LIMIT_RETRIES = 20;

/** How many times in a row the poll loop has been turned away, per cause.
 *  Each cause has its own ceiling, and any progress resets all three. */
interface PollAttempts {
  readonly busy: number;
  readonly transport: number;
  readonly rateLimited: number;
}

const NO_ATTEMPTS: PollAttempts = { busy: 0, transport: 0, rateLimited: 0 };

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

  /** One turn of the poll loop, against whichever endpoint is honest right
   *  now. While a background worker owns execution the tick endpoint does no
   *  work at all -- it returns the very same report `current` does -- so
   *  polling it only spends the `ai_recommendations` limiter (90 per 5
   *  minutes, per user): one tab at `BACKGROUND_POLL_MS` burns 75 of them and
   *  a second tab of the same account 429s. `current` is a plain read and
   *  carries no limiter by design. The moment a report says the worker is
   *  gone the loop returns to `tick`, so a dying worker still gets the run
   *  advanced by the client -- that self-healing fallback is the point of the
   *  whole design. */
  private step(attempts: PollAttempts): void {
    const poll = this.report()?.background
      ? this.api.currentRecommendations()
      : this.api.tickRecommendations();

    poll.subscribe({
      next: (r) => this.onReport(r, attempts),
      error: (e: HttpErrorResponse) => this.retryOrStop(e, attempts),
    });
  }

  private onReport(r: RecommendationRunReport, attempts: PollAttempts): void {
    this.report.set(r);
    switch (r.status) {
      case 'pending':
      case 'running':
        if (r.background) this.stepLater(NO_ATTEMPTS, BACKGROUND_POLL_MS);
        else this.step(NO_ATTEMPTS);
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
   *  this case, and it costs the user a whole run otherwise. A 429 is not
   *  this case at all -- the server is healthy and the run is still
   *  progressing, the client has just asked too often -- so it gets its own
   *  branch, own counter, and own (much longer) wait rather than spending
   *  the transport ceiling meant for an unhealthy server. */
  private retryOrStop(e: HttpErrorResponse, attempts: PollAttempts): void {
    if (e.status === 429) {
      this.backOffWhileRateLimited(e, attempts);
      return;
    }
    if (attempts.transport >= MAX_TRANSPORT_RETRIES) {
      this.stopWithHttpError(e);
      return;
    }
    this.stepLater({ ...attempts, transport: attempts.transport + 1 });
  }

  /** Waits out the `ai_recommendations` limiter's sliding window rather than
   *  declaring the run dead -- but only so long: a server that keeps
   *  rejecting for good must still end the run rather than poll forever. */
  private backOffWhileRateLimited(e: HttpErrorResponse, attempts: PollAttempts): void {
    if (attempts.rateLimited >= MAX_RATE_LIMIT_RETRIES) {
      this.stopWithHttpError(e);
      return;
    }
    this.stepLater({ ...attempts, rateLimited: attempts.rateLimited + 1 }, RATE_LIMIT_POLL_MS);
  }

  private stepLater(attempts: PollAttempts, delayMs = BACKOFF_MS): void {
    setTimeout(() => this.step(attempts), delayMs);
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
