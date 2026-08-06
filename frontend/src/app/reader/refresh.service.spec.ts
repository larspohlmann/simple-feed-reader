import { effect } from '@angular/core';
import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { RefreshService } from './refresh.service';

const report = (over: Partial<Record<string, unknown>>) => ({
  status: 'partial',
  total: 10,
  fetched: 0,
  notModified: 0,
  failed: 0,
  skippedForBudget: 0,
  remaining: 5,
  pruned: 0,
  ...over,
});

describe('RefreshService', () => {
  let svc: RefreshService;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    svc = TestBed.inject(RefreshService);
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loops partial then completes and calls onDone', () => {
    const done = jest.fn();
    svc.run(done);
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'partial', remaining: 5 }));
    expect(svc.running()).toBe(true);
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'completed', remaining: 0, fetched: 10 }));
    expect(svc.running()).toBe(false);
    expect(svc.progress()).toBe(1);
    expect(done).toHaveBeenCalledTimes(1);
  });

  // A backend that reports the same `remaining` twice is making no progress.
  // Polling on regardless is what sent 89 requests to one rationed Reddit feed
  // in production, because a throttled feed writes no fetch time and so never
  // left the due set (#302). The loop must stop rather than hammer.
  it('stops when remaining does not decrease', () => {
    const done = jest.fn();
    svc.run(done);

    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'partial', remaining: 1 }));
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'partial', remaining: 1 }));

    ctrl.verify();
    expect(svc.running()).toBe(false);
    expect(svc.failure()).toEqual({ kind: 'stalled' });
    expect(done).toHaveBeenCalledTimes(1);
  });

  it('keeps looping while remaining actually falls', () => {
    svc.run();

    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'partial', remaining: 3 }));
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'partial', remaining: 2 }));
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'completed', remaining: 0 }));

    expect(svc.running()).toBe(false);
    expect(svc.failure()).toBeNull();
  });

  it('emits a slice tick for every partial report, not just at the end', () => {
    const ticks: number[] = [];
    TestBed.runInInjectionContext(() => {
      effect(() => ticks.push(svc.slice()));
    });
    TestBed.tick(); // flush the effect's initial run — captures the starting 0

    svc.run();

    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'partial', remaining: 2, fetched: 2 }));
    TestBed.tick();

    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'completed', remaining: 0, fetched: 4 }));
    TestBed.tick();

    // 0 on subscribe, then one increment per landed report.
    expect(ticks).toEqual([0, 1, 2]);
  });

  it('scopes every request to the given feed id across the poll loop', () => {
    svc.run(undefined, { feedId: 42 });
    const first = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    expect(first.request.params.get('feedId')).toBe('42');
    first.flush(report({ status: 'partial', remaining: 1 }));
    // The scope must survive the re-poll, not just the first call.
    const second = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    expect(second.request.params.get('feedId')).toBe('42');
    second.flush(report({ status: 'completed', remaining: 0 }));
    expect(svc.running()).toBe(false);
  });

  it('scopes every request to the given tag id across the poll loop', () => {
    svc.run(undefined, { tagId: 3 });
    const first = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    expect(first.request.params.get('tag')).toBe('3');
    expect(first.request.params.get('feedId')).toBeNull();
    first.flush(report({ status: 'partial', remaining: 1 }));
    const second = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    expect(second.request.params.get('tag')).toBe('3');
    second.flush(report({ status: 'completed', remaining: 0 }));
    expect(svc.running()).toBe(false);
  });

  it('backs off on busy then retries', fakeAsync(() => {
    svc.run();
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'busy', total: 0, remaining: 0 }));
    expect(svc.running()).toBe(true);
    tick(1500);
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'completed', remaining: 0 }));
    expect(svc.running()).toBe(false);
    expect(svc.failure()).toBeNull();
  }));

  // Retrying longer is not the fix: a CLI sweep holds the lock for its whole
  // budget. The user has to be told, or the spinner just stops (#119).
  it('records a busy failure once the retry budget is spent', fakeAsync(() => {
    const done = jest.fn();
    svc.run(done);
    const busy = report({ status: 'busy', total: 0, remaining: 0 });

    // The first call plus MAX_BUSY_RETRIES more, all answered busy.
    for (let attempt = 0; attempt <= 5; attempt++) {
      ctrl.expectOne('https://api.test/api/refresh').flush(busy);
      tick(1500);
    }

    ctrl.verify(); // the loop gave up rather than polling on
    expect(svc.running()).toBe(false);
    expect(svc.failure()).toEqual({ kind: 'busy' });
    expect(done).toHaveBeenCalledTimes(1);
  }));

  // An aborted sweep left feeds unfetched and still due. Sharing the
  // `completed` branch made it present as a clean run (#119).
  it('records an aborted sweep rather than reporting it as finished', () => {
    svc.run();
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'aborted', total: 10, remaining: 7, fetched: 3 }));
    expect(svc.running()).toBe(false);
    expect(svc.failure()).toEqual({ kind: 'aborted' });
  });

  it('reports a completed sweep as no failure at all', () => {
    svc.run();
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'completed', remaining: 0, fetched: 10 }));
    expect(svc.failure()).toBeNull();
  });

  it('stops and records the problem on error (e.g. 429)', () => {
    svc.run();
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(
        { type: 'rate_limited', title: 't', status: 429 },
        { status: 429, statusText: 'Too Many Requests' },
      );
    expect(svc.running()).toBe(false);
    const failure = svc.failure();
    expect(failure?.kind).toBe('http');
    expect(failure).toMatchObject({ problem: { status: 429, type: 'rate_limited' } });
  });

  it('clears a previous failure when a new run starts', () => {
    svc.run();
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(report({ status: 'aborted', remaining: 4 }));
    expect(svc.failure()).not.toBeNull();

    svc.run();
    expect(svc.failure()).toBeNull();
    ctrl.expectOne('https://api.test/api/refresh').flush(report({ status: 'completed' }));
  });
});
