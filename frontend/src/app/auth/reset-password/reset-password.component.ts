// src/app/auth/reset-password/reset-password.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { parseProblem } from '../../core/problem';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';

@Component({
  selector: 'app-reset-password',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
  ],
  template: `
    <app-auth-shell title="Set a new password">
      @if (token()) {
        <form (ngSubmit)="submit()" [formGroup]="form">
          <label class="field">
            <span>New password (at least 12 characters)</span>
            <input type="password" formControlName="password" autocomplete="new-password" />
          </label>
          <app-form-error [message]="error()" />
          <app-button type="submit" variant="primary" [loading]="loading()"
            >Save password</app-button
          >
        </form>
      } @else {
        <p class="err">This reset link is invalid or has expired.</p>
        <a routerLink="/reset-password-request">Request a new link</a>
      }
    </app-auth-shell>
  `,
  styles: [
    `
      .err {
        color: var(--danger);
        margin-bottom: var(--space-4);
      }
    `,
  ],
})
export class ResetPasswordComponent implements OnInit {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly token = signal<string | null>(null);
  readonly form = this.fb.group({
    password: ['', [Validators.required, Validators.minLength(12)]],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  ngOnInit(): void {
    this.route.queryParamMap.subscribe((p) => this.token.set(p.get('token')));
  }

  submit(): void {
    const token = this.token();
    if (!token || this.form.invalid || this.loading()) return;
    this.loading.set(true);
    this.error.set(null);
    this.http
      .post(`${this.base}/api/auth/password-reset`, {
        token,
        password: this.form.getRawValue().password,
      })
      .subscribe({
        next: () => void this.router.navigate(['/login'], { queryParams: { reset: '1' } }),
        error: (e: HttpErrorResponse) => {
          this.error.set(
            parseProblem(e).detail ?? 'Could not reset the password. Request a new link.',
          );
          this.loading.set(false);
        },
      });
  }
}
