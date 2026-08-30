// src/app/auth/login/login.component.ts
import { Component, ElementRef, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { PasskeyService } from '../../core/passkey.service';
import { Problem, parseProblem } from '../../core/problem';
import { isConditionalMediationSupported, isPasskeySupported } from '../../core/webauthn';
import { adoptAutofilledValues } from '../autofill';
import { SetupService } from '../../setup/setup.service';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';
import { FieldComponent } from '../../shared/field/field.component';
import { PasswordInputComponent } from '../../shared/password-input/password-input.component';

@Component({
  selector: 'app-login',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslocoPipe,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
    FieldComponent,
    PasswordInputComponent,
  ],
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss',
})
export class LoginComponent implements OnInit, OnDestroy {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslocoService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly setup = inject(SetupService);
  private readonly passkeyService = inject(PasskeyService);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly providers = signal<string[]>([]);
  readonly mailEnabled = this.setup.mailEnabled;

  /** Read once: a browser does not gain or lose WebAuthn support mid-session,
   *  the same reasoning `PasskeysGroupComponent.isSupported` documents. */
  protected readonly passkeySupported = isPasskeySupported();

  /**
   * Whether passkey sign-in belongs on this page at all (#624 follow-up):
   * the browser has to understand WebAuthn AND this instance has to be able
   * to complete the ceremony -- toggled on, with a valid relying party. The
   * second half comes from `SetupService.passkeySignInAvailable`, fetched
   * alongside `mailEnabled` by the same `/api/setup/status` call.
   *
   * `!== false` fails OPEN (fix round 1, sharpened): NOT because "unknown"
   * is merely rare here, but because `setupRedirectGuard` (`setup.guard.ts`)
   * always resolves `ensureLoaded()` -- and so this signal -- BEFORE the
   * login route is allowed to activate. By the time this component exists,
   * the value is either `true`, `false`, or `null` because the status call
   * itself already failed (the guard's own `catchError` lets navigation
   * through regardless). So `null` here is never "still loading" -- it is
   * specifically "the server didn't answer", the same failure `mailEnabled`
   * already treats as fail-open just below, for the same reason: showing a
   * button that then 403s on click is no worse than the network failure the
   * visitor is already having, and hiding a real feature because one status
   * call hiccuped would be worse.
   */
  protected readonly passkeyOfferedHere = computed(
    () => this.passkeySupported && this.setup.passkeySignInAvailable() !== false,
  );

  /** Held so the conditional ceremony started in `ngOnInit` can be cancelled
   *  from `submit()` and `ngOnDestroy()` -- two live `navigator.credentials`
   *  calls compete and the browser rejects one (#624 task 15, trap 2). */
  private conditionalAbort: AbortController | null = null;

  ngOnInit(): void {
    this.http.get<{ providers: string[] }>(`${this.base}/api/auth/oauth/providers`).subscribe({
      next: (r) => this.providers.set(r.providers ?? []),
      error: () => this.providers.set([]),
    });

    if (this.passkeyOfferedHere()) {
      void this.offerConditionalPasskey();
    }
  }

  ngOnDestroy(): void {
    this.abortConditionalPasskey();
  }

  submit(): void {
    if (this.loading()) return;
    // A conditional passkey ceremony may still be pending on the e-mail
    // field's autofill; a password submit means the visitor chose the other
    // path, and the browser will not run both at once.
    this.abortConditionalPasskey();
    // This form already carried a hand-rolled version of this, keyed on the
    // `name` attribute and querying the whole document. It is now the shared
    // helper: scoped to this component, and keyed on formControlName, which is
    // the attribute actually guaranteed to be there. Generalising it is the
    // point -- the register form had the identical bug and no workaround, which
    // is how a filled-in form came to refuse to submit with nothing on screen.
    adoptAutofilledValues(this.host.nativeElement, this.form);

    if (this.form.invalid) {
      this.error.set(this.i18n.translate('auth.login.invalidInput'));
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    const { email, password } = this.form.getRawValue();
    this.auth.login(email, password).subscribe({
      next: () => this.afterSignIn(),
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e).detail ?? this.i18n.translate('auth.login.failed'));
        this.loading.set(false);
      },
    });
  }

  /** The explicit "Sign in with a passkey" button. Distinct from the
   *  conditional ceremony `ngOnInit` starts: this one is user-initiated, so
   *  a cancelled sheet (`NotAllowedError`) still has to clear `loading`. */
  signInWithPasskey(): void {
    if (this.loading()) return;
    this.abortConditionalPasskey();
    this.loading.set(true);
    this.error.set(null);
    this.passkeyService.signIn().then(
      () => this.afterSignIn(),
      (problem: Problem) => {
        this.loading.set(false);
        this.showPasskeyFailure(problem);
      },
    );
  }

  oauthUrl(provider: string): string {
    return `${this.base}/api/auth/oauth/${provider}`;
  }

  startOAuth(provider: string): void {
    location.assign(this.oauthUrl(provider));
  }

  label(provider: string): string {
    return provider.charAt(0).toUpperCase() + provider.slice(1);
  }

  /** Offers the passkey through the e-mail field's autofill the moment the
   *  browser can (`mediation: 'conditional'`); a no-op wherever it can't,
   *  per `isConditionalMediationSupported()`'s own contract. Runs for the
   *  page's whole lifetime, so `submit()` and `ngOnDestroy()` both have to be
   *  able to cut it short.
   *
   *  A failure here is deliberately silent (#624 finding 7), not routed
   *  through `showPasskeyFailure()`: this ceremony starts on its own the
   *  moment the page loads, before the visitor has done anything, so any
   *  rejection -- including a 429 from the shared `passkey_challenge`
   *  limiter, which a page view alone can trigger for the 31st visitor in its
   *  window -- must not paint an error banner on a page nobody interacted
   *  with. Only the explicit "Sign in with a passkey" button may surface a
   *  failure to the visitor. */
  private async offerConditionalPasskey(): Promise<void> {
    if (!(await isConditionalMediationSupported())) return;

    this.conditionalAbort = new AbortController();
    try {
      await this.passkeyService.signInConditionally(this.conditionalAbort.signal);
      this.afterSignIn();
    } catch {
      // Silent by design -- see the docblock above.
    }
  }

  private abortConditionalPasskey(): void {
    this.conditionalAbort?.abort();
    this.conditionalAbort = null;
  }

  /** Shared by password, explicit-passkey and conditional-passkey sign-in --
   *  wherever the credential came from, the app lands the visitor in exactly
   *  the same place. `TokenStore` is already populated by this point:
   *  `AuthService.login()` and `PasskeyService`'s ceremonies both set it
   *  themselves before resolving. */
  private afterSignIn(): void {
    this.auth.loadMe().subscribe({
      next: () => void this.router.navigate(['/']),
      error: () => void this.router.navigate(['/']),
    });
  }

  /** Only `signInWithPasskey()` -- the explicit button -- calls this; the
   *  background conditional ceremony never does (#624 finding 7, see
   *  `offerConditionalPasskey()`'s docblock). A cancelled sheet
   *  (`NotAllowedError`) is not a failure to report; anything else renders
   *  through the same `app-form-error` a failed password sign-in does. */
  private showPasskeyFailure(problem: Problem): void {
    if (problem.type === 'NotAllowedError') return;
    this.error.set(problem.detail ?? this.i18n.translate('auth.login.passkeyFailed'));
  }
}
