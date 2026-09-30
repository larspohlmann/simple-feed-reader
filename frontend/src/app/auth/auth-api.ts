import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../core/api';

export interface Registration {
  readonly email: string;
  readonly password: string;
  readonly altcha: string;
  readonly locale: string;
}

@Injectable({ providedIn: 'root' })
export class AuthApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  oauthProviders(): Observable<string[]> {
    return this.http
      .get<{ providers: string[] | null }>(`${this.base}/api/auth/oauth/providers`)
      .pipe(map((response) => response.providers ?? []));
  }

  oauthStartUrl(provider: string): string {
    return `${this.base}/api/auth/oauth/${provider}`;
  }

  /** The one-time code is only half; the flow cookie is the other half.
   *  Omitting withCredentials yields a 400 identical to a bad code. */
  exchangeOAuthCode(code: string): Observable<string> {
    return this.http
      .post<{
        token: string;
      }>(`${this.base}/api/auth/oauth/exchange`, { code }, { withCredentials: true })
      .pipe(map((response) => response.token));
  }

  register(registration: Registration): Observable<string> {
    return this.http
      .post<{ status: string }>(`${this.base}/api/auth/register`, registration)
      .pipe(map((response) => response.status));
  }

  requestPasswordReset(email: string, altcha: string): Observable<unknown> {
    return this.http.post(`${this.base}/api/auth/password-reset-request`, { email, altcha });
  }

  resetPassword(token: string, password: string): Observable<unknown> {
    return this.http.post(`${this.base}/api/auth/password-reset`, { token, password });
  }

  verifyEmail(token: string): Observable<unknown> {
    return this.http.post(`${this.base}/api/auth/verify-email`, { token });
  }
}
