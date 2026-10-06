import { HttpErrorResponse } from '@angular/common/http';
import { Observable, defer, throwError, of } from 'rxjs';
import { retryTransientGatewayErrors } from './retry-transient-gateway-errors';

/** A source that fails with each status in turn, then succeeds — counting its subscriptions. */
function failingWith(statuses: number[]): { source: Observable<string>; attempts: () => number } {
  let attempts = 0;
  const source = defer(() => {
    const status = statuses[attempts++];
    return status === undefined
      ? of('page')
      : throwError(() => new HttpErrorResponse({ status, statusText: 'err' }));
  });
  return { source, attempts: () => attempts };
}

describe('retryTransientGatewayErrors', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('retries a 504 after two seconds and emits the retried response', () => {
    const { source, attempts } = failingWith([504]);
    const values: string[] = [];
    source.pipe(retryTransientGatewayErrors()).subscribe((value) => values.push(value));

    jest.advanceTimersByTime(1999);
    expect(attempts()).toBe(1);
    jest.advanceTimersByTime(1);
    expect(attempts()).toBe(2);
    expect(values).toEqual(['page']);
  });

  it('backs off 2, 5 and 10 seconds across three gateway failures', () => {
    const { source, attempts } = failingWith([502, 503, 504]);
    const values: string[] = [];
    source.pipe(retryTransientGatewayErrors()).subscribe((value) => values.push(value));

    jest.advanceTimersByTime(2000);
    expect(attempts()).toBe(2);
    jest.advanceTimersByTime(4999);
    expect(attempts()).toBe(2);
    jest.advanceTimersByTime(1);
    expect(attempts()).toBe(3);
    jest.advanceTimersByTime(9999);
    expect(attempts()).toBe(3);
    jest.advanceTimersByTime(1);
    expect(attempts()).toBe(4);
    expect(values).toEqual(['page']);
  });

  it('surfaces the fourth gateway failure once the retries are spent', () => {
    const { source, attempts } = failingWith([504, 504, 504, 504]);
    const errors: HttpErrorResponse[] = [];
    source.pipe(retryTransientGatewayErrors()).subscribe({ error: (error) => errors.push(error) });

    jest.advanceTimersByTime(17000);
    expect(attempts()).toBe(4);
    expect(errors.map((error) => error.status)).toEqual([504]);
  });

  it.each([500, 404, 401, 0])('surfaces a %s at once without retrying', (status) => {
    const { source, attempts } = failingWith([status]);
    const errors: HttpErrorResponse[] = [];
    source.pipe(retryTransientGatewayErrors()).subscribe({ error: (error) => errors.push(error) });

    expect(errors.map((error) => error.status)).toEqual([status]);
    jest.advanceTimersByTime(20000);
    expect(attempts()).toBe(1);
  });

  it('never retries an error that is not an HTTP response', () => {
    let attempts = 0;
    const errors: unknown[] = [];
    defer(() => {
      attempts++;
      return throwError(() => new Error('boom'));
    })
      .pipe(retryTransientGatewayErrors())
      .subscribe({ error: (error) => errors.push(error) });

    jest.advanceTimersByTime(20000);
    expect(attempts).toBe(1);
    expect(errors).toHaveLength(1);
  });

  it('cancels a pending retry when the subscriber leaves', () => {
    const { source, attempts } = failingWith([504]);
    const subscription = source.pipe(retryTransientGatewayErrors()).subscribe();

    subscription.unsubscribe();
    jest.advanceTimersByTime(20000);
    expect(attempts()).toBe(1);
  });
});
