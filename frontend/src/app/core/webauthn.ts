// src/app/core/webauthn.ts

/**
 * Pure WebAuthn helpers (#624): base64url<->bytes conversion and capability
 * detection. No Angular imports, so this is testable without a TestBed.
 * `PasskeyService` (core/passkey.service.ts) is the only caller and owns
 * everything ceremony-shaped -- this file knows nothing about HTTP or the
 * backend's wire contract.
 *
 * Every binary field the passkey endpoints exchange -- challenges,
 * credential ids, client data -- crosses the wire base64url-encoded (RFC
 * 4648 §5: '-' and '_' in place of '+' and '/', no padding). The browser's
 * `navigator.credentials` API only understands raw bytes, so getting the
 * alphabet or the padding wrong here would silently corrupt every
 * credential id it touches rather than throwing.
 */

/** Decodes a base64url string into raw bytes. */
export function base64UrlToBytes(value: string): ArrayBuffer {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  const paddingNeeded = (4 - (base64.length % 4)) % 4;
  const binary = atob(base64.padEnd(base64.length + paddingNeeded, '='));

  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}

/** Encodes raw bytes as an unpadded base64url string. */
export function bytesToBase64Url(value: ArrayBuffer): string {
  let binary = '';
  for (const byte of new Uint8Array(value)) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** True when the browser exposes the WebAuthn API at all. jsdom (the test
 *  environment) has no `PublicKeyCredential`, so "unsupported" is what every
 *  test gets without a stub. */
export function isPasskeySupported(): boolean {
  return typeof window !== 'undefined' && 'PublicKeyCredential' in window;
}

/** True when the browser can offer a passkey through a plain login field's
 *  autofill (`mediation: 'conditional'`). Resolves to false rather than
 *  rejecting both when the capability check itself is missing and when the
 *  browser's own check throws: callers use this to decide whether to offer
 *  autofill, not to detect a hard failure. */
export async function isConditionalMediationSupported(): Promise<boolean> {
  if (!isPasskeySupported()) {
    return false;
  }

  const check = window.PublicKeyCredential.isConditionalMediationAvailable;
  if (typeof check !== 'function') {
    return false;
  }

  try {
    return await check();
  } catch {
    return false;
  }
}

/** The two WebAuthn L3 Signal API statics (#727). TypeScript 5.9's lib.dom
 *  does not declare them. Every id here is already a base64url string --
 *  the encoding the backend stores -- so nothing is converted. */
interface SignalMethods {
  signalUnknownCredential?: (options: { rpId: string; credentialId: string }) => Promise<void>;
  signalAllAcceptedCredentials?: (options: {
    rpId: string;
    userId: string;
    allAcceptedCredentialIds: string[];
  }) => Promise<void>;
}

function signalMethods(): SignalMethods {
  return isPasskeySupported() ? (window.PublicKeyCredential as unknown as SignalMethods) : {};
}

/** Every signal is best-effort: it resolves without effect when the API or
 *  the method is absent and when the browser throws, so it can never gate a
 *  sign-in, an enrolment or a listing. The optional call keeps `this` bound. */
async function bestEffort(
  signal: (methods: SignalMethods) => Promise<void> | undefined,
): Promise<void> {
  try {
    await signal(signalMethods());
  } catch {
    // Best-effort by design -- see the docblock.
  }
}

/** Tells the browser a credential id the server does not know, so the
 *  password manager can drop it. */
export function signalUnknownCredential(rpId: string, credentialId: string): Promise<void> {
  return bestEffort((methods) => methods.signalUnknownCredential?.({ rpId, credentialId }));
}

/** Hands the browser one account's authoritative credential set so anything
 *  stale disappears. The list must be complete: a short or empty list makes
 *  the browser delete valid credentials for that user. */
export function signalAllAcceptedCredentials(
  rpId: string,
  userId: string,
  allAcceptedCredentialIds: string[],
): Promise<void> {
  return bestEffort((methods) =>
    methods.signalAllAcceptedCredentials?.({ rpId, userId, allAcceptedCredentialIds }),
  );
}
