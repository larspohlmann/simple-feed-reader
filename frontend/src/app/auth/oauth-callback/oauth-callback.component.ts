import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthApi } from '../auth-api';
import { AuthService } from '../../core/auth/auth.service';
import { parseProblem } from '../../core/problem';
import { TokenStore } from '../../core/auth/token.store';
import { AuthShellComponent } from '../auth-shell/auth-shell.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';

@Component({
  selector: 'app-oauth-callback',
  imports: [RouterLink, TranslocoPipe, AuthShellComponent, SpinnerComponent],
  templateUrl: './oauth-callback.component.html',
  styleUrl: './oauth-callback.component.scss',
})
export class OAuthCallbackComponent implements OnInit {
  private readonly authApi = inject(AuthApi);
  private readonly route = inject(ActivatedRoute);
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
      this.authApi.exchangeOAuthCode(code).subscribe({
        next: (token) => {
          this.tokens.set(token);
          this.auth.finishSignIn();
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
