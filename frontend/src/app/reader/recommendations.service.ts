// src/app/reader/recommendations.service.ts
import { HttpErrorResponse } from '@angular/common/http';
import { DestroyRef, InjectionToken, Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { Problem, parseProblem } from '../core/problem';
import { ToastService } from '../shared/toast/toast.service';
import { ReaderApi } from './reader-api';
import { RecommendationRunReport } from './models';
import { selectionQueryParams } from './query';

const BACKOFF_MS = 1500;
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

/** Ticker cadence for the anticipatory bar. Fine enough to read as motion,
 *  coarse enough to be cheap; the shared hairline's own `width` transition
 *  smooths between ticks. */
const TICK_MS = 200;
/** How far into the gap to the next milestone the creep is allowed to reach.
 *  Strictly < 1 so the bar never claims a step done before the server confirms
 *  it; the real completion snaps it the rest of the way. */
const CREEP_CAP = 0.92;

const clamp01 = (value: number): number => Math.min(1, Math.max(0, value));

/** How many times in a row the poll loop has been turned away, per cause.
 *  Each cause has its own ceiling, and any progress resets both. */
interface PollAttempts {
  readonly transport: number;
  readonly rateLimited: number;
}

const NO_ATTEMPTS: PollAttempts = { transport: 0, rateLimited: 0 };

/** Monotonic wall-clock in ms. Injectable so tests drive time deterministically
 *  instead of leaning on a real clock. */
export const MONOTONIC_NOW = new InjectionToken<() => number>('MONOTONIC_NOW', {
  providedIn: 'root',
  factory: () => () => performance.now(),
});

/** Why a recommendation run ended without producing a fresh for-you list. */
export type RecommendationFailure =
  | { kind: 'failed'; error: string | null } // the backend gave up on the run itself
  | { kind: 'http'; problem: Problem };

/** Drives a for-you recommendation run to completion: starts it, ticks the
 *  poll loop, and resumes one left in flight by an earlier session. Modeled
 *  on `RefreshService` -- same shape, same "the server owes us progress"
 *  posture -- but the source is a batch run rather than a feed sweep, so
 *  completion and failure are worth telling the user about directly: this
 *  service owns the toast and the "go look" navigation. */
@Injectable({ providedIn: 'root' })
export class RecommendationsService {
  private readonly api = inject(ReaderApi);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly router = inject(Router);
  private readonly now = inject(MONOTONIC_NOW);

  readonly running = signal(false);
  /** True from the moment the user asks to stop until the run actually ends.
   *  The two are not the same instant: a tick already inside a provider call
   *  keeps going until that call returns, so the button must be able to say
   *  "stopping" rather than pretend the run is already over. */
  readonly stopping = signal(false);
  readonly report = signal<RecommendationRunReport | null>(null);
  /** Null while a run is doing its job. Set exactly once per run, on the
   *  paths that end it without a completed batch set. */
  readonly failure = signal<RecommendationFailure | null>(null);

  /** Monotonic ms when the in-flight batch began, from our point of view: set
   *  whenever `batchesDone` changes (a completion) and on the first report. */
  private readonly currentBatchStart = signal<number | null>(null);

  /** Blended seconds-per-completed-batch (`elapsedSeconds / batchesDone`);
   *  null until batch 1 completes, which is the honest-blank window. */
  private readonly avgCompletedSeconds = signal<number | null>(null);

  /** True while the poll loop is waiting out the 429 limiter. The ticker is
   *  paused for the duration (see `backOffWhileRateLimited`), so the bar holds
   *  its last value instead of creeping to its cap, and the ETA number is
   *  swapped for a wait label rather than letting the estimate balloon while
   *  nothing is actually progressing. */
  readonly rateLimited = signal(false);

  /** Ticker handle; the bar re-reads the clock on every bump. */
  private tickerId: ReturnType<typeof setInterval> | null = null;

  /** Bumped by the ticker so the interpolated reads recompute between polls. */
  private readonly frame = signal(0);

  /** Increments once per completed run. Consumers watch this to refetch the
   *  for-you list rather than polling `report` themselves. */
  readonly completedStamp = signal(0);

  readonly progress = computed(() => {
    this.frame(); // re-run on every ticker bump
    const current = this.report();
    if (!current || !current.batchesTotal) return 0;

    const base = clamp01(current.batchesDone / current.batchesTotal);
    const average = this.avgCompletedSeconds();
    const batchStart = this.currentBatchStart();
    if (average === null || batchStart === null) return base; // honest blank

    const next = clamp01((current.batchesDone + 1) / current.batchesTotal);
    const secondsIntoBatch = (this.now() - batchStart) / 1000;
    const fractionIntoBatch = clamp01(secondsIntoBatch / average);
    return clamp01(base + fractionIntoBatch * (next - base) * CREEP_CAP);
  });

  /** Ceil seconds remaining, or `null` when there is no run, no total, the run
   *  is not actively progressing, or no average yet exists (before batch 1). */
  readonly etaSeconds = computed<number | null>(() => {
    this.frame();
    const current = this.report();
    if (!current || !current.batchesTotal) return null;
    if (current.status !== 'running' && current.status !== 'pending') return null;

    const average = this.avgCompletedSeconds();
    const batchStart = this.currentBatchStart();
    if (average === null || batchStart === null) return null;

    const batchesRemaining = current.batchesTotal - current.batchesDone; // includes the in-flight one
    const secondsIntoBatch = (this.now() - batchStart) / 1000;
    return Math.max(0, Math.ceil(average * batchesRemaining - secondsIntoBatch));
  });

  /** Drives the Task 6 label: hidden outside a run, starting before the first
   *  average exists, waiting during a 429 backoff, eta otherwise. */
  readonly etaState = computed<'hidden' | 'starting' | 'waiting' | 'eta'>(() => {
    if (!this.running()) return 'hidden';
    if (this.rateLimited()) return 'waiting';
    if (this.etaSeconds() === null) return 'starting';
    return 'eta';
  });

  /** True while a background worker owns this run's execution, so the
   *  client's own poll loop is a pure status read rather than the thing
   *  driving progress. Shapes `report()` for both the service's own poll
   *  loop and the template, so none of the three reads `report()?.background`
   *  directly. */
  readonly workerOwnsRun = computed(() => this.report()?.background ?? false);

  /** The surviving for-you list's item count, for the sidebar badge. */
  readonly forYouCount = computed(() => this.report()?.forYou.itemCount ?? 0);
  /** The surviving for-you list's generation time (ISO), for the list
   *  header's "Last refreshed" hint. */
  readonly generatedAt = computed(() => this.report()?.forYou.generatedAt ?? null);
  /** The id of the run that generated the surviving for-you list. The reader
   *  suppresses this one run's boundary divider — the header already names it —
   *  by identity, not by timestamp (#348). */
  readonly newestRunId = computed(() => this.report()?.forYou.newestRunId ?? null);

  constructor() {
    inject(DestroyRef).onDestroy(() => this.stopTicker());
  }

  /** Starts a new run and polls it to completion. */
  start(): void {
    this.beginRun(this.api.startRecommendations());
  }

  /** Resumes the latest failed run at the batch that failed, then polls it to
   *  completion. The client offers this only when it has seen a failed run, so
   *  a 409 here is a stale click and surfaces through the same error path as a
   *  failed start. */
  resumeRun(): void {
    this.beginRun(this.api.resumeRecommendations());
  }

  /** Shared entry for both start and resume: guard against a double-run, reset
   *  the per-run signals, then drive the returned run report into the poll
   *  loop. The two differ only in which endpoint opens the run. */
  private beginRun(source: Observable<RecommendationRunReport>): void {
    if (this.running()) return;
    this.running.set(true);
    this.startTicker();
    this.stopping.set(false);
    this.report.set(null);
    this.failure.set(null);
    source.subscribe({
      next: (r) => this.onReport(r),
      error: (e: HttpErrorResponse) => this.stopWithHttpError(e),
    });
  }

  /** Asks the server to stop the run. The poll loop is deliberately left
   *  alone: it is the loop that will observe the run reaching `cancelled` and
   *  tear itself down, so stopping stays a single source of truth rather than
   *  two halves that can disagree. A failure just clears the flag -- the run
   *  is still going, and saying otherwise would be a lie. */
  stop(): void {
    if (!this.running() || this.stopping()) return;
    this.stopping.set(true);
    this.api.stopRecommendations().subscribe({
      next: (r) => this.onReport(r),
      error: () => this.stopping.set(false),
    });
  }

  /** Re-reads the current run/for-you status without starting or advancing
   *  anything. Best-effort like `resume()`'s own lookup: a failed refresh
   *  just leaves the last known report in place rather than surfacing a
   *  second error path for what is, from here, a read-only side effect. */
  refreshStatus(): void {
    this.api.currentRecommendations().subscribe({
      next: (r) => this.applyReport(r),
      error: () => {
        // Best-effort; see the docblock above.
      },
    });
  }

  /** Best-effort resume on boot: pick up a run left in flight by an earlier
   *  session. Anything other than pending/running -- including a fetch
   *  failure -- is silently ignored; there's nothing to tell the user about a
   *  run they didn't start this session. */
  resume(): void {
    this.api.currentRecommendations().subscribe({
      next: (r) => {
        this.applyReport(r); // even a finished run carries the for-you summary the sidebar needs
        if (r.status !== 'pending' && r.status !== 'running') return;
        this.running.set(true);
        this.startTicker();
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
    const poll = this.workerOwnsRun()
      ? this.api.currentRecommendations()
      : this.api.tickRecommendations();

    poll.subscribe({
      next: (r) => this.onReport(r),
      error: (e: HttpErrorResponse) => this.retryOrStop(e, attempts),
    });
  }

  /** The single place a fresh report lands: it re-blends the average and
   *  re-anchors the current batch whenever `batchesDone` moves, clears the
   *  rate-limited flag on any live report, then stores the report. */
  private applyReport(next: RecommendationRunReport): void {
    const previousDone = this.report()?.batchesDone ?? -1;
    if (next.batchesDone !== previousDone) {
      this.avgCompletedSeconds.set(
        next.batchesDone >= 1 && next.elapsedSeconds !== null
          ? next.elapsedSeconds / next.batchesDone
          : null,
      );
      this.currentBatchStart.set(this.now());
    }
    if (next.status === 'running' || next.status === 'pending') {
      this.rateLimited.set(false);
      this.startTicker(); // resume the bar if a rate-limit backoff had paused it
    }
    this.report.set(next);
  }

  private onReport(r: RecommendationRunReport): void {
    this.applyReport(r);
    switch (r.status) {
      case 'pending':
      case 'running':
        if (this.workerOwnsRun()) this.stepLater(NO_ATTEMPTS, BACKGROUND_POLL_MS);
        else this.step(NO_ATTEMPTS);
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
      case 'cancelled':
        // No toast and no failure: the user asked for this and is looking at
        // the button they just pressed. Announcing it back to them is noise.
        this.finish();
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
    this.stopTicker(); // freeze the bar: with no ticker bump, progress() holds its last value
    this.rateLimited.set(true);
    this.stepLater({ ...attempts, rateLimited: attempts.rateLimited + 1 }, RATE_LIMIT_POLL_MS);
  }

  private stepLater(attempts: PollAttempts, delayMs = BACKOFF_MS): void {
    setTimeout(() => this.step(attempts), delayMs);
  }

  private stopWithHttpError(e: HttpErrorResponse): void {
    this.failure.set({ kind: 'http', problem: parseProblem(e) });
    this.finish();
    // The run's only in-reader surface is the progress hairline, which vanishes
    // the moment the run ends. A request that fails outright — the start POST,
    // or the poll loop giving up after its transport/rate-limit ceiling — would
    // leave nothing behind without this, so it goes to the same toast the
    // backend-side failure uses rather than failing silently (#325).
    this.toast.show({ message: this.i18n.translate('reader.forYouUnreachable') });
  }

  private finish(): void {
    this.running.set(false);
    this.stopping.set(false);
    this.rateLimited.set(false);
    this.stopTicker();
  }

  private startTicker(): void {
    if (this.tickerId !== null) return;
    this.tickerId = setInterval(() => this.frame.update((n) => n + 1), TICK_MS);
  }

  private stopTicker(): void {
    if (this.tickerId === null) return;
    clearInterval(this.tickerId);
    this.tickerId = null;
  }

  private navigateToForYou(): void {
    void this.router.navigate(['/'], {
      queryParams: selectionQueryParams({ view: 'for-you' }),
    });
  }
}
