import { buildVersion } from '../../environments/version';
import {
  ClientErrorItem,
  buildVersionTag,
  reportBootError,
  resolveClientErrorsUrl,
  sendClientError,
} from './client-error-beacon';

describe('client-error-beacon', () => {
  let fetchMock: jest.Mock;

  const item: ClientErrorItem = {
    message: 'boom',
    stack: null,
    kind: 'Error',
    url: null,
    route: null,
    buildVersion: null,
    userAgent: null,
    at: null,
  };

  beforeEach(() => {
    fetchMock = jest.fn().mockResolvedValue({ ok: true });
    (globalThis as unknown as { fetch: jest.Mock }).fetch = fetchMock;
  });

  afterEach(() => {
    jest.restoreAllMocks();
    document.querySelectorAll('base').forEach((base) => base.remove());
    delete (globalThis as unknown as { Request?: unknown }).Request;
  });

  describe('buildVersionTag', () => {
    it('joins version, commit and builtAt into one string', () => {
      const expected = `${buildVersion.version}+${buildVersion.commit}@${buildVersion.builtAt}`;
      expect(buildVersionTag()).toBe(expected);
    });
  });

  describe('resolveClientErrorsUrl', () => {
    it('resolves relative to the default same-origin base', () => {
      expect(new URL(resolveClientErrorsUrl()).pathname).toBe('/api/client-errors');
    });

    it('resolves under the Strato <base href="/reader/">', () => {
      const base = document.createElement('base');
      base.href = '/reader/';
      document.head.appendChild(base);

      expect(new URL(resolveClientErrorsUrl()).pathname).toBe('/reader/api/client-errors');
    });
  });

  describe('sendClientError', () => {
    it('POSTs a batch of one to the resolved url with keepalive', () => {
      sendClientError(item);

      expect(fetchMock).toHaveBeenCalledTimes(1);
      const [url, init] = fetchMock.mock.calls[0];
      expect(new URL(url).pathname).toBe('/api/client-errors');
      expect(init.keepalive).toBe(true);
      expect(init.headers['Content-Type']).toBe('application/json');
      expect(JSON.parse(init.body)).toEqual({ errors: [item] });
    });

    it('posts to an explicit url when one is given, overriding baseURI resolution', () => {
      sendClientError(item, { url: '/reader/api/client-errors' });

      expect(fetchMock.mock.calls[0][0]).toBe('/reader/api/client-errors');
    });

    it('attaches the bearer token when given', () => {
      sendClientError(item, { bearerToken: 'jwt-xyz' });

      expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer jwt-xyz');
    });

    it('omits the Authorization header when no token is given', () => {
      sendClientError(item);

      expect(fetchMock.mock.calls[0][1].headers.Authorization).toBeUndefined();
    });

    it('never throws even when fetch rejects', () => {
      fetchMock.mockRejectedValue(new Error('offline'));

      expect(() => sendClientError(item)).not.toThrow();
    });

    it('never throws even when fetch throws synchronously', () => {
      fetchMock.mockImplementation(() => {
        throw new Error('network down');
      });

      expect(() => sendClientError(item)).not.toThrow();
    });

    it('falls back to sendBeacon when keepalive is not supported', () => {
      (globalThis as unknown as { Request: unknown }).Request = function StubRequest(): void {
        // A fetch-capable browser old enough to lack keepalive on Request.
      };
      const sendBeaconMock = jest.fn();
      Object.defineProperty(navigator, 'sendBeacon', {
        value: sendBeaconMock,
        configurable: true,
      });

      sendClientError(item);

      expect(fetchMock).not.toHaveBeenCalled();
      expect(sendBeaconMock).toHaveBeenCalledTimes(1);
      const [url, blob] = sendBeaconMock.mock.calls[0];
      expect(new URL(url).pathname).toBe('/api/client-errors');
      expect(blob).toBeInstanceOf(Blob);
    });

    it('never throws when neither fetch keepalive nor sendBeacon is available', () => {
      (globalThis as unknown as { Request: unknown }).Request = function StubRequest(): void {
        // Same old-browser stand-in as above, with no sendBeacon either.
      };
      Object.defineProperty(navigator, 'sendBeacon', { value: undefined, configurable: true });

      expect(() => sendClientError(item)).not.toThrow();
      expect(fetchMock).not.toHaveBeenCalled();
    });
  });

  describe('reportBootError', () => {
    it('builds an item from the error and sends it to the resolved url', () => {
      reportBootError(new TypeError('boot broke'));

      expect(fetchMock).toHaveBeenCalledTimes(1);
      const [url, init] = fetchMock.mock.calls[0];
      expect(new URL(url).pathname).toBe('/api/client-errors');
      const body = JSON.parse(init.body);
      expect(body.errors[0]).toMatchObject({
        message: 'boot broke',
        kind: 'TypeError',
        route: null,
      });
      expect(typeof body.errors[0].buildVersion).toBe('string');
    });

    it('never throws even when the error is not an Error instance', () => {
      expect(() => reportBootError('a plain string blew up')).not.toThrow();
    });
  });
});
