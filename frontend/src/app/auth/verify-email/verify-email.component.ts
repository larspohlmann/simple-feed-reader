// src/app/auth/verify-email/verify-email.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';

@Component({
  selector: 'app-verify-email',
  imports: [RouterLink, AuthShellComponent, SpinnerComponent],
  template: `
    <app-auth-shell title="Confirm email">
      @switch (state()) {
        @case ('loading') {
          <app-spinner />
        }
        @case ('ok') {
          <p>
            Your email is confirmed. An administrator will review your account before you can sign
            in.
          </p>
          <a routerLink="/login">Back to sign in</a>
        }
        @case ('error') {
          <p class="err">This confirmation link is invalid or has expired.</p>
          <a routerLink="/login">Back to sign in</a>
        }
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
export class VerifyEmailComponent implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly route = inject(ActivatedRoute);
  readonly state = signal<'loading' | 'ok' | 'error'>('loading');

  ngOnInit(): void {
    this.route.queryParamMap.subscribe((params) => {
      const token = params.get('token');
      if (!token) {
        this.state.set('error');
        return;
      }
      this.http.post(`${this.base}/api/auth/verify-email`, { token }).subscribe({
        next: () => this.state.set('ok'),
        error: () => this.state.set('error'),
      });
    });
  }
}
