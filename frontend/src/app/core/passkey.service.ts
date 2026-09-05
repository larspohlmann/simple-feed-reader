// src/app/core/passkey.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, firstValueFrom, map, tap } from 'rxjs';
import { API_BASE_URL } from './api';
import { Problem, parseProblem } from './problem';
import { TokenStore } from './token.store';
import {
  base64UrlToBytes,
  bytesToBase64Url,
  signalAllAcceptedCredentials,
  signalUnknownCredential,
} from './webauthn';

export interface PasskeySummary {
  id: number;
  label: string;
  createdAt: string;
  lastUsedAt: string | null;
}

/** The listing body since #727 -- see `PasskeyJson::listing()`. The id list
 *  is authoritative and goes to the browser unchanged. */
interface PasskeyListingJson {
  rpId: string;
  userHandle: string | null;
  acceptedCredentialIds: string[];
  passkeys: PasskeySummary[];
}

interface SignalSubject {
  rpId: string;
  userHandle: string;
}

/** Mediation and abort control for a login ceremony -- the only difference
 *  between an explicit "Sign in with a passkey" button and letting the
 *  browser offer one through a plain login field's autofill. */
interface LoginCeremonyOptions {
  mediation?: CredentialMediationRequirement;
  signal?: AbortSignal;
}

/** The `{options, handle}` shape both `PasskeyJson::optionsResponse()` calls
 *  return -- see `backend/src/Http/PasskeyJson.php`. */
interface CeremonyOptions<TOptions> {
  options: TOptions;
  handle: string;
}

/** The wire shape `AttestationVerifier::deserialize()` expects: every
 *  binary field base64url-encoded, nothing else. */
interface RegistrationCredentialJson {
  id: string;
  rawId: string;
  type: string;
  response: {
    clientDataJSON: string;
    attestationObject: string;
  };
}

/** The wire shape `AssertionVerifier::parse()` expects. */
interface AssertionCredentialJson {
  id: string;
  rawId: string;
  type: string;
  response: {
    clientDataJSON: string;
    authenticatorData: string;
    signature: string;
    userHandle: string | null;
  };
}

/** The `register` request body -- see `RegisterPasskeyRequest`. */
interface RegistrationBody {
  handle: string;
  credential: RegistrationCredentialJson;
  label: string;
}

/** The `login` request body PasskeyAuthenticator reads. */
interface AssertionBody {
  handle: string;
  credential: AssertionCredentialJson;
}

/**
 * Drives the two WebAuthn ceremonies against the passkey endpoints (#624)
 * and is the only place that converts between the backend's base64url wire
 * contract and the `ArrayBuffer`s `navigator.credentials` deals in -- see
 * `core/webauthn.ts`'s docblock for why that boundary matters. Since #727 it
 * is also the only caller of the Signal API helpers in core/webauthn.ts,
 * which prune stale entries from the browser's password manager.
 *
 * A rejected ceremony (the user cancels the platform prompt, an
 * authenticator errors) throws a `Problem`, the same shape every other API
 * failure in this app surfaces as, rather than letting the browser's raw
 * `DOMException` reach a caller that only knows how to render `Problem`s.
 */
@Injectable({ providedIn: 'root' })
export class PasskeyService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly tokens = inject(TokenStore);

  /** The last handle seen, so the sweep after deleting the LAST passkey still
   *  has the key those credentials were created under: that response carries
   *  `userHandle: null` and an empty list. */
  private signalSubject: SignalSubject | null = null;

  async enrol(label: string): Promise<void> {
    try {
      const { options, handle } = await firstValueFrom(
        this.http.post<CeremonyOptions<PublicKeyCredentialCreationOptionsJSON>>(
          `${this.base}/api/auth/passkey/register/options`,
          {},
        ),
      );
      const credential = await this.createCredential(options);
      await this.register(options.rp.id, { handle, credential, label });
    } catch (error) {
      throw this.toProblem(error);
    }
  }

  list(): Observable<PasskeySummary[]> {
    return this.http.get<PasskeyListingJson>(`${this.base}/api/auth/passkeys`).pipe(
      tap((listing) => this.pruneStaleCredentials(listing)),
      map((listing) => listing.passkeys),
    );
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/auth/passkeys/${id}`);
  }

  async signIn(): Promise<string> {
    return this.login({});
  }

  async signInConditionally(signal: AbortSignal): Promise<string> {
    return this.login({ mediation: 'conditional', signal });
  }

  private async login(ceremonyOptions: LoginCeremonyOptions): Promise<string> {
    try {
      const { options, handle } = await firstValueFrom(
        this.http.post<CeremonyOptions<PublicKeyCredentialRequestOptionsJSON>>(
          `${this.base}/api/auth/passkey/login/options`,
          {},
        ),
      );
      const credential = await this.getCredential(options, ceremonyOptions);
      const token = await this.exchangeAssertion(options.rpId, { handle, credential });
      this.tokens.set(token);
      return token;
    } catch (error) {
      throw this.toProblem(error);
    }
  }

  private async createCredential(
    options: PublicKeyCredentialCreationOptionsJSON,
  ): Promise<RegistrationCredentialJson> {
    const credential = (await navigator.credentials.create({
      publicKey: decodeCreationOptions(options),
    })) as PublicKeyCredential;
    return encodeRegistrationCredential(credential);
  }

  /** The authenticator already holds the credential when the server refuses
   *  it (#727), so a 4xx tells the browser to drop it. Not on status 0 or a
   *  5xx: the row may exist and only the response was lost. */
  private async register(rpId: string | undefined, body: RegistrationBody): Promise<void> {
    try {
      await firstValueFrom(this.http.post<void>(`${this.base}/api/auth/passkey/register`, body));
    } catch (error) {
      if (rpId && isClientRejection(error)) {
        await signalUnknownCredential(rpId, body.credential.id);
      }
      throw error;
    }
  }

  /** Signals on the ONE type that means "no account holds this id" (#727).
   *  Any other 401 -- an expired challenge above all -- names a working
   *  passkey, and the signal is irreversible. */
  private async exchangeAssertion(rpId: string | undefined, body: AssertionBody): Promise<string> {
    try {
      const { token } = await firstValueFrom(
        this.http.post<{ token: string }>(`${this.base}/api/auth/passkey/login`, body),
      );
      return token;
    } catch (error) {
      if (rpId && isUnknownCredential(error)) {
        await signalUnknownCredential(rpId, body.credential.id);
      }
      throw error;
    }
  }

  private async getCredential(
    options: PublicKeyCredentialRequestOptionsJSON,
    ceremonyOptions: LoginCeremonyOptions,
  ): Promise<AssertionCredentialJson> {
    const credential = (await navigator.credentials.get({
      publicKey: decodeRequestOptions(options),
      mediation: ceremonyOptions.mediation,
      signal: ceremonyOptions.signal,
    })) as PublicKeyCredential;
    return encodeAssertionCredential(credential);
  }

  /** Fire-and-forget (#727): the browser drops every entry outside the
   *  server's list. The list is passed through exactly as received -- a
   *  rebuilt or shortened one would delete valid credentials. */
  private pruneStaleCredentials(listing: PasskeyListingJson): void {
    if (listing.userHandle !== null) {
      this.signalSubject = { rpId: listing.rpId, userHandle: listing.userHandle };
    }
    if (!this.signalSubject) return;
    void signalAllAcceptedCredentials(
      this.signalSubject.rpId,
      this.signalSubject.userHandle,
      listing.acceptedCredentialIds,
    );
  }

  /** Every failure this service can throw -- a rejected HTTP call or a
   *  rejected ceremony -- surfaces through here as a `Problem`, so no caller
   *  ever has to distinguish an `HttpErrorResponse` from a `DOMException`.
   *
   *  A rejected ceremony's `type` is the DOMException's own `name` --
   *  `NotAllowedError` (the user cancelled the sheet, or it timed out),
   *  `AbortError` (the caller itself aborted the request, e.g. the
   *  conditional-mediation ceremony on every password-form submit), or
   *  `InvalidStateError` (this authenticator is already enrolled -- the
   *  exclude list produced it) among others. That name is a fixed,
   *  non-localised identifier a caller can branch on directly; `error.message`
   *  is free text that varies by browser and locale, so it goes into `title`
   *  for display, never as the thing callers switch on.
   *
   *  This branch also stamps `ceremonyRejected: true` (`Problem`'s own
   *  docblock has the full reasoning): both this branch and a genuine dropped
   *  connection through `parseProblem()` below produce `status: 0`, so a
   *  caller that wants to hide only the browser's raw `title` needs a flag
   *  that says which of the two this is, not the shared status. */
  private toProblem(error: unknown): Problem {
    if (error instanceof HttpErrorResponse) {
      return parseProblem(error);
    }
    return {
      type: error instanceof DOMException ? error.name : 'about:blank',
      title: error instanceof Error ? error.message : 'The passkey ceremony failed.',
      status: 0,
      ceremonyRejected: true,
    };
  }
}

/** lib.dom.d.ts declares `hints` only on the JSON variants, not on what
 *  `navigator.credentials` takes. */
type PasskeyHint = 'client-device' | 'security-key' | 'hybrid';
type WithHints<TOptions> = TOptions & { hints: PasskeyHint[] };

/** Without this Chrome puts the QR flow first, so enrolling from a desktop
 *  saved the passkey on a phone. A preference, not a restriction. */
const LOCAL_DEVICE_FIRST: PasskeyHint[] = ['client-device'];

function isClientRejection(error: unknown): boolean {
  return error instanceof HttpErrorResponse && error.status >= 400 && error.status < 500;
}

/** `UnknownPasskeyCredentialException::$type` on the backend. */
const UNKNOWN_PASSKEY_CREDENTIAL = 'unknown_passkey_credential';

function isUnknownCredential(error: unknown): boolean {
  return (
    error instanceof HttpErrorResponse && parseProblem(error).type === UNKNOWN_PASSKEY_CREDENTIAL
  );
}

function decodeCreationOptions(
  json: PublicKeyCredentialCreationOptionsJSON,
): WithHints<PublicKeyCredentialCreationOptions> {
  return {
    hints: LOCAL_DEVICE_FIRST,
    rp: json.rp,
    user: { ...json.user, id: base64UrlToBytes(json.user.id) },
    challenge: base64UrlToBytes(json.challenge),
    pubKeyCredParams: json.pubKeyCredParams,
    authenticatorSelection: json.authenticatorSelection,
    // Cast: the server only ever sends one of the enum's own string values.
    attestation: json.attestation as AttestationConveyancePreference | undefined,
    excludeCredentials: (json.excludeCredentials ?? []).map(decodeDescriptor),
  };
}

function decodeRequestOptions(
  json: PublicKeyCredentialRequestOptionsJSON,
): WithHints<PublicKeyCredentialRequestOptions> {
  return {
    hints: LOCAL_DEVICE_FIRST,
    challenge: base64UrlToBytes(json.challenge),
    rpId: json.rpId,
    // Cast: the server only ever sends one of the enum's own string values.
    userVerification: json.userVerification as UserVerificationRequirement | undefined,
    allowCredentials: (json.allowCredentials ?? []).map(decodeDescriptor),
  };
}

function decodeDescriptor(
  descriptor: PublicKeyCredentialDescriptorJSON,
): PublicKeyCredentialDescriptor {
  return { id: base64UrlToBytes(descriptor.id), type: 'public-key' };
}

function encodeRegistrationCredential(credential: PublicKeyCredential): RegistrationCredentialJson {
  const response = credential.response as AuthenticatorAttestationResponse;
  return {
    id: credential.id,
    rawId: bytesToBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: bytesToBase64Url(response.clientDataJSON),
      attestationObject: bytesToBase64Url(response.attestationObject),
    },
  };
}

function encodeAssertionCredential(credential: PublicKeyCredential): AssertionCredentialJson {
  const response = credential.response as AuthenticatorAssertionResponse;
  return {
    id: credential.id,
    rawId: bytesToBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: bytesToBase64Url(response.clientDataJSON),
      authenticatorData: bytesToBase64Url(response.authenticatorData),
      signature: bytesToBase64Url(response.signature),
      userHandle: response.userHandle ? bytesToBase64Url(response.userHandle) : null,
    },
  };
}
