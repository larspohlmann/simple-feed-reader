// src/app/core/auth.service.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { API_BASE_URL } from './api';
import { LanguageService } from './language.service';
import { TokenStore } from './token.store';

export interface CurrentUser {
  id: number;
  email: string;
  roles: string[];
  status: string;
  createdAt: string;
  locale: string;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly tokens = inject(TokenStore);
  private readonly router = inject(Router);
  private readonly language = inject(LanguageService);

  readonly user = signal<CurrentUser | null>(null);

  login(email: string, password: string): Observable<{ token: string }> {
    return this.http
      .post<{ token: string }>(`${this.base}/api/auth/login`, { email, password })
      .pipe(tap((res) => this.tokens.set(res.token)));
  }

  /** The single place the account's locale is adopted into the UI -- every
   *  caller (login, OAuth callback, the admin guard, a deep-link reader or
   *  settings mount) goes through here, so none of them touch `LanguageService`
   *  directly any more. */
  loadMe(): Observable<CurrentUser> {
    return this.http.get<CurrentUser>(`${this.base}/api/me`).pipe(
      tap((u) => {
        this.user.set(u);
        this.language.adopt(u.locale);
      }),
    );
  }

  logout(): void {
    this.tokens.clear();
    this.user.set(null);
    void this.router.navigate(['/login']);
  }

  isAdmin(): boolean {
    return this.user()?.roles.includes('ROLE_ADMIN') ?? false;
  }
}
