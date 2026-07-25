// src/app/auth/register/register.component.ts
import { Component, ElementRef, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { API_BASE_URL } from '../../core/api';
import { parseProblem } from '../../core/problem';
import { AltchaService } from '../altcha.service';
import { solveAltcha } from '../altcha';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';

@Component({
  selector: 'app-register',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslocoPipe,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
  ],
  templateUrl: './register.component.html',
  styleUrl: './register.component.scss',
})
export class RegisterComponent {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly altcha = inject(AltchaService);
  private readonly i18n = inject(TranslocoService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(12)]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly done = signal(false);

  /** Copy what is actually in the inputs into the form model.
   *
   *  Angular binds to the `input` event. A password manager filling both fields
   *  does not always dispatch one -- iOS is the reliable offender -- so the user
   *  sees their credentials on screen while the form model is still empty, the
   *  form reads as invalid, and submitting does nothing. Reading the DOM once,
   *  at submit, closes that gap whatever mechanism did the filling.
   */
  private adoptAutofilledValues(): void {
    const host = this.host.nativeElement;
    const email = host.querySelector<HTMLInputElement>('input[type="email"]');
    const password = host.querySelector<HTMLInputElement>('input[type="password"]');
    if (email && email.value !== this.form.controls.email.value) {
      this.form.controls.email.setValue(email.value);
    }
    if (password && password.value !== this.form.controls.password.value) {
      this.form.controls.password.setValue(password.value);
    }
  }

  async submit(): Promise<void> {
    if (this.loading()) return;
    this.adoptAutofilledValues();
    // Say why nothing happened. Returning silently here makes the button look
    // broken rather than refused -- and it is not a theoretical case: a password
    // manager can fill both fields without dispatching the event Angular binds
    // to, so the form reads as empty while the user is looking at their own
    // credentials on screen. Reported from an iPhone as "the button does
    // nothing, not even a spinner", which is exactly what this produced.
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('auth.register.invalidInput'));
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    try {
      const challenge = await firstValueFrom(this.altcha.challenge());
      const solution = await solveAltcha(challenge);
      const { email, password } = this.form.getRawValue();
      await firstValueFrom(
        this.http.post(`${this.base}/api/auth/register`, {
          email,
          password,
          altcha: solution,
          // Tell the backend which language to send this account's emails in.
          locale: this.i18n.getActiveLang(),
        }),
      );
      this.done.set(true);
    } catch (e) {
      const p = parseProblem(e as HttpErrorResponse);
      const firstFieldError = p.errors ? Object.values(p.errors)[0]?.[0] : undefined;
      this.error.set(firstFieldError ?? p.detail ?? this.i18n.translate('auth.register.failed'));
    } finally {
      this.loading.set(false);
    }
  }
}
