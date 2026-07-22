// src/app/auth/oauth-callback/oauth-callback.component.ts
import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { TokenStore } from '../../core/token.store';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';

@Component({
  selector: 'app-oauth-callback',
  imports: [RouterLink, AuthShellComponent, SpinnerComponent],
  template: `
    <app-auth-shell title="Signing you in">
      @switch (state()) {
        @case ('loading') {
          <app-spinner />
        }
        @case ('error') {
          <p class="err">Sign-in did not complete. Please try again.</p>
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
export class OAuthCallbackComponent implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly tokens = inject(TokenStore);
  private readonly auth = inject(AuthService);
  readonly state = signal<'loading' | 'error'>('loading');

  ngOnInit(): void {
    this.route.queryParamMap.subscribe((params) => {
      const error = params.get('error');
      const code = params.get('code');
      if (error || !code) {
        this.state.set('error');
        return;
      }
      // CREDENTIALED: the one-time code is only half — the flow cookie is the
      // other half. Omitting withCredentials yields a 400 identical to a bad code.
      this.http
        .post<{ token: string }>(
          `${this.base}/api/auth/oauth/exchange`,
          { code },
          { withCredentials: true },
        )
        .subscribe({
          next: (res) => {
            this.tokens.set(res.token);
            this.auth.loadMe().subscribe({
              next: () => void this.router.navigate(['/']),
              error: () => void this.router.navigate(['/']),
            });
          },
          error: () => this.state.set('error'),
        });
    });
  }
}
