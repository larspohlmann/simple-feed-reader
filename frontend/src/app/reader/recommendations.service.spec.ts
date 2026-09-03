import { TestBed, fakeAsync, tick, discardPeriodicTasks } from '@angular/core/testing';
import { WritableSignal, signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { API_BASE_URL } from '../core/api';
import { ToastService } from '../shared/toast/toast.service';
import { MONOTONIC_NOW, RecommendationsService } from './recommendations.service';
import { RecommendationRunReport } from './models';
import { ForYouProgressComponent } from './for-you-progress/for-you-progress.component';
import { LayoutService } from './layout.service';

const report = (over: Partial<RecommendationRunReport>): RecommendationRunReport => ({
  status: 'pending',
  batchesTotal: null,
  batchesDone: 0,
  error: null,
  background: false,
  streamedChars: 0,
  elapsedSeconds: null,
  firstBatchStarted: true,
  forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
  ...over,
});

describe('RecommendationsService', () => {
  let svc: RecommendationsService;
  let ctrl: HttpTestingController;
  let toast: { show: jest.Mock; dismiss: jest.Mock; visible: WritableSignal<boolean> };
  // The pill is the narrow layout's surface; above the drawer breakpoint the
  // reader header carries the run instead. Default to narrow so every test
  // below reads as it always did, and cross it explicitly where that matters.
  let isNarrow: WritableSignal<boolean>;
  let navigate: jest.Mock;
  let nowMs = 0;

  beforeEach(() => {
    const visible = signal(false);
    toast = {
      show: jest.fn(() => visible.set(true)),
      dismiss: jest.fn(() => visible.set(false)),
      visible,
    };
    navigate = jest.fn();
    isNarrow = signal(true);
    nowMs = 0;
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: ToastService, useValue: toast },
        { provide: TranslocoService, useValue: { translate: (k: string) => k } },
        { provide: Router, useValue: { navigate } },
        { provide: MONOTONIC_NOW, useValue: () => nowMs },
        { provide: LayoutService, useValue: { isNarrow } },
      ],
    });
    svc = TestBed.inject(RecommendationsService);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  /** A `running` flush with `background: false` re-polls immediately, leaving
   *  one open tick request. The creep tests below drive to a milestone and then
   *  settle that request with a `completed` report to end the run cleanly. */
  const drainTrailingTick = (): void => {
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 4, batchesDone: 4, elapsedSeconds: 80 }));
  };

  it('starts a run, ticks until completed, and shows the ready toast', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));
    expect(svc.running()).toBe(true);

    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 3,
        batchesDone: 1,
        elapsedSeconds: 20,
        etaSeconds: 40,
      }),
    );
    expect(svc.running()).toBe(true);
    expect(svc.progress()).toBeCloseTo(1 / 3);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 3, batchesDone: 3 }));

    expect(svc.running()).toBe(false);
    expect(svc.completedStamp()).toBe(1);
    expect(toast.show).toHaveBeenCalledTimes(2); // the pill, then the ready message
    expect(toast.show).toHaveBeenLastCalledWith(
      expect.objectContaining({ message: 'reader.forYouReady', width: 'fixed' }),
    );
  });

  it('resumeRun opens the run via the resume endpoint, then ticks to completion', () => {
    svc.resumeRun();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/resume')
      .flush(report({ status: 'running', batchesTotal: 3, batchesDone: 2 }));
    expect(svc.running()).toBe(true);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 3, batchesDone: 3 }));

    expect(svc.running()).toBe(false);
    expect(svc.completedStamp()).toBe(1);
  });

  it('the ready toast action navigates to the for-you view', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'completed', batchesTotal: 1, batchesDone: 1 }));

    const call = toast.show.mock.calls.at(-1)![0] as { action?: () => void };
    call.action?.();

    expect(navigate).toHaveBeenCalledWith(['/'], {
      queryParams: {
        view: 'for-you',
        tag: null,
        subscription: null,
        entry: null,
        q: null,
        searchOrigin: null,
      },
    });
  });

  it('records a failed run, shows the failure toast, and issues no further requests', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'failed', error: 'boom' }));

    ctrl.verify(); // no further requests
    expect(svc.running()).toBe(false);
    expect(svc.failure()).toEqual({ kind: 'failed', error: 'boom' });
    expect(toast.show).toHaveBeenCalledTimes(2); // the pill, then the failure message
    expect(toast.show).toHaveBeenLastCalledWith(
      expect.objectContaining({ message: 'reader.forYouFailed', width: 'fixed' }),
    );
  });

  it('resume continues ticking a pending/running run', () => {
    svc.resume();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/current')
      .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));
    expect(svc.running()).toBe(true);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 2, batchesDone: 2 }));
    expect(svc.running()).toBe(false);
    expect(svc.completedStamp()).toBe(1);
  });

  it('a second resume() during a live run neither re-raises a closed pill nor starts a second poll loop', () => {
    svc.resume();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/current')
      .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));
    const firstTick = ctrl.expectOne('https://api.test/api/recommendations/runs/tick');

    toast.dismiss(); // the user pressed ✕
    expect(svc.pillHidden()).toBe(true);
    toast.show.mockClear();

    // The reader shell calls resume() again on every mount (reader -> another
    // route -> reader), and the server still answers 'running' for the same run.
    svc.resume();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/current')
      .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));

    // The pill must not come back on its own, and no second tick request
    // should be outstanding alongside the first.
    expect(toast.show).not.toHaveBeenCalled();
    expect(svc.pillHidden()).toBe(true);
    ctrl.expectNone('https://api.test/api/recommendations/runs/tick');

    firstTick.flush(report({ status: 'completed', batchesTotal: 2, batchesDone: 2 }));
    expect(svc.running()).toBe(false);
  });

  it('resume does nothing for an already-completed run (no toast)', () => {
    svc.resume();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/current')
      .flush(report({ status: 'completed', batchesTotal: 2, batchesDone: 2 }));

    ctrl.verify(); // no tick request
    expect(svc.running()).toBe(false);
    expect(toast.show).not.toHaveBeenCalled();
  });

  it('resume stores a completed run report so the for-you summary is available at boot', () => {
    svc.resume();
    ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush(
      report({
        status: 'completed',
        batchesTotal: 2,
        batchesDone: 2,
        forYou: { itemCount: 7, generatedAt: '2026-08-08T09:00:00Z', newestRunId: null },
      }),
    );

    ctrl.verify(); // no tick request -- a finished run is not resumed
    expect(svc.running()).toBe(false);
    expect(svc.forYouCount()).toBe(7);
    expect(svc.generatedAt()).toBe('2026-08-08T09:00:00Z');
  });

  it('resume swallows a fetch error rather than surfacing a failure', () => {
    svc.resume();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/current')
      .flush('boom', { status: 500, statusText: 'Server Error' });

    expect(svc.running()).toBe(false);
    expect(svc.failure()).toBeNull();
    expect(toast.show).not.toHaveBeenCalled();
  });

  const failTick = (): void =>
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(
        { type: 'server_error', title: 't', status: 500 },
        { status: 500, statusText: 'Server Error' },
      );

  it('keeps polling a run the server still holds after a failed tick', fakeAsync(() => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));

    failTick();
    expect(svc.running()).toBe(true);
    expect(svc.failure()).toBeNull();

    tick(1500);
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 2, batchesDone: 2 }));

    expect(svc.running()).toBe(false);
    expect(svc.failure()).toBeNull();
    expect(svc.completedStamp()).toBe(1);
  }));

  it('stops and records the problem once the tick retry budget is spent', fakeAsync(() => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));

    failTick();
    for (let attempt = 1; attempt <= 3; attempt++) {
      tick(1500);
      failTick();
    }

    ctrl.verify(); // the loop gave up rather than polling on
    expect(svc.running()).toBe(false);
    const failure = svc.failure();
    expect(failure?.kind).toBe('http');
    expect(failure).toMatchObject({ problem: { status: 500, type: 'server_error' } });
  }));

  it('stops on an HTTP error from start, which has no run to keep polling', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(
        { type: 'server_error', title: 't', status: 500 },
        { status: 500, statusText: 'Server Error' },
      );

    ctrl.verify(); // no tick request
    expect(svc.running()).toBe(false);
    expect(svc.failure()?.kind).toBe('http');
    // The run's only surface is the app-wide pill, and `finish()` has just
    // taken it down. Without this toast an outright request failure would be
    // silent (#325).
    expect(toast.show).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'reader.forYouUnreachable', width: 'fixed' }),
    );
  });

  describe('stopping a run', () => {
    it('posts the stop, ends the run, and stays quiet about it', () => {
      svc.start();
      ctrl
        .expectOne('https://api.test/api/recommendations/runs')
        .flush(report({ status: 'running', batchesTotal: 3, batchesDone: 1 }));
      const inFlight = ctrl.expectOne('https://api.test/api/recommendations/runs/tick');

      svc.stop();
      expect(svc.stopping()).toBe(true);

      ctrl
        .expectOne('https://api.test/api/recommendations/runs/stop')
        .flush(report({ status: 'cancelled', batchesTotal: 3, batchesDone: 1 }));

      expect(svc.running()).toBe(false);
      expect(svc.stopping()).toBe(false);
      // The user pressed the button; telling them it worked is noise, and a
      // failure toast would be a lie about what happened.
      expect(toast.show).toHaveBeenCalledTimes(1); // the pill, and nothing stop adds
      expect(svc.failure()).toBeNull();

      // The tick that was already in flight when they pressed stop still
      // answers. It must not restart the loop -- that is the bug where the
      // button appears to work and the run keeps going.
      inFlight.flush(report({ status: 'cancelled', batchesTotal: 3, batchesDone: 1 }));
      expect(svc.running()).toBe(false);
      ctrl.verify();
    });

    it('keeps the run going when the stop request fails', () => {
      svc.start();
      ctrl
        .expectOne('https://api.test/api/recommendations/runs')
        .flush(report({ status: 'running', batchesTotal: 3, batchesDone: 1 }));
      const inFlight = ctrl.expectOne('https://api.test/api/recommendations/runs/tick');

      svc.stop();
      ctrl
        .expectOne('https://api.test/api/recommendations/runs/stop')
        .flush({}, { status: 500, statusText: 'Server Error' });

      // Claiming the run stopped when the server never agreed would strand
      // the user with a run still spending their money.
      expect(svc.stopping()).toBe(false);
      expect(svc.running()).toBe(true);

      inFlight.flush(report({ status: 'completed', batchesTotal: 3, batchesDone: 3 }));
      expect(svc.running()).toBe(false);
    });

    it('does nothing when no run is going', () => {
      svc.stop();

      expect(svc.stopping()).toBe(false);
      ctrl.verify();
    });
  });

  describe('rate limiting (429)', () => {
    const fail429Tick = (): void =>
      ctrl
        .expectOne('https://api.test/api/recommendations/runs/tick')
        .flush(
          { type: 'rate_limited', title: 'Too many requests', status: 429 },
          { status: 429, statusText: 'Too Many Requests' },
        );

    it('does not surface the hard failure or stop the run on repeated 429s', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending' }));

        // More than MAX_TRANSPORT_RETRIES (3) worth of 429s in a row -- a 429
        // must not count against that ceiling at all.
        for (let attempt = 1; attempt <= 5; attempt++) {
          fail429Tick();
          expect(svc.running()).toBe(true);
          expect(svc.failure()).toBeNull();
          jest.advanceTimersByTime(15000);
        }

        // The loop is still polling, not stuck or dead: closing it out with a
        // success proves it, and that the failure signal was never set.
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'completed' }));
        expect(svc.running()).toBe(false);
        expect(svc.failure()).toBeNull();
      } finally {
        jest.useRealTimers();
      }
    });

    it('backs off well past BACKOFF_MS -- a fast retry would spend another token', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending' }));

        fail429Tick();
        jest.advanceTimersByTime(1500); // BACKOFF_MS: not enough for a 429 retry
        ctrl.expectNone('https://api.test/api/recommendations/runs/tick');

        jest.advanceTimersByTime(13500); // completes the 15s rate-limit wait
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'completed' }));
        expect(svc.running()).toBe(false);
      } finally {
        jest.useRealTimers();
      }
    });

    it('resumes normal cadence once a running report arrives after 429s', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending' }));

        fail429Tick();
        jest.advanceTimersByTime(15000);
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));

        // The next tick is issued immediately (background: false) -- the
        // 429 wait did not stick around past the run's own progress.
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'completed' }));
        expect(svc.running()).toBe(false);
        expect(svc.failure()).toBeNull();
      } finally {
        jest.useRealTimers();
      }
    });

    const fail500Tick = (): void =>
      ctrl
        .expectOne('https://api.test/api/recommendations/runs/tick')
        .flush(
          { type: 'server_error', title: 't', status: 500 },
          { status: 500, statusText: 'Server Error' },
        );

    it('does not let earlier 429s shorten the transport-failure budget', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending' }));

        // Two 429s, with no reset in between -- if they shared a counter
        // with genuine transport failures (the bug), this alone would leave
        // only one slot of MAX_TRANSPORT_RETRIES (3) free.
        fail429Tick();
        jest.advanceTimersByTime(15000);
        fail429Tick();
        jest.advanceTimersByTime(15000);

        // Two genuine transport failures now. A shared counter would already
        // be at 2 (from the 429s) + 2 = 4, past the ceiling of 3, and the
        // second one here would already surface the hard failure.
        fail500Tick();
        jest.advanceTimersByTime(1500);
        fail500Tick();
        jest.advanceTimersByTime(1500);
        expect(svc.running()).toBe(true);
        expect(svc.failure()).toBeNull();

        // Two more genuine failures (four in total) spend the full, un-shortened
        // MAX_TRANSPORT_RETRIES (3) budget — matching the spec above, which needs
        // the same four calls starting from a clean slate.
        fail500Tick();
        jest.advanceTimersByTime(1500);
        fail500Tick();

        ctrl.verify(); // the loop gave up rather than polling on
        expect(svc.running()).toBe(false);
        expect(svc.failure()?.kind).toBe('http');
      } finally {
        jest.useRealTimers();
      }
    });

    it('sets rateLimited during a 429 backoff and clears it once a live report resumes', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending' }));

        fail429Tick();
        expect(svc.rateLimited()).toBe(true);

        jest.advanceTimersByTime(15000);
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));
        expect(svc.rateLimited()).toBe(false);

        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'completed', batchesTotal: 2, batchesDone: 2 }));
        expect(svc.rateLimited()).toBe(false);
      } finally {
        jest.useRealTimers();
      }
    });

    it('clears rateLimited in finish() when the run ends while still rate-limited', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending' }));

        for (let attempt = 1; attempt <= 20; attempt++) {
          fail429Tick();
          jest.advanceTimersByTime(15000);
        }
        fail429Tick();

        ctrl.verify(); // the loop gave up rather than polling on
        expect(svc.rateLimited()).toBe(false);
      } finally {
        jest.useRealTimers();
      }
    });

    it('still terminates: enough consecutive 429s surface the hard failure', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending' }));

        // MAX_RATE_LIMIT_RETRIES (20) attempts, then one more tips it over.
        for (let attempt = 1; attempt <= 20; attempt++) {
          fail429Tick();
          jest.advanceTimersByTime(15000);
        }
        fail429Tick();

        ctrl.verify(); // the loop gave up rather than polling on
        expect(svc.running()).toBe(false);
        expect(svc.failure()?.kind).toBe('http');
      } finally {
        jest.useRealTimers();
      }
    });
  });

  describe('lock contention (waitingForLock)', () => {
    it('reports the lockHeld state when the report carries waitingForLock: true', () => {
      svc.start();
      ctrl
        .expectOne('https://api.test/api/recommendations/runs')
        .flush(report({ status: 'pending', waitingForLock: true }));

      expect(svc.etaState()).toBe('lockHeld');

      ctrl
        .expectOne('https://api.test/api/recommendations/runs/tick')
        .flush(report({ status: 'completed' }));
    });

    it('does not report lockHeld when waitingForLock is false', () => {
      svc.start();
      ctrl
        .expectOne('https://api.test/api/recommendations/runs')
        .flush(report({ status: 'pending', waitingForLock: false }));

      expect(svc.etaState()).not.toBe('lockHeld');

      ctrl
        .expectOne('https://api.test/api/recommendations/runs/tick')
        .flush(report({ status: 'completed' }));
    });

    it('does not report lockHeld when waitingForLock is absent, as from an older backend', () => {
      svc.start();
      ctrl
        .expectOne('https://api.test/api/recommendations/runs')
        .flush(report({ status: 'pending' }));

      expect(svc.etaState()).not.toBe('lockHeld');

      ctrl
        .expectOne('https://api.test/api/recommendations/runs/tick')
        .flush(report({ status: 'completed' }));
    });

    it('keeps the rate-limited state ahead of the lock state when both are set', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending', waitingForLock: true }));
        expect(svc.etaState()).toBe('lockHeld');

        // The last known report still carries waitingForLock: true -- only
        // the fresh 429 changes here -- and the rate limit must still win.
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(
            { type: 'rate_limited', title: 'Too many requests', status: 429 },
            { status: 429, statusText: 'Too Many Requests' },
          );
        expect(svc.etaState()).toBe('waiting');

        jest.advanceTimersByTime(15000);
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'completed' }));
      } finally {
        jest.useRealTimers();
      }
    });

    /** The reload case: the run is already stalled when the page loads, so
     *  `resume()` applies the report (freezing the bar) before marking it live.
     *  Marking it live used to start the ticker outright, undoing that freeze (#439). */
    it('leaves the bar frozen when resume() picks up a run already waiting for its lock', fakeAsync(() => {
      jest.useFakeTimers();
      nowMs = 0;
      svc.resume();
      ctrl.expectOne('https://api.test/api/recommendations/runs/current').flush(
        report({
          status: 'running',
          batchesTotal: 4,
          batchesDone: 1,
          elapsedSeconds: 20,
          etaSeconds: 60,
          waitingForLock: true,
        }),
      );
      expect(svc.running()).toBe(true);
      expect(svc.etaState()).toBe('lockHeld');
      const frozen = svc.progress();

      nowMs = 60000; // a whole batch's worth of time, and the bar must not move
      jest.advanceTimersByTime(200);
      expect(svc.progress()).toBeCloseTo(frozen);

      ctrl
        .expectOne('https://api.test/api/recommendations/runs/tick')
        .flush(
          report({ status: 'completed', batchesTotal: 4, batchesDone: 4, elapsedSeconds: 80 }),
        );
      discardPeriodicTasks();
      jest.useRealTimers();
    }));
  });

  describe('background regime', () => {
    it('slows the poll to BACKGROUND_POLL_MS when a worker owns execution', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending', background: true }));

        // No synchronous next tick -- the deferred tick returns instantly, so a
        // tight recursive loop would otherwise hammer the endpoint.
        ctrl.expectNone('https://api.test/api/recommendations/runs/current');

        jest.advanceTimersByTime(3999);
        ctrl.expectNone('https://api.test/api/recommendations/runs/current');

        jest.advanceTimersByTime(1);
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/current')
          .flush(report({ status: 'completed', background: true }));
        expect(svc.running()).toBe(false);
      } finally {
        jest.useRealTimers();
      }
    });

    it('polls the unlimited read endpoint, not the rate-limited write, while the worker owns the run', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'running', background: true }));

        jest.advanceTimersByTime(4000);
        // The tick endpoint would do no work at all here -- it delegates to
        // exactly this read -- and would spend the `ai_recommendations`
        // limiter for nothing.
        ctrl.expectNone('https://api.test/api/recommendations/runs/tick');
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/current')
          .flush(report({ status: 'completed', background: true }));
      } finally {
        jest.useRealTimers();
      }
    });

    it('returns to the tick endpoint as soon as a report says the worker is gone', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'running', background: true }));

        jest.advanceTimersByTime(4000);
        // The worker died between the two reads: this one still comes from
        // `current`, but it carries background: false.
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/current')
          .flush(report({ status: 'running', background: false }));

        // …so the client takes execution back and drives the run itself.
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'completed', background: false }));
        expect(svc.running()).toBe(false);
      } finally {
        jest.useRealTimers();
      }
    });

    it('keeps ticking immediately when the client owns execution (background: false)', () => {
      jest.useFakeTimers();
      try {
        svc.start();
        ctrl
          .expectOne('https://api.test/api/recommendations/runs')
          .flush(report({ status: 'pending', background: false }));

        // Immediate -- no timer advance needed.
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'completed', background: false }));
        expect(svc.running()).toBe(false);
      } finally {
        jest.useRealTimers();
      }
    });
  });

  it('advances the bar from elapsed time and ETA, not batch milestones', fakeAsync(() => {
    jest.useFakeTimers();
    nowMs = 0;
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));
    nowMs = 20000;
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 4,
        batchesDone: 1,
        elapsedSeconds: 20,
        etaSeconds: 60,
      }),
    );

    // 20 seconds elapsed of a predicted 80-second run.
    expect(svc.progress()).toBeCloseTo(0.25);

    // Ten seconds later, the ETA has fallen by the same ten seconds.
    nowMs = 30000;
    jest.advanceTimersByTime(200); // TICK_MS -> recompute
    expect(svc.progress()).toBeCloseTo(0.375);

    // The bar keeps following total time, even though no batch completed.
    nowMs = 45000;
    jest.advanceTimersByTime(200);
    expect(svc.progress()).toBeCloseTo(0.5625);

    drainTrailingTick();
    discardPeriodicTasks();
    jest.useRealTimers();
  }));

  it('re-anchors progress from the fresh server time estimate', fakeAsync(() => {
    jest.useFakeTimers();
    nowMs = 0;
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));
    nowMs = 20000;
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 4,
        batchesDone: 1,
        elapsedSeconds: 20,
        etaSeconds: 60,
      }),
    );
    nowMs = 40000;
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 4,
        batchesDone: 2,
        elapsedSeconds: 40,
        etaSeconds: 40,
      }),
    );
    expect(svc.progress()).toBeCloseTo(0.5);

    drainTrailingTick();
    discardPeriodicTasks();
    jest.useRealTimers();
  }));

  it('shows the server ETA and ticks it down between polls', fakeAsync(() => {
    jest.useFakeTimers();
    nowMs = 0;
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));

    // Before the server sends an estimate: a blank, not a guess.
    expect(svc.etaSeconds()).toBeNull();
    expect(svc.etaState()).toBe('starting');

    nowMs = 20000;
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 4,
        batchesDone: 1,
        elapsedSeconds: 20,
        etaSeconds: 60,
      }),
    );
    expect(svc.etaSeconds()).toBe(60);
    expect(svc.etaState()).toBe('eta');

    // Ten seconds pass with no new poll: the client counts the estimate down
    // from the value the server last sent.
    nowMs = 30000;
    jest.advanceTimersByTime(200);
    expect(svc.etaSeconds()).toBe(50);

    drainTrailingTick();
    discardPeriodicTasks();
    jest.useRealTimers();
  }));

  it('starts the time model only when the first batch has started', fakeAsync(() => {
    jest.useFakeTimers();
    nowMs = 0;
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));

    nowMs = 10000;
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 6,
        batchesDone: 0,
        elapsedSeconds: 10,
        etaSeconds: 90,
        firstBatchStarted: false,
      } as unknown as Partial<RecommendationRunReport>),
    );

    expect(svc.etaSeconds()).toBeNull();
    expect(svc.progress()).toBe(0);

    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 6,
        batchesDone: 0,
        elapsedSeconds: 10,
        etaSeconds: 90,
        firstBatchStarted: true,
      } as unknown as Partial<RecommendationRunReport>),
    );

    expect(svc.etaSeconds()).toBe(90);
    expect(svc.progress()).toBeCloseTo(0.1);

    nowMs = 20000;
    jest.advanceTimersByTime(200);
    expect(svc.etaSeconds()).toBe(80);
    expect(svc.progress()).toBeCloseTo(0.2);

    drainTrailingTick();
    discardPeriodicTasks();
    jest.useRealTimers();
  }));

  it('keeps the starting label until the server sends an ETA', fakeAsync(() => {
    jest.useFakeTimers();
    nowMs = 0;
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));

    nowMs = 20000;
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 1, etaSeconds: null }));

    // A run with no history behind it: the server sends no estimate, so the
    // label stays "starting" rather than showing a fabricated number.
    expect(svc.etaSeconds()).toBeNull();
    expect(svc.etaState()).toBe('starting');

    drainTrailingTick();
    discardPeriodicTasks();
    jest.useRealTimers();
  }));

  it('freezes the bar and reports the waiting state during a 429 backoff', fakeAsync(() => {
    jest.useFakeTimers();
    nowMs = 0;
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));
    nowMs = 20000;
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 4,
        batchesDone: 1,
        elapsedSeconds: 20,
        etaSeconds: 60,
      }),
    );
    nowMs = 30000;
    jest.advanceTimersByTime(200);
    const beforeLimit = svc.progress();

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .error(new ProgressEvent('error'), { status: 429, statusText: 'Too Many Requests' });
    expect(svc.etaState()).toBe('waiting');

    nowMs = 90000; // time marches on, but the bar must not move
    jest.advanceTimersByTime(200);
    expect(svc.progress()).toBeCloseTo(beforeLimit);

    jest.advanceTimersByTime(15000);
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 4, batchesDone: 4, elapsedSeconds: 100 }));
    discardPeriodicTasks();
    jest.useRealTimers();
  }));

  it('freezes the bar and reports the lockHeld state while a lock is held, and resumes when it clears', fakeAsync(() => {
    jest.useFakeTimers();
    nowMs = 0;
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));
    nowMs = 20000;
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 1, elapsedSeconds: 20 }));
    nowMs = 30000;
    jest.advanceTimersByTime(200);
    const beforeLock = svc.progress();

    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 4,
        batchesDone: 1,
        elapsedSeconds: 20,
        etaSeconds: 60,
        waitingForLock: true,
      }),
    );
    expect(svc.etaState()).toBe('lockHeld');
    // The incoming report is itself a signal write, so it invalidates the
    // computed regardless of the ticker; read it once here, at the same instant
    // the lock report arrived, so it settles to the frozen value, not a later one.
    expect(svc.progress()).toBeCloseTo(beforeLock);

    nowMs = 90000; // time marches on, but the bar must not move
    jest.advanceTimersByTime(200);
    expect(svc.progress()).toBeCloseTo(beforeLock);

    // The lock clears: the next report carries no waitingForLock, and the
    // bar resumes creeping from where it was frozen.
    nowMs = 95000;
    ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(
      report({
        status: 'running',
        batchesTotal: 4,
        batchesDone: 1,
        elapsedSeconds: 40,
        etaSeconds: 40,
      }),
    );
    expect(svc.etaState()).not.toBe('lockHeld');
    nowMs = 100000;
    jest.advanceTimersByTime(200);
    expect(svc.progress()).toBeGreaterThan(beforeLock);

    drainTrailingTick();
    discardPeriodicTasks();
    jest.useRealTimers();
  }));

  it('stops the ticker when the run ends', fakeAsync(() => {
    jest.useFakeTimers();
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 3, batchesDone: 3, elapsedSeconds: 30 }));
    expect(svc.running()).toBe(false);
    // No periodic task should remain; if the ticker leaked, fakeAsync would throw here.
    jest.useRealTimers();
  }));

  it('reports zero progress when the total is null or zero', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending', batchesTotal: null }));
    expect(svc.progress()).toBe(0);

    // 'pending' keeps the loop going (unlike the now-impossible 'busy'),
    // so this tick needs a response to leave no outstanding request behind.
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 1, batchesDone: 1 }));
  });

  /** The pill's exact shape, asserted in one place so the three tests below
   *  read as intent rather than as a repeated object literal. */
  const PILL = {
    content: ForYouProgressComponent,
    durationMs: null,
    width: 'fixed',
    tone: 'translucent',
  };

  it('raises the persistent pill the moment a run starts, before any report arrives', () => {
    svc.start();

    expect(toast.show).toHaveBeenCalledWith(PILL);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'completed', batchesTotal: 1, batchesDone: 1 }));
  });

  it('raises the pill for a run resumed from an earlier session', () => {
    svc.resume();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/current')
      .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));

    expect(toast.show).toHaveBeenCalledWith(PILL);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 2, batchesDone: 2 }));
  });

  it('offers the pill again only while a run is live and the pill has been closed', () => {
    expect(svc.pillHidden()).toBe(false); // no run at all

    svc.start();
    expect(svc.pillHidden()).toBe(false); // the pill is up

    toast.dismiss(); // the user pressed ✕
    expect(svc.pillHidden()).toBe(true);

    svc.showRunPill();
    expect(svc.pillHidden()).toBe(false);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'completed', batchesTotal: 1, batchesDone: 1 }));

    expect(svc.pillHidden()).toBe(false); // the run is over; nothing to restore
  });

  it('takes the pill down on a cancelled run, which raises no toast of its own', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'running', batchesTotal: 2, batchesDone: 1 }));

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'cancelled', batchesTotal: 2, batchesDone: 1 }));

    expect(svc.running()).toBe(false);
    expect(toast.dismiss).toHaveBeenCalled();
    expect(toast.show).toHaveBeenCalledTimes(1); // the pill, and nothing after it
  });

  describe('the run readout follows the layout', () => {
    /** Opens a live run and leaves its first tick outstanding, so the tests
     *  below can cross the breakpoint while the run is still going. */
    const openLiveRun = (): void => {
      svc.start();
      ctrl
        .expectOne('https://api.test/api/recommendations/runs')
        .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 1 }));
      expect(svc.running()).toBe(true);
    };

    it('raises no pill on a wide layout, where the header carries the run', () => {
      isNarrow.set(false);
      openLiveRun();

      expect(toast.show).not.toHaveBeenCalled();

      drainTrailingTick();
    });

    it('never offers the pill back on a wide layout, where nothing can hide it', () => {
      isNarrow.set(false);
      openLiveRun();

      // The eye button hangs off this; on a surface with no ✕ it must stay away.
      expect(svc.pillHidden()).toBe(false);

      drainTrailingTick();
    });

    it('takes the pill down when the viewport widens mid-run, and brings it back', () => {
      openLiveRun();
      expect(toast.show).toHaveBeenCalledWith(expect.objectContaining(PILL));
      toast.show.mockClear();

      isNarrow.set(false);
      TestBed.tick();
      expect(toast.dismiss).toHaveBeenCalled();
      expect(toast.show).not.toHaveBeenCalled();

      isNarrow.set(true);
      TestBed.tick();
      expect(toast.show).toHaveBeenCalledWith(expect.objectContaining(PILL));

      drainTrailingTick();
    });

    it('leaves a ✕ pressed: widening and narrowing again is not a way to undo it', () => {
      openLiveRun();
      toast.dismiss(); // the user pressed ✕
      expect(svc.pillHidden()).toBe(true);
      toast.show.mockClear();

      // Nothing crossed, so the effect has no business running at all.
      TestBed.tick();
      expect(toast.show).not.toHaveBeenCalled();
      expect(svc.pillHidden()).toBe(true);

      drainTrailingTick();
    });
  });
});
