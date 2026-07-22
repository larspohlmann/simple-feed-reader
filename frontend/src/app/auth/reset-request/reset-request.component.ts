// src/app/auth/reset-request/reset-request.component.ts
import { Component, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { AltchaService } from '../altcha.service';
import { solveAltcha } from '../altcha';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { ButtonComponent } from '../../shared/button/button.component';

@Component({
  selector: 'app-reset-request',
  imports: [ReactiveFormsModule, RouterLink, AuthShellComponent, ButtonComponent],
  template: `
    <app-auth-shell title="Reset password" subtitle="We’ll email you a reset link.">
      @if (done()) {
        <p class="ok">
          If that address has an account, a reset link is on its way. The link is valid for 24
          hours.
        </p>
        <a routerLink="/login">Back to sign in</a>
      } @else {
        <form (ngSubmit)="submit()" [formGroup]="form">
          <label class="field">
            <span>Email</span>
            <input type="email" formControlName="email" autocomplete="email" />
          </label>
          <app-button type="submit" variant="primary" [loading]="loading()"
            >Send reset link</app-button
          >
        </form>
        <p class="links"><a routerLink="/login">Back to sign in</a></p>
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
export class ResetRequestComponent {
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly altcha = inject(AltchaService);

  readonly form = this.fb.group({ email: ['', [Validators.required, Validators.email]] });
  readonly loading = signal(false);
  readonly done = signal(false);

  async submit(): Promise<void> {
    if (this.form.invalid || this.loading()) return;
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
