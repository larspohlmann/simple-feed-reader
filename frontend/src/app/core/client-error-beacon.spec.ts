import { buildVersion } from '../../environments/version';
import {
  ClientErrorItem,
  buildVersionTag,
  describeError,
  reportBootError,
  resolveClientErrorsUrl,
  sendClientError,
  toClientErrorItem,
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

  describe('describeError', () => {
    it('keeps an Error message, stack, and name as kind', () => {
      const error = new TypeError('boom');

      expect(describeError(error)).toEqual({
        message: 'boom',
        stack: error.stack,
        kind: 'TypeError',
      });
    });

    it('keeps both message and name from a DOMException-shaped object', () => {
      const error = { name: 'AbortError', message: 'The operation was aborted.' };

      expect(describeError(error)).toEqual({
        message: 'The operation was aborted.',
        stack: null,
        kind: 'AbortError',
      });
    });

    it('serializes a string to itself', () => {
      expect(describeError('plain string blew up')).toEqual({
        message: 'plain string blew up',
        stack: null,
        kind: 'Error',
      });
    });

    it('serializes null to the literal string "null", never [object Object]', () => {
      expect(describeError(null)).toEqual({ message: 'null', stack: null, kind: 'Error' });
    });

    it('serializes undefined to the literal string "undefined", never [object Object]', () => {
      expect(describeError(undefined)).toEqual({
        message: 'undefined',
        stack: null,
        kind: 'Error',
      });
    });

    it('serializes a number, bigint and symbol via String()', () => {
      expect(describeError(404).message).toBe('404');
      expect(describeError(BigInt(9)).message).toBe('9');
      expect(describeError(Symbol('boom')).message).toBe('Symbol(boom)');
    });

    it('serializes a plain object to its constructor and keys, values absent', () => {
      const described = describeError({ code: 'E_BOOM', detail: 'context' });

      expect(described.message).toBe('Object{code, detail}');
      expect(described.message).not.toContain('E_BOOM');
      expect(described.message).not.toContain('context');
      expect(described.stack).toBeNull();
    });

    it('serializes an empty object to "Object{}", never [object Object]', () => {
      expect(describeError({})).toEqual({ message: 'Object{}', stack: null, kind: 'Error' });
    });

    it('serializes a circular object to a readable summary, never [object Object]', () => {
      class Circular {}
      const circular = new Circular() as Circular & { self?: unknown };
      circular.self = circular;

      const described = describeError(circular);

      expect(described.message).toBe('Circular{self}');
      expect(described.message).not.toBe('[object Object]');
      expect(described.stack).toBeNull();
    });

    it('never throws when a getter on the value throws, and returns the fallback', () => {
      const poisoned = {
        get name(): string {
          throw new Error('getter blew up');
        },
        message: 'irrelevant',
      };

      expect(() => describeError(poisoned, 'BootError')).not.toThrow();
      expect(describeError(poisoned, 'BootError')).toEqual({
        message: 'BootError',
        stack: null,
        kind: 'BootError',
      });
    });

    it('uses the given fallback kind when the value carries no kind of its own', () => {
      expect(describeError('boot broke', 'BootError').kind).toBe('BootError');
    });
  });

  describe('toClientErrorItem', () => {
    it('truncates an over-long message to the backend cap of 2000 chars', () => {
      const item = toClientErrorItem(
        { message: 'x'.repeat(3000), stack: null, kind: 'Error' },
        { route: '/reader' },
      );

      expect(item.message).toHaveLength(2000);
    });

    it('truncates an over-long stack to the backend cap of 8000 chars', () => {
      const item = toClientErrorItem(
        { message: 'boom', stack: 'x'.repeat(9000), kind: 'Error' },
        { route: '/reader' },
      );

      expect(item.stack).toHaveLength(8000);
    });

    it('keeps a null stack null', () => {
      const item = toClientErrorItem(
        { message: 'boom', stack: null, kind: 'Error' },
        { route: '/reader' },
      );

      expect(item.stack).toBeNull();
    });

    it('works for the boot path, where the route is null', () => {
      const item = toClientErrorItem(
        { message: 'boot broke', stack: null, kind: 'BootError' },
        { route: null },
      );

      expect(item.route).toBeNull();
      expect(typeof item.buildVersion).toBe('string');
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
