import {
  base64UrlToBytes,
  bytesToBase64Url,
  isConditionalMediationSupported,
  isPasskeySupported,
  signalAllAcceptedCredentials,
  signalUnknownCredential,
} from './webauthn';
import {
  removePublicKeyCredential,
  stubPublicKeyCredential,
} from '../../testing/public-key-credential';

describe('base64UrlToBytes / bytesToBase64Url', () => {
  it('round-trips arbitrary bytes, including ones that need padding', () => {
    const original = new Uint8Array([0, 1, 2, 3, 251, 252, 253, 254, 255]);
    const encoded = bytesToBase64Url(original.buffer);
    const decoded = new Uint8Array(base64UrlToBytes(encoded));
    expect(Array.from(decoded)).toEqual(Array.from(original));
  });

  it('decodes a value with no padding', () => {
    // 5 raw bytes base64-encodes to 8 chars with no '=' padding at all.
    const bytes = new Uint8Array([104, 101, 108, 108, 111]); // "hello"
    const decoded = new Uint8Array(base64UrlToBytes('aGVsbG8'));
    expect(Array.from(decoded)).toEqual(Array.from(bytes));
  });

  it('decodes using the base64url alphabet, not base64', () => {
    // Byte 0xFB 0xFF 0xBE base64-encodes to '+/++' in standard base64 and to
    // '-_-' + '-' in base64url. Feeding the base64url form through must
    // reproduce the original bytes, not silently corrupt them.
    const original = new Uint8Array([0xfb, 0xff, 0xbe]);
    const base64Form = Buffer.from(original).toString('base64');
    expect(base64Form).toContain('+');
    expect(base64Form).toContain('/');

    const base64UrlForm = base64Form.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const decoded = new Uint8Array(base64UrlToBytes(base64UrlForm));
    expect(Array.from(decoded)).toEqual(Array.from(original));
  });

  it('encodes using the base64url alphabet, unpadded', () => {
    // Asserts the exact base64url output '-_--', not merely the absence of
    // '+', '/' and '=' -- that alone would pass an encoder that stripped
    // those characters instead of substituting them, corrupting the decode.
    const original = new Uint8Array([0xfb, 0xff, 0xbe]);
    const encoded = bytesToBase64Url(original.buffer);
    expect(encoded).toBe('-_--');
  });
});

describe('isPasskeySupported', () => {
  afterEach(removePublicKeyCredential);

  it('is false when window.PublicKeyCredential is absent', () => {
    expect('PublicKeyCredential' in window).toBe(false);
    expect(isPasskeySupported()).toBe(false);
  });

  it('is true when the browser exposes PublicKeyCredential', () => {
    stubPublicKeyCredential({});
    expect(isPasskeySupported()).toBe(true);
  });
});

describe('isConditionalMediationSupported', () => {
  afterEach(removePublicKeyCredential);

  it('resolves false when PublicKeyCredential is absent', async () => {
    await expect(isConditionalMediationSupported()).resolves.toBe(false);
  });

  it('resolves false when the browser has no isConditionalMediationAvailable method', async () => {
    stubPublicKeyCredential({});
    await expect(isConditionalMediationSupported()).resolves.toBe(false);
  });

  it('resolves false when the browser check rejects', async () => {
    stubPublicKeyCredential({
      isConditionalMediationAvailable: jest.fn().mockRejectedValue(new Error('boom')),
    });
    await expect(isConditionalMediationSupported()).resolves.toBe(false);
  });

  it('resolves to whatever the browser check reports', async () => {
    stubPublicKeyCredential({
      isConditionalMediationAvailable: jest.fn().mockResolvedValue(true),
    });
    await expect(isConditionalMediationSupported()).resolves.toBe(true);
  });
});

const signalHelpers = [
  ['signalUnknownCredential', () => signalUnknownCredential('test', 'Y3JlZA')],
  ['signalAllAcceptedCredentials', () => signalAllAcceptedCredentials('test', 'aGFuZGxl', ['a'])],
] as const;

// Both helpers are best-effort by contract: whatever the browser lacks or
// throws, the caller's sign-in, enrolment or listing goes on unchanged.
describe.each(signalHelpers)('%s', (name, call) => {
  afterEach(removePublicKeyCredential);

  it('resolves when PublicKeyCredential is absent', async () => {
    await expect(call()).resolves.toBeUndefined();
  });

  it('resolves when the browser lacks the method', async () => {
    stubPublicKeyCredential({});
    await expect(call()).resolves.toBeUndefined();
  });

  it('resolves when the browser call rejects', async () => {
    stubPublicKeyCredential({ [name]: jest.fn().mockRejectedValue(new Error('boom')) });
    await expect(call()).resolves.toBeUndefined();
  });
});

describe('signalUnknownCredential', () => {
  afterEach(removePublicKeyCredential);

  it('hands the browser the rp id and the credential id exactly as given', async () => {
    const signal = jest.fn().mockResolvedValue(undefined);
    stubPublicKeyCredential({ signalUnknownCredential: signal });

    await signalUnknownCredential('test', 'Y3JlZA');

    expect(signal).toHaveBeenCalledWith({ rpId: 'test', credentialId: 'Y3JlZA' });
  });
});

describe('signalAllAcceptedCredentials', () => {
  afterEach(removePublicKeyCredential);

  it('hands the browser the handle and the list exactly as given', async () => {
    const signal = jest.fn().mockResolvedValue(undefined);
    stubPublicKeyCredential({ signalAllAcceptedCredentials: signal });

    await signalAllAcceptedCredentials('test', 'aGFuZGxl', ['Zmlyc3Q', 'c2Vjb25k']);

    expect(signal).toHaveBeenCalledWith({
      rpId: 'test',
      userId: 'aGFuZGxl',
      allAcceptedCredentialIds: ['Zmlyc3Q', 'c2Vjb25k'],
    });
  });

  // An empty list is how the last passkey's deletion reaches the browser; it
  // must go through unchanged, never be treated as "nothing to send".
  it('passes an empty list through unchanged', async () => {
    const signal = jest.fn().mockResolvedValue(undefined);
    stubPublicKeyCredential({ signalAllAcceptedCredentials: signal });

    await signalAllAcceptedCredentials('test', 'aGFuZGxl', []);

    expect(signal).toHaveBeenCalledWith({
      rpId: 'test',
      userId: 'aGFuZGxl',
      allAcceptedCredentialIds: [],
    });
  });
});
