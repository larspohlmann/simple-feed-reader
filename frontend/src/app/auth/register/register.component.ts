// src/app/auth/register/register.component.ts
import { Component, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
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
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
  ],
  template: `
    <app-auth-shell title="Create account" subtitle="Register, then confirm your email.">
      @if (done()) {
        <p class="ok">
          Check your email for a confirmation link. After you confirm, an administrator reviews your
          account before you can sign in.
        </p>
        <a routerLink="/login">Back to sign in</a>
      } @else {
        <form (ngSubmit)="submit()" [formGroup]="form">
          <label class="field">
            <span>Email</span>
            <input type="email" formControlName="email" autocomplete="email" />
          </label>
          <label class="field">
            <span>Password (at least 12 characters)</span>
            <input type="password" formControlName="password" autocomplete="new-password" />
          </label>
          <app-form-error [message]="error()" />
          <app-button type="submit" variant="primary" [loading]="loading()"
            >Create account</app-button
          >
        </form>
        <p class="links"><a routerLink="/login">Already have an account?</a></p>
      }
    </app-auth-shell>
  `,
  styles: [
    `
      .ok {
        color: var(--text-secondary);
        margin-bottom: var(--space-4);
      }
      .links {
        margin-top: var(--space-5);
        font-size: var(--fs-sm);
      }
    `,
  ],
})
export class RegisterComponent {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly altcha = inject(AltchaService);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(12)]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly done = signal(false);

  async submit(): Promise<void> {
    if (this.form.invalid || this.loading()) return;
    this.loading.set(true);
    this.error.set(null);
    try {
      const challenge = await firstValueFrom(this.altcha.challenge());
      const solution = await solveAltcha(challenge);
      const { email, password } = this.form.getRawValue();
      await firstValueFrom(
        this.http.post(`${this.base}/api/auth/register`, { email, password, altcha: solution }),
      );
      this.done.set(true);
    } catch (e) {
      const p = parseProblem(e as HttpErrorResponse);
      const firstFieldError = p.errors ? Object.values(p.errors)[0]?.[0] : undefined;
      this.error.set(firstFieldError ?? p.detail ?? 'Registration failed. Try again.');
    } finally {
      this.loading.set(false);
    }
  }
}
