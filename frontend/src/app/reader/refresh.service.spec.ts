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
  }));

  it('stops and records a problem on error (e.g. 429)', () => {
    svc.run();
    ctrl
      .expectOne('https://api.test/api/refresh')
      .flush(
        { type: 'rate_limited', title: 't', status: 429 },
        { status: 429, statusText: 'Too Many Requests' },
      );
    expect(svc.running()).toBe(false);
    expect(svc.error()?.status).toBe(429);
  });
});
