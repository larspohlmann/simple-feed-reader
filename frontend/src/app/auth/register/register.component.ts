// src/app/auth/register/register.component.ts
import { Component, inject, signal } from '@angular/core';
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
      this.error.set(firstFieldError ?? p.detail ?? this.i18n.translate('auth.register.failed'));
    } finally {
      this.loading.set(false);
    }
  }
}
