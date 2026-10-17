import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';
import { ReaderContent, ReaderFailure } from '../../models';
import { ReaderLoad } from './reader-load';

const FAILURE: ReaderFailure = {
  status: 'failed',
  url: 'https://x/1',
  reason: 'fetch',
  detail: 'HTTP 503',
  originalHero: null,
};

describe('ReaderLoad', () => {
  let load: ReaderLoad;

  beforeEach(() => {
    load = TestBed.runInInjectionContext(() => new ReaderLoad());
  });

  it('is loading until the source answers', () => {
    const pending = new Subject<ReaderContent>();
    load.run(pending);
    expect(load.loading()).toBe(true);
    expect(load.payload()).toBeNull();
  });

  it('reports a failure payload with its own detail', () => {
    const settled = jest.fn();
    load.run(of(FAILURE), settled);
    expect(load.failed()).toBe(true);
    expect(load.failure()).toBe(FAILURE);
    expect(load.payload()).toBe(FAILURE);
    expect(load.errorDetail()).toBe('HTTP 503');
    expect(settled).toHaveBeenCalledWith('failed');
  });

  it('reports a transport error without a payload', () => {
    const settled = jest.fn();
    load.run(throwError(() => new HttpErrorResponse({ status: 0, url: 'https://x' })), settled);
    expect(load.failed()).toBe(true);
    expect(load.failure()).toBeNull();
    expect(load.errorDetail()).toContain('https://x');
    expect(settled).toHaveBeenCalledWith('failed');
  });

  it('drops the previous load when a new one starts', () => {
    const first = new Subject<ReaderContent>();
    load.run(first);
    load.run(new Subject<ReaderContent>());
    expect(first.observed).toBe(false);
  });

  it('returns to idle', () => {
    load.run(of(FAILURE));
    load.idle();
    expect(load.failed()).toBe(false);
    expect(load.loading()).toBe(false);
  });
});
