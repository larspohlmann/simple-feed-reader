import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { PasskeyService } from '../../core/auth/passkey.service';
import { ReaderLocationService } from '../../core/auth/reader-location.service';
import { LoginComponent } from './login.component';
import { SetupService } from '../../core/setup/setup.service';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('LoginComponent', () => {
  let ctrl: HttpTestingController;
  let navigateByUrl: jest.SpyInstance;

  beforeEach(async () => {
    localStorage.clear();
    sessionStorage.clear();
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
    navigateByUrl = jest.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
  });

  function create() {
    const fixture = TestBed.createComponent(LoginComponent);
    fixture.detectChanges(); // triggers ngOnInit → providers GET
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: ['google'] });
    return fixture;
  }

  it('lists OAuth providers and builds provider URLs', () => {
    const fixture = create();
    expect(fixture.componentInstance.providers()).toEqual(['google']);
    expect(fixture.componentInstance.oauthUrl('google')).toBe(
      'https://api.test/api/auth/oauth/google',
    );
  });

  it('restores the pending reader destination after password sign-in', () => {
    TestBed.inject(ReaderLocationService).rememberAttemptedReaderUrl('/?tag=17&entry=42-example');
    const fixture = create();
    fixture.componentInstance.form.setValue({ email: 'a@b.c', password: 'password12345' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/auth/login').flush({ token: 'jwt' });
    ctrl.expectOne('https://api.test/api/me').flush({
      id: 1,
      email: 'a@b.c',
      roles: [],
      status: 'active',
      createdAt: 'x',
      locale: 'de',
    });
    expect(navigateByUrl).toHaveBeenCalledWith('/?tag=17&entry=42-example');
    expect(TestBed.inject(ReaderLocationService).consumeSignInReturnUrl()).toBe('/');
    // Proves the account's locale, not the cached one, drives the UI after login.
    expect(document.documentElement.lang).toBe('de');
    expect(localStorage.getItem('sfr.lang')).toBe('de');
  });

  it('falls back to the reader root after loadMe fails following password sign-in', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ email: 'a@b.c', password: 'password12345' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/auth/login').flush({ token: 'jwt' });
    ctrl
      .expectOne('https://api.test/api/me')
      .flush(
        { type: 'unavailable', title: 'Unavailable', status: 503 },
        { status: 503, statusText: 'Unavailable' },
      );

    expect(navigateByUrl).toHaveBeenCalledWith('/');
  });

  it('renders the problem detail on a failed login', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ email: 'a@b.c', password: 'wrongpass1234' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/auth/login').flush(
      {
        type: 'invalid_credentials',
        title: 'x',
        status: 401,
        detail: 'Email address or password is incorrect.',
      },
      { status: 401, statusText: 'Unauthorized' },
    );
    expect(fixture.componentInstance.error()).toBe('Email address or password is incorrect.');
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
    const fixture = TestBed.createComponent(LoginComponent);
    fixture.detectChanges();
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: [] });
    fixture.detectChanges();
    return fixture;
  }

  function resetLink(fixture: ReturnType<typeof create>) {
    const anchors = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLAnchorElement>('a'),
    );
    return anchors.find(
      (anchor) => anchor.getAttribute('routerLink') === '/reset-password-request',
    );
  }

  it('hides the reset link when mail is disabled', () => {
    const fixture = create(false);
    expect(resetLink(fixture)).toBeUndefined();
  });

  it('shows the reset link when mail is enabled', () => {
    const fixture = create(true);
    expect(resetLink(fixture)).toBeDefined();
  });

  it('shows the reset link while mail capability is still unknown', () => {
    const fixture = create(null);
    expect(resetLink(fixture)).toBeDefined();
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
    const fixture = TestBed.createComponent(LoginComponent);
    fixture.detectChanges();
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: [] });
    fixture.detectChanges();
    return fixture;
  }

  function passkeyButton(fixture: ReturnType<typeof create>): HTMLButtonElement | null {
    return (fixture.nativeElement as HTMLElement).querySelector('[data-test="passkey-login"]');
  }

  it('hides the passkey button once the instance reports it unavailable', () => {
    stubPasskeySupport();
    const fixture = create(false);
    expect(passkeyButton(fixture)).toBeNull();
  });

  it('shows the passkey button once the instance reports it available', () => {
    stubPasskeySupport();
    const fixture = create(true);
    expect(passkeyButton(fixture)).not.toBeNull();
  });

  /** Fails open while the flag is in flight, mirroring mailEnabled's `!== false`
   *  convention here: setupRedirectGuard usually resolves it before render, so
   *  the unknown state is transient in practice. */
  it('shows the passkey button while availability is still unknown', () => {
    stubPasskeySupport();
    const fixture = create(null);
    expect(passkeyButton(fixture)).not.toBeNull();
  });
});

interface PasskeyServiceStub {
  signIn: jest.Mock;
  signInConditionally: jest.Mock;
}

describe('LoginComponent — passkey login', () => {
  let ctrl: HttpTestingController;
  let navigateByUrl: jest.SpyInstance;
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
    sessionStorage.clear();
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
    navigateByUrl = jest.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
  });

  function create() {
    const fixture = TestBed.createComponent(LoginComponent);
    fixture.detectChanges(); // triggers ngOnInit → providers GET
    ctrl.expectOne('https://api.test/api/auth/oauth/providers').flush({ providers: [] });
    fixture.detectChanges();
    return fixture;
  }

  function passkeyButton(fixture: ReturnType<typeof create>): HTMLButtonElement | null {
    return (fixture.nativeElement as HTMLElement).querySelector('[data-test="passkey-login"]');
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
    const fixture = create();
    expect(passkeyButton(fixture)).toBeNull();
  });

  it('gives the e-mail field the webauthn autocomplete token, unconditionally', () => {
    const fixture = create();
    const email = (fixture.nativeElement as HTMLElement).querySelector(
      'input[formControlName="email"]',
    ) as HTMLInputElement;
    expect(email.getAttribute('autocomplete')).toBe('username webauthn');
  });

  it('restores the pending reader destination after explicit passkey sign-in', async () => {
    stubPasskeySupport(false);
    passkeyService.signIn.mockResolvedValue('jwt');
    TestBed.inject(ReaderLocationService).rememberAttemptedReaderUrl('/?tag=17&entry=42-example');
    const fixture = create();
    fixture.detectChanges();

    const button = passkeyButton(fixture);
    expect(button).not.toBeNull();
    button?.click();
    await flushMicrotasks();
    fixture.detectChanges();

    flushSuccessfulLogin();
    expect(navigateByUrl).toHaveBeenCalledWith('/?tag=17&entry=42-example');
  });

  it('renders a passkey sign-in failure through app-form-error', async () => {
    stubPasskeySupport(false);
    passkeyService.signIn.mockRejectedValue({
      type: 'unreachable',
      title: 'x',
      status: 0,
      detail: 'The passkey server could not be reached.',
    });
    const fixture = create();
    fixture.detectChanges();

    passkeyButton(fixture)?.click();
    await flushMicrotasks();
    fixture.detectChanges();

    const banner = (fixture.nativeElement as HTMLElement).querySelector('.err');
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
    const fixture = create();
    await flushMicrotasks();
    expect(passkeyService.signInConditionally).toHaveBeenCalledTimes(1);
    expect(capturedSignal?.aborted).toBe(false);

    fixture.componentInstance.form.setValue({ email: 'a@b.c', password: 'password12345' });
    fixture.componentInstance.submit();

    expect(capturedSignal?.aborted).toBe(true);

    // The abort this component triggers surfaces as an AbortError, just like
    // a real aborted navigator.credentials.get() (see toProblem()'s docblock)
    // -- it must not flash an error on ordinary password sign-in.
    rejectConditional({ type: 'AbortError', title: 'The operation was aborted.', status: 0 });
    await flushMicrotasks();
    fixture.detectChanges();
    expect(fixture.componentInstance.error()).toBeNull();
    expect((fixture.nativeElement as HTMLElement).querySelector('.err')).toBeNull();

    ctrl.expectOne('https://api.test/api/auth/login').flush({ token: 'jwt' });
    flushSuccessfulLogin();
    expect(navigateByUrl).toHaveBeenCalledWith('/');
  });

  it('restores the pending reader destination after conditional passkey sign-in', async () => {
    stubPasskeySupport(true);
    passkeyService.signInConditionally.mockResolvedValue('jwt');
    TestBed.inject(ReaderLocationService).rememberAttemptedReaderUrl('/?tag=17&entry=42-example');

    create();
    await flushMicrotasks();

    flushSuccessfulLogin();

    expect(navigateByUrl).toHaveBeenCalledWith('/?tag=17&entry=42-example');
  });

  it('renders no banner for a NotAllowedError from the conditional ceremony (the user dismissed it)', async () => {
    stubPasskeySupport(true);
    passkeyService.signInConditionally.mockRejectedValue({
      type: 'NotAllowedError',
      title: 'The operation either timed out or was not allowed.',
      status: 0,
    });
    const fixture = create();
    await flushMicrotasks();
    fixture.detectChanges();

    expect(fixture.componentInstance.error()).toBeNull();
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
    const fixture = create();
    await flushMicrotasks();
    fixture.detectChanges();

    expect(fixture.componentInstance.error()).toBeNull();
    expect((fixture.nativeElement as HTMLElement).querySelector('.err')).toBeNull();
  });

  it('aborts the conditional request on destroy', async () => {
    stubPasskeySupport(true);
    let capturedSignal: AbortSignal | undefined;
    passkeyService.signInConditionally.mockImplementation((signal: AbortSignal) => {
      capturedSignal = signal;
      return new Promise(() => undefined);
    });
    const fixture = create();
    await flushMicrotasks();

    fixture.destroy();

    expect(capturedSignal?.aborted).toBe(true);
  });
});
