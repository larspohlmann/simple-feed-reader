import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { ClientErrorReporter } from './client-error-reporter';
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

  it('does not dedupe errors with different messages', () => {
    const reporter = setup();
    reporter.report(new Error('first'));
    reporter.report(new Error('second'));

    expect(fetchMock).toHaveBeenCalledTimes(2);
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
});
