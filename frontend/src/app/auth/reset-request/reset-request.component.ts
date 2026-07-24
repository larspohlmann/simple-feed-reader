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
  templateUrl: './reset-request.component.html',
  styleUrl: './reset-request.component.scss',
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
