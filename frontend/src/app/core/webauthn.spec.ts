import {
  base64UrlToBytes,
  bytesToBase64Url,
  isConditionalMediationSupported,
  isPasskeySupported,
} from './webauthn';

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
  afterEach(() => {
    delete (window as unknown as { PublicKeyCredential?: unknown }).PublicKeyCredential;
  });

  it('is false when window.PublicKeyCredential is absent', () => {
    expect('PublicKeyCredential' in window).toBe(false);
    expect(isPasskeySupported()).toBe(false);
  });

  it('is true when the browser exposes PublicKeyCredential', () => {
    (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {};
    expect(isPasskeySupported()).toBe(true);
  });
});

describe('isConditionalMediationSupported', () => {
  afterEach(() => {
    delete (window as unknown as { PublicKeyCredential?: unknown }).PublicKeyCredential;
  });

  it('resolves false when PublicKeyCredential is absent', async () => {
    await expect(isConditionalMediationSupported()).resolves.toBe(false);
  });

  it('resolves false when the browser has no isConditionalMediationAvailable method', async () => {
    (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {};
    await expect(isConditionalMediationSupported()).resolves.toBe(false);
  });

  it('resolves false when the browser check rejects', async () => {
    (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {
      isConditionalMediationAvailable: jest.fn().mockRejectedValue(new Error('boom')),
    };
    await expect(isConditionalMediationSupported()).resolves.toBe(false);
  });

  it('resolves to whatever the browser check reports', async () => {
    (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {
      isConditionalMediationAvailable: jest.fn().mockResolvedValue(true),
    };
    await expect(isConditionalMediationSupported()).resolves.toBe(true);
  });
});
