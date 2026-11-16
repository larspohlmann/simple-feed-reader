import { Subject, of, throwError } from 'rxjs';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';
import { StaleGatedLoad } from './stale-gated-load';

describe('StaleGatedLoad', () => {
  beforeEach(() => jest.useFakeTimers({ now: new Date('2026-11-16T12:00:00Z') }));
  afterEach(() => jest.useRealTimers());

  it('is stale before it ever loaded', () => {
    expect(new StaleGatedLoad().isStale()).toBe(true);
  });

  it('adopts the answer and stays fresh for the window', () => {
    const loads = new StaleGatedLoad();
    const adopt = jest.fn();

    loads.run(of('answer'), adopt);

    expect(adopt).toHaveBeenCalledWith('answer');
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS - 1);
    expect(loads.isStale()).toBe(false);
    jest.advanceTimersByTime(1);
    expect(loads.isStale()).toBe(true);
  });

  it('is not stale while a request is out, however old', () => {
    const loads = new StaleGatedLoad();
    loads.run(new Subject<string>(), () => undefined);

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS * 2);

    expect(loads.isStale()).toBe(false);
  });

  it('is stale again past the window once a request failed', () => {
    const loads = new StaleGatedLoad();
    loads.run(
      throwError(() => new Error('down')),
      () => undefined,
    );

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

    expect(loads.isStale()).toBe(true);
  });

  it('drops the answer of a request a newer one superseded', () => {
    const loads = new StaleGatedLoad();
    const first = new Subject<string>();
    const adopt = jest.fn();
    loads.run(first, adopt);
    loads.run(of('second'), adopt);

    first.next('first');

    expect(adopt).toHaveBeenCalledTimes(1);
    expect(adopt).toHaveBeenCalledWith('second');
  });

  it('forget() drops the request out and makes the next tick load', () => {
    const loads = new StaleGatedLoad();
    const out = new Subject<string>();
    const adopt = jest.fn();
    loads.run(out, adopt);

    loads.forget();
    out.next('late');

    expect(adopt).not.toHaveBeenCalled();
    expect(loads.isStale()).toBe(true);
  });
});
