import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { parseProblem } from '../../core/problem';
import { TokenStore } from '../../core/token.store';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';

@Component({
  selector: 'app-oauth-callback',
  imports: [RouterLink, TranslocoPipe, AuthShellComponent, SpinnerComponent],
  templateUrl: './oauth-callback.component.html',
  styleUrl: './oauth-callback.component.scss',
})
export class OAuthCallbackComponent implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly tokens = inject(TokenStore);
  private readonly auth = inject(AuthService);
  readonly state = signal<'loading' | 'error' | 'blocked'>('loading');

  /**
   * The `accountStatus` the API reported, which decides which sentence the
   * blocked screen shows. Never read outside the `blocked` state.
   */
  readonly blockedStatus = signal<string | null>(null);

  /**
   * "Signing you in" is a lie once the answer is "this account may not". The
   * shell heading follows the outcome, and borrows the API's own wording for a
   * blocked account so the screen and the problem document agree.
   */
  readonly title = computed(() =>
    'blocked' === this.state() ? 'auth.oauth.blockedTitle' : 'auth.oauth.title',
  );

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
          error: (response: HttpErrorResponse) => this.show(response),
        });
    });
  }

  /**
   * A blocked account is an outcome, not a breakdown.
   *
   * The API answers a first-time OAuth user in the approval queue with 403
   * `account_not_active` carrying `accountStatus` (OAuthSignIn::issueLoginCode())
   * so this leg can say what the user is waiting for, instead of the generic
   * "something went wrong" a redirect could only give.
   *
   * Switches on `accountStatus`, not `detail`: `detail` is English-only prose,
   * while `accountStatus` is the documented client-branch key
   * (ApiExceptionListener). No `accountStatus` keeps the generic message -- a
   * spent code, missing flow cookie, or dead network are real, retryable failures.
   */
  private show(response: HttpErrorResponse): void {
    const accountStatus = parseProblem(response).accountStatus;

    if (undefined === accountStatus) {
      this.state.set('error');
      return;
    }

    this.blockedStatus.set(accountStatus);
    this.state.set('blocked');
  }
}
