// src/app/auth/login/login.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { parseProblem } from '../../core/problem';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';

@Component({
  selector: 'app-login',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
  ],
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss',
})
export class LoginComponent implements OnInit {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly router = inject(Router);

  readonly form = this.fb.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly providers = signal<string[]>([]);

  ngOnInit(): void {
    this.http.get<{ providers: string[] }>(`${this.base}/api/auth/oauth/providers`).subscribe({
      next: (r) => this.providers.set(r.providers ?? []),
      error: () => this.providers.set([]),
    });
  }

  submit(): void {
    if (this.loading()) return;

    // iOS autofill: DOM value may not be synced to the form control.
    for (const name of ['email', 'password'] as const) {
      const el = document.querySelector<HTMLInputElement>(`[name="${name}"]`);
      if (el && el.value !== this.form.get(name)?.value) {
        this.form.get(name)?.setValue(el.value);
      }
    }

    if (this.form.invalid) {
      this.error.set('Please enter a valid email and password.');
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    const { email, password } = this.form.getRawValue();
    this.auth.login(email, password).subscribe({
      next: () =>
        this.auth.loadMe().subscribe({
          next: () => void this.router.navigate(['/']),
          error: () => void this.router.navigate(['/']),
        }),
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e).detail ?? 'Sign in failed.');
        this.loading.set(false);
      },
    });
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
}
