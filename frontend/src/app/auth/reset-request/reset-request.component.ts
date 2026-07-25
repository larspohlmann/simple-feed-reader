// src/app/auth/reset-request/reset-request.component.ts
import { Component, ElementRef, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { API_BASE_URL } from '../../core/api';
import { AltchaService } from '../altcha.service';
import { adoptAutofilledValues } from '../autofill';
import { solveAltcha } from '../altcha';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { FormErrorComponent } from '../../shared/form-error/form-error.component';

@Component({
  selector: 'app-reset-request',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslocoPipe,
    AuthShellComponent,
    ButtonComponent,
    FormErrorComponent,
  ],
  templateUrl: './reset-request.component.html',
  styleUrl: './reset-request.component.scss',
})
export class ResetRequestComponent {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly altcha = inject(AltchaService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly i18n = inject(TranslocoService);

  readonly form = this.fb.group({ email: ['', [Validators.required, Validators.email]] });
  readonly loading = signal(false);
  readonly done = signal(false);
  /** Only ever holds a client-side validation message. The request itself stays
   *  deliberately neutral -- see the catch below -- so nothing here can leak
   *  whether an address exists. */
  readonly error = signal<string | null>(null);

  async submit(): Promise<void> {
    if (this.loading()) return;
    adoptAutofilledValues(this.host.nativeElement, this.form);
    // Never return in silence: an unexplained no-op reads as a broken button.
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.i18n.translate('auth.reset.requestInvalidInput'));
      return;
    }
    this.error.set(null);
    this.loading.set(true);
    try {
      const challenge = await firstValueFrom(this.altcha.challenge());
      const solution = await solveAltcha(challenge);
      await firstValueFrom(
        this.http.post(`${this.base}/api/auth/password-reset-request`, {
          email: this.form.getRawValue().email,
          altcha: solution,
        }),
      );
    } catch {
      // Neutral by design: never reveal whether the address exists or the call failed.
    } finally {
      this.done.set(true);
      this.loading.set(false);
    }
  }
}
