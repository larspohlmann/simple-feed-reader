// src/app/core/passkey.service.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from './api';
import { PasskeyService, PasskeySummary } from './passkey.service';
import { bytesToBase64Url } from './webauthn';
import { TokenStore } from './token.store';

const bytesOf = (text: string): ArrayBuffer => new TextEncoder().encode(text).buffer as ArrayBuffer;

/** Drains every pending microtask, however many `await` hops a ceremony's
 *  promise chain needs -- a fixed number of `await Promise.resolve()` calls
 *  is fragile against exactly that count changing. A `setTimeout` callback
 *  only runs once Node's microtask queue is fully empty, so this is a
 *  reliable "let everything settle" barrier regardless of chain depth. */
const flushMicrotasks = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

const creationOptions = {
  rp: { id: 'test', name: 'Test RP' },
  user: { id: 'dXNlci1oYW5kbGU', name: 'a@b.c', displayName: 'a@b.c' },
  challenge: 'Y2hhbGxlbmdl',
  pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
  authenticatorSelection: { userVerification: 'required', residentKey: 'required' },
  attestation: 'none',
  excludeCredentials: [],
};

const requestOptions = {
  challenge: 'YXNzZXJ0aW9uLWNoYWxsZW5nZQ',
  rpId: 'test',
  allowCredentials: [],
  userVerification: 'required',
};

function fixtureAttestationCredential(): Credential {
  return {
    id: 'credential-id',
    rawId: bytesOf('raw-id'),
    type: 'public-key',
    response: {
      clientDataJSON: bytesOf('{"type":"webauthn.create"}'),
      attestationObject: bytesOf('attestation-bytes'),
    },
  } as unknown as Credential;
}

function fixtureAssertionCredential(withUserHandle = true): Credential {
  return {
    id: 'credential-id',
    rawId: bytesOf('raw-id'),
    type: 'public-key',
    response: {
      clientDataJSON: bytesOf('{"type":"webauthn.get"}'),
      authenticatorData: bytesOf('authenticator-data'),
      signature: bytesOf('signature-bytes'),
      userHandle: withUserHandle ? bytesOf('user-handle') : null,
    },
  } as unknown as Credential;
}

describe('PasskeyService', () => {
  let svc: PasskeyService;
  let ctrl: HttpTestingController;
  let tokens: TokenStore;
  let create: jest.Mock;
  let get: jest.Mock;

  beforeEach(() => {
    localStorage.clear();
    create = jest.fn();
    get = jest.fn();
    (navigator as unknown as { credentials: unknown }).credentials = { create, get };

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    svc = TestBed.inject(PasskeyService);
    ctrl = TestBed.inject(HttpTestingController);
    tokens = TestBed.inject(TokenStore);
  });

  afterEach(() => {
    ctrl.verify();
    delete (navigator as unknown as { credentials?: unknown }).credentials;
  });

  it('enrol posts to register/options then register, in order, base64url-encoding the credential', async () => {
    create.mockResolvedValue(fixtureAttestationCredential());

    const enrolment = svc.enrol('MacBook Touch ID');

    const optionsReq = ctrl.expectOne('https://api.test/api/auth/passkey/register/options');
    expect(optionsReq.request.method).toBe('POST');
    optionsReq.flush({ options: creationOptions, handle: 'register-handle' });

    await flushMicrotasks();

    expect(create).toHaveBeenCalledTimes(1);
    const publicKey = create.mock.calls[0][0].publicKey;
    expect(new Uint8Array(publicKey.challenge)).toEqual(new TextEncoder().encode('challenge'));
    expect(new Uint8Array(publicKey.user.id)).toEqual(new TextEncoder().encode('user-handle'));

    const registerReq = ctrl.expectOne('https://api.test/api/auth/passkey/register');
    expect(registerReq.request.method).toBe('POST');
    expect(registerReq.request.body).toEqual({
      handle: 'register-handle',
      label: 'MacBook Touch ID',
      credential: {
        id: 'credential-id',
        rawId: bytesToBase64Url(bytesOf('raw-id')),
        type: 'public-key',
        response: {
          clientDataJSON: bytesToBase64Url(bytesOf('{"type":"webauthn.create"}')),
          attestationObject: bytesToBase64Url(bytesOf('attestation-bytes')),
        },
      },
    });
    registerReq.flush({ passkeys: [] }, { status: 201, statusText: 'Created' });

    await expect(enrolment).resolves.toBeUndefined();
  });

  it('surfaces a rejected registration ceremony as a Problem, not an unhandled rejection', async () => {
    create.mockRejectedValue(new DOMException('User cancelled.', 'NotAllowedError'));

    const enrolment = svc.enrol('MacBook Touch ID');
    ctrl
      .expectOne('https://api.test/api/auth/passkey/register/options')
      .flush({ options: creationOptions, handle: 'register-handle' });

    await expect(enrolment).rejects.toMatchObject({
      type: 'NotAllowedError',
      title: expect.any(String),
      status: expect.any(Number),
    });
  });

  // Task 15 aborts the conditional-mediation ceremony on every password-form
  // submit; Task 17's revocation dialog must keep the offer unanswered on a
  // cancelled sheet. Both need a stable identifier to branch on rather than
  // string-matching `title`, so each DOMException name a real authenticator
  // rejects with must survive into `Problem.type` unchanged.
  it.each([['NotAllowedError'], ['AbortError'], ['InvalidStateError']])(
    'preserves the DOMException name %s as the Problem type, for callers to branch on',
    async (name) => {
      create.mockRejectedValue(new DOMException(`${name} message`, name));

      const enrolment = svc.enrol('MacBook Touch ID');
      ctrl
        .expectOne('https://api.test/api/auth/passkey/register/options')
        .flush({ options: creationOptions, handle: 'register-handle' });

      await expect(enrolment).rejects.toMatchObject({ type: name, status: 0 });
    },
  );

  it('degrades a non-DOMException ceremony rejection to about:blank', async () => {
    create.mockRejectedValue(new Error('something else went wrong'));

    const enrolment = svc.enrol('MacBook Touch ID');
    ctrl
      .expectOne('https://api.test/api/auth/passkey/register/options')
      .flush({ options: creationOptions, handle: 'register-handle' });

    await expect(enrolment).rejects.toMatchObject({
      type: 'about:blank',
      title: 'something else went wrong',
      status: 0,
    });
  });

  it('surfaces a failed options request as a Problem', async () => {
    const enrolment = svc.enrol('MacBook Touch ID');
    ctrl
      .expectOne('https://api.test/api/auth/passkey/register/options')
      .flush(
        { type: 'about:blank', title: 'Unauthorized', status: 401 },
        { status: 401, statusText: 'Unauthorized' },
      );

    await expect(enrolment).rejects.toMatchObject({ status: 401, title: 'Unauthorized' });
  });

  it('list unwraps the {passkeys} envelope', () => {
    let result: PasskeySummary[] | undefined;
    svc.list().subscribe((passkeys) => (result = passkeys));

    ctrl.expectOne('https://api.test/api/auth/passkeys').flush({
      passkeys: [
        { id: 1, label: 'Phone', createdAt: '2026-08-01T00:00:00+00:00', lastUsedAt: null },
      ],
    });

    expect(result).toEqual([
      { id: 1, label: 'Phone', createdAt: '2026-08-01T00:00:00+00:00', lastUsedAt: null },
    ]);
  });

  it('remove deletes by id', () => {
    svc.remove(7).subscribe();
    const req = ctrl.expectOne('https://api.test/api/auth/passkeys/7');
    expect(req.request.method).toBe('DELETE');
    req.flush(null, { status: 204, statusText: 'No Content' });
  });

  it('signIn resolves to the token from the login call and stores it', async () => {
    get.mockResolvedValue(fixtureAssertionCredential());

    const signIn = svc.signIn();

    const optionsReq = ctrl.expectOne('https://api.test/api/auth/passkey/login/options');
    optionsReq.flush({ options: requestOptions, handle: 'login-handle' });

    await flushMicrotasks();

    expect(get).toHaveBeenCalledTimes(1);
    expect(get.mock.calls[0][0].mediation).toBeUndefined();
    expect(get.mock.calls[0][0].signal).toBeUndefined();

    const loginReq = ctrl.expectOne('https://api.test/api/auth/passkey/login');
    expect(loginReq.request.body).toEqual({
      handle: 'login-handle',
      credential: {
        id: 'credential-id',
        rawId: bytesToBase64Url(bytesOf('raw-id')),
        type: 'public-key',
        response: {
          clientDataJSON: bytesToBase64Url(bytesOf('{"type":"webauthn.get"}')),
          authenticatorData: bytesToBase64Url(bytesOf('authenticator-data')),
          signature: bytesToBase64Url(bytesOf('signature-bytes')),
          userHandle: bytesToBase64Url(bytesOf('user-handle')),
        },
      },
    });
    loginReq.flush({ token: 'jwt-abc' });

    await expect(signIn).resolves.toBe('jwt-abc');
    expect(tokens.token()).toBe('jwt-abc');
  });

  it('signInConditionally passes mediation and the abort signal to navigator.credentials.get', async () => {
    get.mockResolvedValue(fixtureAssertionCredential(false));
    const controller = new AbortController();

    const signIn = svc.signInConditionally(controller.signal);

    ctrl
      .expectOne('https://api.test/api/auth/passkey/login/options')
      .flush({ options: requestOptions, handle: 'login-handle' });

    await flushMicrotasks();

    expect(get.mock.calls[0][0].mediation).toBe('conditional');
    expect(get.mock.calls[0][0].signal).toBe(controller.signal);

    ctrl.expectOne('https://api.test/api/auth/passkey/login').flush({ token: 'jwt-conditional' });

    await expect(signIn).resolves.toBe('jwt-conditional');
  });

  it('surfaces a rejected login ceremony as a Problem', async () => {
    get.mockRejectedValue(new DOMException('No credential available.', 'NotAllowedError'));

    const signIn = svc.signIn();
    ctrl
      .expectOne('https://api.test/api/auth/passkey/login/options')
      .flush({ options: requestOptions, handle: 'login-handle' });

    await expect(signIn).rejects.toMatchObject({
      type: expect.any(String),
      title: expect.any(String),
      status: expect.any(Number),
    });
  });
});
