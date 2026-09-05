import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
  TestRequest,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from './api';
import { PasskeyService, PasskeySummary } from './passkey.service';
import { bytesToBase64Url } from './webauthn';
import { TokenStore } from './token.store';

const bytesOf = (text: string): ArrayBuffer => new TextEncoder().encode(text).buffer as ArrayBuffer;

/** Drains all pending microtasks regardless of a ceremony's promise-chain
 *  depth -- more reliable than counting `await Promise.resolve()` calls.
 *  A setTimeout callback only runs once the microtask queue is empty. */
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

interface ListingBody {
  rpId: string;
  userHandle: string | null;
  acceptedCredentialIds: string[];
  passkeys: PasskeySummary[];
}

function listingBody(overrides: Partial<ListingBody> = {}): ListingBody {
  return {
    rpId: 'test',
    userHandle: 'aGFuZGxl',
    acceptedCredentialIds: ['Y3JlZC1hYmM'],
    passkeys: [],
    ...overrides,
  };
}

interface WindowWithCredential {
  PublicKeyCredential?: unknown;
}

/** Installs a fake Signal API and returns its spies. jsdom has none, so the
 *  absent-API case is what every test gets without this. */
function installSignalApi(): { unknown: jest.Mock; allAccepted: jest.Mock } {
  const unknown = jest.fn().mockResolvedValue(undefined);
  const allAccepted = jest.fn().mockResolvedValue(undefined);
  (window as unknown as WindowWithCredential).PublicKeyCredential = {
    signalUnknownCredential: unknown,
    signalAllAcceptedCredentials: allAccepted,
  };
  return { unknown, allAccepted };
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
    delete (window as unknown as WindowWithCredential).PublicKeyCredential;
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
      ceremonyRejected: true,
    });
  });

  // Callers need a stable identifier to branch on rather than string-matching
  // `title` -- e.g. aborting a ceremony on submit, or a cancelled revocation
  // dialog -- so each DOMException name must survive into Problem.type unchanged.
  it.each([['NotAllowedError'], ['AbortError'], ['InvalidStateError']])(
    'preserves the DOMException name %s as the Problem type, for callers to branch on',
    async (name) => {
      create.mockRejectedValue(new DOMException(`${name} message`, name));

      const enrolment = svc.enrol('MacBook Touch ID');
      ctrl
        .expectOne('https://api.test/api/auth/passkey/register/options')
        .flush({ options: creationOptions, handle: 'register-handle' });

      await expect(enrolment).rejects.toMatchObject({
        type: name,
        status: 0,
        ceremonyRejected: true,
      });
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
      ceremonyRejected: true,
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

  // A dropped connection also produces status: 0, same as the DOMException
  // branch, but must NOT carry ceremonyRejected -- it never reached the
  // browser's ceremony, so its own "could not reach the server" text stays.
  it('does not mark a genuine network failure as a rejected ceremony', async () => {
    const enrolment = svc.enrol('MacBook Touch ID');
    ctrl
      .expectOne('https://api.test/api/auth/passkey/register/options')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });

    await expect(enrolment).rejects.toMatchObject({ status: 0 });
    await expect(enrolment).rejects.not.toHaveProperty('ceremonyRejected');
  });

  describe('a credential the server refused', () => {
    /** Runs the options + ceremony half of `enrol()` and returns the pending
     *  register request, so each test decides only how the server answers. */
    async function enrolUpToRegister(): Promise<{
      enrolment: Promise<void>;
      register: TestRequest;
    }> {
      create.mockResolvedValue(fixtureAttestationCredential());
      const enrolment = svc.enrol('MacBook Touch ID');
      ctrl
        .expectOne('https://api.test/api/auth/passkey/register/options')
        .flush({ options: creationOptions, handle: 'register-handle' });
      await flushMicrotasks();
      return { enrolment, register: ctrl.expectOne('https://api.test/api/auth/passkey/register') };
    }

    // The authenticator already holds the credential the server just refused
    // (#727); without the signal the sign-in sheet offers it forever.
    it('tells the browser to drop it on a 4xx from register', async () => {
      const { unknown } = installSignalApi();
      const { enrolment, register } = await enrolUpToRegister();

      register.flush(
        { type: 'passkey_attestation_rejected', title: 'Rejected', status: 400 },
        { status: 400, statusText: 'Bad Request' },
      );

      await expect(enrolment).rejects.toMatchObject({ status: 400 });
      expect(unknown).toHaveBeenCalledWith({ rpId: 'test', credentialId: 'credential-id' });
    });

    // A lost response is not a refusal: the row may exist, and the signal is
    // irreversible.
    it('leaves the browser alone on a network failure during register', async () => {
      const { unknown } = installSignalApi();
      const { enrolment, register } = await enrolUpToRegister();

      register.error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });

      await expect(enrolment).rejects.toMatchObject({ status: 0 });
      expect(unknown).not.toHaveBeenCalled();
    });

    it('leaves the browser alone on a 5xx from register', async () => {
      const { unknown } = installSignalApi();
      const { enrolment, register } = await enrolUpToRegister();

      register.flush(
        { type: 'about:blank', title: 'Server error', status: 500 },
        { status: 500, statusText: 'Internal Server Error' },
      );

      await expect(enrolment).rejects.toMatchObject({ status: 500 });
      expect(unknown).not.toHaveBeenCalled();
    });

    // A rejected ceremony created no credential, so there is nothing to prune.
    it('signals nothing when the ceremony itself was rejected', async () => {
      const { unknown } = installSignalApi();
      create.mockRejectedValue(new DOMException('User cancelled.', 'NotAllowedError'));

      const enrolment = svc.enrol('MacBook Touch ID');
      ctrl
        .expectOne('https://api.test/api/auth/passkey/register/options')
        .flush({ options: creationOptions, handle: 'register-handle' });

      await expect(enrolment).rejects.toMatchObject({ type: 'NotAllowedError' });
      expect(unknown).not.toHaveBeenCalled();
    });

    it('still surfaces the Problem when the browser rejects the signal', async () => {
      const { unknown } = installSignalApi();
      unknown.mockRejectedValue(new Error('boom'));
      const { enrolment, register } = await enrolUpToRegister();

      register.flush(
        { type: 'passkey_attestation_rejected', title: 'Rejected', status: 400 },
        { status: 400, statusText: 'Bad Request' },
      );

      await expect(enrolment).rejects.toMatchObject({ status: 400, title: 'Rejected' });
    });
  });

  it('list unwraps the {passkeys} envelope', () => {
    const passkeys: PasskeySummary[] = [
      { id: 1, label: 'Phone', createdAt: '2026-08-01T00:00:00Z', lastUsedAt: null },
    ];
    let received: PasskeySummary[] | undefined;

    svc.list().subscribe((list) => (received = list));
    ctrl.expectOne('https://api.test/api/auth/passkeys').flush(listingBody({ passkeys }));

    expect(received).toEqual(passkeys);
  });

  describe('pruning stale passkeys from the browser on every listing', () => {
    it('hands the browser the authoritative set exactly as the server sent it', async () => {
      const { allAccepted } = installSignalApi();

      svc.list().subscribe();
      ctrl
        .expectOne('https://api.test/api/auth/passkeys')
        .flush(listingBody({ acceptedCredentialIds: ['Zmlyc3Q', 'c2Vjb25k'] }));
      await flushMicrotasks();

      expect(allAccepted).toHaveBeenCalledWith({
        rpId: 'test',
        userId: 'aGFuZGxl',
        allAcceptedCredentialIds: ['Zmlyc3Q', 'c2Vjb25k'],
      });
    });

    // After the LAST passkey is deleted the server has no handle to send, and
    // the sweep needs one exactly then. The handle from the listing before
    // the delete is the key those credentials were created under.
    it('uses the remembered handle when the account has no passkeys left', async () => {
      const { allAccepted } = installSignalApi();

      svc.list().subscribe();
      ctrl.expectOne('https://api.test/api/auth/passkeys').flush(listingBody());
      svc.list().subscribe();
      ctrl
        .expectOne('https://api.test/api/auth/passkeys')
        .flush(listingBody({ userHandle: null, acceptedCredentialIds: [] }));
      await flushMicrotasks();

      expect(allAccepted).toHaveBeenLastCalledWith({
        rpId: 'test',
        userId: 'aGFuZGxl',
        allAcceptedCredentialIds: [],
      });
    });

    it('signals nothing when no handle was ever seen', async () => {
      const { allAccepted } = installSignalApi();

      svc.list().subscribe();
      ctrl
        .expectOne('https://api.test/api/auth/passkeys')
        .flush(listingBody({ userHandle: null, acceptedCredentialIds: [] }));
      await flushMicrotasks();

      expect(allAccepted).not.toHaveBeenCalled();
    });

    it('still delivers the rows when the browser rejects the signal', async () => {
      const { allAccepted } = installSignalApi();
      allAccepted.mockRejectedValue(new Error('boom'));
      const passkeys: PasskeySummary[] = [
        { id: 1, label: 'Phone', createdAt: '2026-08-01T00:00:00Z', lastUsedAt: null },
      ];
      let received: PasskeySummary[] | undefined;

      svc.list().subscribe((list) => (received = list));
      ctrl.expectOne('https://api.test/api/auth/passkeys').flush(listingBody({ passkeys }));
      await flushMicrotasks();

      expect(received).toEqual(passkeys);
    });
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

  // Without a hint, Chrome put the QR flow first and enrolment landed on a phone.
  describe('steering both ceremonies at this device', () => {
    it('asks enrolment for the passkey store on the machine the user is at', async () => {
      create.mockResolvedValue(fixtureAttestationCredential());

      const enrolment = svc.enrol('MacBook Touch ID');
      ctrl
        .expectOne('https://api.test/api/auth/passkey/register/options')
        .flush({ options: creationOptions, handle: 'register-handle' });

      await flushMicrotasks();

      expect(create.mock.calls[0][0].publicKey.hints).toEqual(['client-device']);

      ctrl.expectOne('https://api.test/api/auth/passkey/register').flush(null);
      await enrolment;
    });

    it('asks sign-in for the same, so a local passkey beats the QR flow', async () => {
      get.mockResolvedValue(fixtureAssertionCredential());

      const signIn = svc.signIn();
      ctrl
        .expectOne('https://api.test/api/auth/passkey/login/options')
        .flush({ options: requestOptions, handle: 'login-handle' });

      await flushMicrotasks();

      expect(get.mock.calls[0][0].publicKey.hints).toEqual(['client-device']);

      ctrl.expectOne('https://api.test/api/auth/passkey/login').flush({ token: 'jwt-abc' });
      await signIn;
    });
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

  describe('a login with a credential id the server does not know', () => {
    async function signInUpToLogin(): Promise<{ signIn: Promise<string>; login: TestRequest }> {
      get.mockResolvedValue(fixtureAssertionCredential());
      const signIn = svc.signIn();
      ctrl
        .expectOne('https://api.test/api/auth/passkey/login/options')
        .flush({ options: requestOptions, handle: 'login-handle' });
      await flushMicrotasks();
      return { signIn, login: ctrl.expectOne('https://api.test/api/auth/passkey/login') };
    }

    it('tells the browser to drop it on the unknown_passkey_credential type', async () => {
      const { unknown } = installSignalApi();
      const { signIn, login } = await signInUpToLogin();

      login.flush(
        { type: 'unknown_passkey_credential', title: 'Unknown passkey', status: 401 },
        { status: 401, statusText: 'Unauthorized' },
      );

      await expect(signIn).rejects.toMatchObject({ type: 'unknown_passkey_credential' });
      expect(unknown).toHaveBeenCalledWith({ rpId: 'test', credentialId: 'credential-id' });
    });

    // Every other 401 -- an expired challenge above all, likely under
    // conditional mediation -- names a WORKING passkey. Pruning it would be
    // worse than the orphan #727 exists for.
    it('leaves the browser alone on any other 401', async () => {
      const { unknown } = installSignalApi();
      const { signIn, login } = await signInUpToLogin();

      login.flush(
        { type: 'invalid_credentials', title: 'Invalid credentials', status: 401 },
        { status: 401, statusText: 'Unauthorized' },
      );

      await expect(signIn).rejects.toMatchObject({ type: 'invalid_credentials' });
      expect(unknown).not.toHaveBeenCalled();
    });

    // Conditional mediation is the path that keeps re-offering a dead entry,
    // so it matters most that it signals too.
    it('signals from the conditional ceremony as well', async () => {
      const { unknown } = installSignalApi();
      get.mockResolvedValue(fixtureAssertionCredential());

      const signIn = svc.signInConditionally(new AbortController().signal);
      ctrl
        .expectOne('https://api.test/api/auth/passkey/login/options')
        .flush({ options: requestOptions, handle: 'login-handle' });
      await flushMicrotasks();
      ctrl
        .expectOne('https://api.test/api/auth/passkey/login')
        .flush(
          { type: 'unknown_passkey_credential', title: 'Unknown passkey', status: 401 },
          { status: 401, statusText: 'Unauthorized' },
        );

      await expect(signIn).rejects.toMatchObject({ type: 'unknown_passkey_credential' });
      expect(unknown).toHaveBeenCalledWith({ rpId: 'test', credentialId: 'credential-id' });
    });

    it('still surfaces the Problem when the browser rejects the signal', async () => {
      const { unknown } = installSignalApi();
      unknown.mockRejectedValue(new Error('boom'));
      const { signIn, login } = await signInUpToLogin();

      login.flush(
        { type: 'unknown_passkey_credential', title: 'Unknown passkey', status: 401 },
        { status: 401, statusText: 'Unauthorized' },
      );

      await expect(signIn).rejects.toMatchObject({ status: 401, title: 'Unknown passkey' });
      expect(tokens.token()).toBeNull();
    });
  });
});
