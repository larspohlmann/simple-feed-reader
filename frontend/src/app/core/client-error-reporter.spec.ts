import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import {
  ClientErrorReporter,
  DEDUPE_WINDOW_MS,
  MAX_REPORTS_PER_WINDOW,
  RATE_WINDOW_MS,
} from './client-error-reporter';
import { TokenStore } from './token.store';

describe('ClientErrorReporter', () => {
  let fetchMock: jest.Mock;

  const setup = (token: string | null = null) => {
    TestBed.configureTestingModule({
      providers: [
        { provide: API_BASE_URL, useValue: '' },
        { provide: Router, useValue: { url: '/reader' } },
        { provide: TokenStore, useValue: { token: () => token } },
      ],
    });
    return TestBed.inject(ClientErrorReporter);
  };

  beforeEach(() => {
    fetchMock = jest.fn().mockResolvedValue({ ok: true });
    (globalThis as unknown as { fetch: jest.Mock }).fetch = fetchMock;
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
  });

  afterEach(() => jest.restoreAllMocks());

  it('POSTs a tagged batch of one to /api/client-errors', () => {
    setup().report(new TypeError('boom'));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/client-errors');
    expect(init.keepalive).toBe(true);
    const body = JSON.parse(init.body);
    expect(body.errors).toHaveLength(1);
    expect(body.errors[0]).toMatchObject({ message: 'boom', kind: 'TypeError', route: '/reader' });
    expect(typeof body.errors[0].buildVersion).toBe('string');
  });

  it('attaches the bearer token when the session has one', () => {
    setup('jwt-abc').report(new Error('boom'));

    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer jwt-abc');
  });

  it('dedupes identical errors inside the throttle window', () => {
    const reporter = setup();
    reporter.report(new Error('same'));
    reporter.report(new Error('same'));

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('never throws back into the caller when delivery fails', () => {
    fetchMock.mockImplementation(() => {
      throw new Error('network down');
    });

    expect(() => setup().report(new Error('boom'))).not.toThrow();
  });

  it('collapses the same HttpErrorResponse reported twice, as the interceptor and the global handler both would', () => {
    const reporter = setup();
    const httpError = { name: 'HttpErrorResponse', status: 0, url: '/api/entries' };

    reporter.report(httpError);
    reporter.report(httpError);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const body = JSON.parse(fetchMock.mock.calls[0][1].body);
    expect(body.errors[0]).toMatchObject({ message: 'HTTP 0 /api/entries', kind: 'HttpError' });
  });

  it('does not dedupe errors with different messages', () => {
    const reporter = setup();
    reporter.report(new Error('first'));
    reporter.report(new Error('second'));

    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('keeps an explicitly-supplied kind even when the reason is an Error', () => {
    setup().report(new Error('HTTP 401 GET /api/entries'), 'HttpError');

    const body = JSON.parse(fetchMock.mock.calls[0][1].body);
    expect(body.errors[0].kind).toBe('HttpError');
  });

  it('falls back to the Error name when no kind is supplied', () => {
    setup().report(new TypeError('y'));

    const body = JSON.parse(fetchMock.mock.calls[0][1].body);
    expect(body.errors[0].kind).toBe('TypeError');
  });

  it('posts under the API_BASE_URL prefix, so a Strato deploy hits /reader/api/client-errors', () => {
    TestBed.configureTestingModule({
      providers: [
        { provide: API_BASE_URL, useValue: '/reader' },
        { provide: Router, useValue: { url: '/reader/inbox' } },
        { provide: TokenStore, useValue: { token: () => null } },
      ],
    });

    TestBed.inject(ClientErrorReporter).report(new Error('boom'));

    expect(fetchMock.mock.calls[0][0]).toBe('/reader/api/client-errors');
  });

  describe('dedupe map pruning', () => {
    afterEach(() => jest.useRealTimers());

    it('forgets a signature once it ages out of the dedupe window, instead of growing forever', () => {
      jest.useFakeTimers({ now: new Date('2026-01-01T00:00:00Z') });
      const reporter = setup();
      const internals = reporter as unknown as { lastSentAtBySignature: Map<string, number> };

      reporter.report(new Error('first'));
      expect(internals.lastSentAtBySignature.size).toBe(1);

      jest.advanceTimersByTime(DEDUPE_WINDOW_MS + 1);
      reporter.report(new Error('second'));

      // 'first' aged out and was swept; only 'second' remains, so the map
      // does not keep one entry per distinct message forever.
      expect(internals.lastSentAtBySignature.size).toBe(1);
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });
  });

  describe('flood cap', () => {
    afterEach(() => jest.useRealTimers());

    it('sends up to MAX_REPORTS_PER_WINDOW distinct errors, suppresses the next, then resumes after RATE_WINDOW_MS', () => {
      jest.useFakeTimers({ now: new Date('2026-01-01T00:00:00Z') });
      const reporter = setup();

      for (let index = 0; index < MAX_REPORTS_PER_WINDOW; index += 1) {
        reporter.report(new Error(`distinct-${index}`));
      }
      expect(fetchMock).toHaveBeenCalledTimes(MAX_REPORTS_PER_WINDOW);

      reporter.report(new Error('one-too-many'));
      expect(fetchMock).toHaveBeenCalledTimes(MAX_REPORTS_PER_WINDOW);

      jest.advanceTimersByTime(RATE_WINDOW_MS + 1);
      reporter.report(new Error('after-the-window'));

      expect(fetchMock).toHaveBeenCalledTimes(MAX_REPORTS_PER_WINDOW + 1);
    });
  });
});
