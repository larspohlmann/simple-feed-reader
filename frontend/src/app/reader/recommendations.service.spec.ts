// src/app/reader/recommendations.service.spec.ts
import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { API_BASE_URL } from '../core/api';
import { ToastService } from '../shared/toast/toast.service';
import { RecommendationsService } from './recommendations.service';
import { RecommendationRunReport } from './models';

const report = (over: Partial<RecommendationRunReport>): RecommendationRunReport => ({
  status: 'pending',
  batchesTotal: null,
  batchesDone: 0,
  error: null,
  background: false,
  ...over,
});

describe('RecommendationsService', () => {
  let svc: RecommendationsService;
  let ctrl: HttpTestingController;
  let toast: { show: jest.Mock };
  let navigate: jest.Mock;

  beforeEach(() => {
    toast = { show: jest.fn() };
    navigate = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: ToastService, useValue: toast },
        { provide: TranslocoService, useValue: { translate: (k: string) => k } },
        { provide: Router, useValue: { navigate } },
      ],
    });
    svc = TestBed.inject(RecommendationsService);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it('starts a run, ticks until completed, and shows the ready toast', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'pending' }));
    expect(svc.running()).toBe(true);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'running', batchesTotal: 3, batchesDone: 1 }));
    expect(svc.running()).toBe(true);
    expect(svc.progress()).toBeCloseTo(1 / 3);

    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 3, batchesDone: 3 }));

    expect(svc.running()).toBe(false);
    expect(svc.completedStamp()).toBe(1);
    expect(toast.show).toHaveBeenCalledTimes(1);
    expect(toast.show).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'reader.forYouReady' }),
    );
  });

  it('the ready toast action navigates to the for-you view', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'completed', batchesTotal: 1, batchesDone: 1 }));

    const call = toast.show.mock.calls[0][0] as { action?: () => void };
    call.action?.();

    expect(navigate).toHaveBeenCalledWith(['/'], {
      queryParams: { view: 'for-you', tag: null, subscription: null, entry: null },
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
    expect(toast.show).toHaveBeenCalledTimes(1);
    expect(toast.show).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'reader.forYouFailed' }),
    );
  });

  it('backs off on busy then retries', fakeAsync(() => {
    svc.start();
    ctrl.expectOne('https://api.test/api/recommendations/runs').flush(report({ status: 'busy' }));
    expect(svc.running()).toBe(true);

    tick(1500);
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/tick')
      .flush(report({ status: 'completed', batchesTotal: 1, batchesDone: 1 }));

    expect(svc.running()).toBe(false);
    expect(svc.failure()).toBeNull();
  }));

  it('records a busy failure once the retry budget is spent, without a toast', fakeAsync(() => {
    svc.start();
    const busy = report({ status: 'busy' });

    ctrl.expectOne('https://api.test/api/recommendations/runs').flush(busy);
    for (let attempt = 1; attempt <= 5; attempt++) {
      tick(1500);
      ctrl.expectOne('https://api.test/api/recommendations/runs/tick').flush(busy);
    }

    ctrl.verify(); // the loop gave up rather than polling on
    expect(svc.running()).toBe(false);
    expect(svc.failure()).toEqual({ kind: 'busy' });
    expect(toast.show).not.toHaveBeenCalled();
  }));

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

  it('resume does nothing for an already-completed run (no toast)', () => {
    svc.resume();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs/current')
      .flush(report({ status: 'completed', batchesTotal: 2, batchesDone: 2 }));

    ctrl.verify(); // no tick request
    expect(svc.running()).toBe(false);
    expect(toast.show).not.toHaveBeenCalled();
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
        ctrl.expectNone('https://api.test/api/recommendations/runs/tick');

        jest.advanceTimersByTime(3999);
        ctrl.expectNone('https://api.test/api/recommendations/runs/tick');

        jest.advanceTimersByTime(1);
        ctrl
          .expectOne('https://api.test/api/recommendations/runs/tick')
          .flush(report({ status: 'completed', background: true }));
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

  it('reports zero progress when the total is null or zero', () => {
    svc.start();
    ctrl
      .expectOne('https://api.test/api/recommendations/runs')
      .flush(report({ status: 'busy', batchesTotal: null }));
    expect(svc.progress()).toBe(0);
  });
});
