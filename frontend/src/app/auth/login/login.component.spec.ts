import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { PasskeyService } from '../../core/passkey.service';
import { LoginComponent } from './login.component';
import { SetupService } from '../../setup/setup.service';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('LoginComponent', () => {
  let ctrl: HttpTestingController;
  let navigate: jest.SpyInstance;

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [LoginComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  });

  function create() {
    const f = TestBed.createComponent(LoginComponent);
    f.detectChanges(); // triggers ngOnInit → providers GET
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: ['google'] });
    return f;
  }

  it('lists OAuth providers and builds provider URLs', () => {
    const f = create();
    expect(f.componentInstance.providers()).toEqual(['google']);
    expect(f.componentInstance.oauthUrl('google')).toBe('https://api.test/api/auth/oauth/google');
  });

  it('logs in, loads the user, and navigates home', () => {
    const f = create();
    f.componentInstance.form.setValue({ email: 'a@b.c', password: 'password12345' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/auth/login').flush({ token: 'jwt' });
    ctrl.expectOne('https://api.test/api/me').flush({
      id: 1,
      email: 'a@b.c',
      roles: [],
      status: 'active',
      createdAt: 'x',
      locale: 'de',
    });
    expect(navigate).toHaveBeenCalledWith(['/']);
    // Proves the account's locale, not the cached one, drives the UI after login.
    expect(document.documentElement.lang).toBe('de');
    expect(localStorage.getItem('sfr.lang')).toBe('de');
  });

  it('renders the problem detail on a failed login', () => {
    const f = create();
    f.componentInstance.form.setValue({ email: 'a@b.c', password: 'wrongpass1234' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/auth/login').flush(
      {
        type: 'invalid_credentials',
        title: 'x',
        status: 401,
        detail: 'Email address or password is incorrect.',
      },
      { status: 401, statusText: 'Unauthorized' },
    );
    expect(f.componentInstance.error()).toBe('Email address or password is incorrect.');
  });
});

describe('LoginComponent — forgot-password link visibility', () => {
  let ctrl: HttpTestingController;

  function create(mailEnabled: boolean | null) {
    TestBed.configureTestingModule({
      imports: [LoginComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SetupService, useValue: { mailEnabled: signal(mailEnabled) } },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    const f = TestBed.createComponent(LoginComponent);
    f.detectChanges();
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: [] });
    f.detectChanges();
    return f;
  }

  function resetLink(f: ReturnType<typeof create>) {
    const anchors = Array.from(
      (f.nativeElement as HTMLElement).querySelectorAll<HTMLAnchorElement>('a'),
    );
    return anchors.find((a) => a.getAttribute('routerLink') === '/reset-password-request');
  }

  it('hides the reset link when mail is disabled', () => {
    const f = create(false);
    expect(resetLink(f)).toBeUndefined();
  });

  it('shows the reset link when mail is enabled', () => {
    const f = create(true);
    expect(resetLink(f)).toBeDefined();
  });

  it('shows the reset link while mail capability is still unknown', () => {
    const f = create(null);
    expect(resetLink(f)).toBeDefined();
  });
});

describe('LoginComponent — passkey sign-in availability (#624 follow-up)', () => {
  let ctrl: HttpTestingController;

  /** jsdom carries neither `PublicKeyCredential` nor
   *  `isConditionalMediationAvailable`; leaving a stub behind would leak
   *  "supported" into sibling specs (`webauthn.spec.ts`'s own convention). */
  afterEach(() => {
    delete (window as unknown as { PublicKeyCredential?: unknown }).PublicKeyCredential;
  });

  function stubPasskeySupport(): void {
    (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {
      isConditionalMediationAvailable: jest.fn().mockResolvedValue(false),
    };
  }

  function create(passkeySignInAvailable: boolean | null) {
    TestBed.configureTestingModule({
      imports: [LoginComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        {
          provide: SetupService,
          useValue: {
            mailEnabled: signal(true),
            passkeySignInAvailable: signal(passkeySignInAvailable),
          },
        },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    const f = TestBed.createComponent(LoginComponent);
    f.detectChanges();
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: [] });
    f.detectChanges();
    return f;
  }

  function passkeyButton(f: ReturnType<typeof create>): HTMLButtonElement | null {
    return (f.nativeElement as HTMLElement).querySelector('[data-test="passkey-login"]');
  }

  it('hides the passkey button once the instance reports it unavailable', () => {
    stubPasskeySupport();
    const f = create(false);
    expect(passkeyButton(f)).toBeNull();
  });

  it('shows the passkey button once the instance reports it available', () => {
    stubPasskeySupport();
    const f = create(true);
    expect(passkeyButton(f)).not.toBeNull();
  });

  /** Fails open while the flag is in flight, mirroring mailEnabled's `!== false`
   *  convention here: setupRedirectGuard usually resolves it before render, so
   *  the unknown state is transient in practice. */
  it('shows the passkey button while availability is still unknown', () => {
    stubPasskeySupport();
    const f = create(null);
    expect(passkeyButton(f)).not.toBeNull();
  });
});

interface PasskeyServiceStub {
  signIn: jest.Mock;
  signInConditionally: jest.Mock;
}

describe('LoginComponent — passkey login', () => {
  let ctrl: HttpTestingController;
  let navigate: jest.SpyInstance;
  let passkeyService: PasskeyServiceStub;

  /** jsdom carries neither `PublicKeyCredential` nor
   *  `isConditionalMediationAvailable`; leaving a stub behind would leak
   *  "supported" into sibling specs (`webauthn.spec.ts`'s own convention). */
  afterEach(() => {
    delete (window as unknown as { PublicKeyCredential?: unknown }).PublicKeyCredential;
  });

  function stubPasskeySupport(conditionalMediationAvailable: boolean): void {
    (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {
      isConditionalMediationAvailable: jest.fn().mockResolvedValue(conditionalMediationAvailable),
    };
  }

  beforeEach(async () => {
    localStorage.clear();
    passkeyService = {
      signIn: jest.fn(),
      // Never resolves unless a test overrides it: standing in for a live
      // ceremony nothing has settled yet, the same way the dialog specs in
      // passkeys-group.component.spec.ts use an un-emitting Subject.
      signInConditionally: jest.fn(() => new Promise(() => undefined)),
    };
    await TestBed.configureTestingModule({
      imports: [LoginComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: PasskeyService, useValue: passkeyService },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
    navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  });

  function create() {
    const f = TestBed.createComponent(LoginComponent);
    f.detectChanges(); // triggers ngOnInit → providers GET
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: [] });
    f.detectChanges();
    return f;
  }

  function passkeyButton(f: ReturnType<typeof create>): HTMLButtonElement | null {
    return (f.nativeElement as HTMLElement).querySelector('[data-test="passkey-login"]');
  }

  async function flushMicrotasks(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
  }

  function flushSuccessfulLogin(): void {
    ctrl.expectOne('https://api.test/api/me').flush({
      id: 1,
      email: 'a@b.c',
      roles: [],
      status: 'active',
      createdAt: 'x',
      locale: 'en',
    });
  }

  it('hides the passkey button when the browser has no WebAuthn support', () => {
    const f = create();
    expect(passkeyButton(f)).toBeNull();
  });

  it('gives the e-mail field the webauthn autocomplete token, unconditionally', () => {
    const f = create();
    const email = (f.nativeElement as HTMLElement).querySelector(
      'input[formControlName="email"]',
    ) as HTMLInputElement;
    expect(email.getAttribute('autocomplete')).toBe('username webauthn');
  });

  it('shows the passkey button, signs in, and navigates exactly where password login does', async () => {
    stubPasskeySupport(false);
    passkeyService.signIn.mockResolvedValue('jwt');
    const f = create();
    f.detectChanges();

    const button = passkeyButton(f);
    expect(button).not.toBeNull();
    button?.click();
    await flushMicrotasks();
    f.detectChanges();

    flushSuccessfulLogin();
    expect(navigate).toHaveBeenCalledWith(['/']);
  });

  it('renders a passkey sign-in failure through app-form-error', async () => {
    stubPasskeySupport(false);
    passkeyService.signIn.mockRejectedValue({
      type: 'unreachable',
      title: 'x',
      status: 0,
      detail: 'The passkey server could not be reached.',
    });
    const f = create();
    f.detectChanges();

    passkeyButton(f)?.click();
    await flushMicrotasks();
    f.detectChanges();

    const banner = (f.nativeElement as HTMLElement).querySelector('.err');
    expect(banner?.textContent).toContain('The passkey server could not be reached.');
  });

  it('requests conditional mediation when the browser reports it is available', async () => {
    stubPasskeySupport(true);
    create();
    await flushMicrotasks();

    expect(passkeyService.signInConditionally).toHaveBeenCalledTimes(1);
  });

  it('does not request conditional mediation when the browser reports it is unavailable', async () => {
    stubPasskeySupport(false);
    create();
    await flushMicrotasks();

    expect(passkeyService.signInConditionally).not.toHaveBeenCalled();
  });

  it('aborts the conditional request when the password form submits, and the resulting AbortError renders no banner', async () => {
    stubPasskeySupport(true);
    let capturedSignal: AbortSignal | undefined;
    let rejectConditional!: (error: unknown) => void;
    passkeyService.signInConditionally.mockImplementation((signal: AbortSignal) => {
      capturedSignal = signal;
      return new Promise((_, reject) => {
        rejectConditional = reject;
      });
    });
    const f = create();
    await flushMicrotasks();
    expect(passkeyService.signInConditionally).toHaveBeenCalledTimes(1);
    expect(capturedSignal?.aborted).toBe(false);

    f.componentInstance.form.setValue({ email: 'a@b.c', password: 'password12345' });
    f.componentInstance.submit();

    expect(capturedSignal?.aborted).toBe(true);

    // The abort this component triggers surfaces as an AbortError, just like
    // a real aborted navigator.credentials.get() (see toProblem()'s docblock)
    // -- it must not flash an error on ordinary password sign-in.
    rejectConditional({ type: 'AbortError', title: 'The operation was aborted.', status: 0 });
    await flushMicrotasks();
    f.detectChanges();
    expect(f.componentInstance.error()).toBeNull();
    expect((f.nativeElement as HTMLElement).querySelector('.err')).toBeNull();

    ctrl.expectOne('https://api.test/api/auth/login').flush({ token: 'jwt' });
    flushSuccessfulLogin();
    expect(navigate).toHaveBeenCalledWith(['/']);
  });

  it('renders no banner for a NotAllowedError from the conditional ceremony (the user dismissed it)', async () => {
    stubPasskeySupport(true);
    passkeyService.signInConditionally.mockRejectedValue({
      type: 'NotAllowedError',
      title: 'The operation either timed out or was not allowed.',
      status: 0,
    });
    const f = create();
    await flushMicrotasks();
    f.detectChanges();

    expect(f.componentInstance.error()).toBeNull();
  });

  it('renders no banner for a rate-limit failure from the conditional ceremony (finding 7: a background ceremony must fail silently)', async () => {
    // The passkey_challenge limiter allows 30/15min; the 31st visitor to
    // merely load the page would see a 429. Unlike the NotAllowedError/
    // AbortError specs above, this proves the silence isn't error-type-keyed.
    stubPasskeySupport(true);
    passkeyService.signInConditionally.mockRejectedValue({
      type: 'about:blank',
      title: 'Too Many Requests',
      status: 429,
      detail: 'Too many attempts. Try again later.',
    });
    const f = create();
    await flushMicrotasks();
    f.detectChanges();

    expect(f.componentInstance.error()).toBeNull();
    expect((f.nativeElement as HTMLElement).querySelector('.err')).toBeNull();
  });

  it('aborts the conditional request on destroy', async () => {
    stubPasskeySupport(true);
    let capturedSignal: AbortSignal | undefined;
    passkeyService.signInConditionally.mockImplementation((signal: AbortSignal) => {
      capturedSignal = signal;
      return new Promise(() => undefined);
    });
    const f = create();
    await flushMicrotasks();

    f.destroy();

    expect(capturedSignal?.aborted).toBe(true);
  });
});
