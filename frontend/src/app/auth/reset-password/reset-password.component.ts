// src/app/auth/reset-password/reset-password.component.ts
import { Component, ElementRef, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { API_BASE_URL } from '../../core/api';
import { parseProblem } from '../../core/problem';
import { adoptAutofilledValues } from '../autofill';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';

@Component({
  selector: 'app-reset-password',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslocoPipe,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
  ],
  templateUrl: './reset-password.component.html',
  styleUrl: './reset-password.component.scss',
})
export class ResetPasswordComponent implements OnInit {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslocoService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

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
    if (this.loading()) return;
    adoptAutofilledValues(this.host.nativeElement, this.form);
    // Never return in silence: an unexplained no-op reads as a broken button.
    if (!token || this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('auth.reset.newInvalidInput'));
      return;
    }
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
          this.error.set(parseProblem(e).detail ?? this.i18n.translate('auth.reset.failed'));
          this.loading.set(false);
        },
      });
  }
}
