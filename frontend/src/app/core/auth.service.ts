// src/app/core/auth.service.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { AiAvailabilityService } from './ai-availability.service';
import { API_BASE_URL } from './api';
import { DigestService } from './digest.service';
import { LanguageService } from './language.service';
import { MagazineStyleService } from './magazine-style.service';
import { PreferencesService } from './preferences.service';
import { TokenStore } from './token.store';

export interface UserDigestPreferences {
  enabled: boolean;
  cadence: 'daily' | 'weekly';
  sendHour: number;
  weekday: number;
  format: 'html' | 'text';
  /** The instance's configured timezone (`APP_TIMEZONE`), read-only: the send
   *  hour is interpreted in this zone. Never sent back in a digest PATCH. */
  timezone: string;
}

export interface UserPreferences {
  scrapeFallbackEnabled: boolean;
  digest: UserDigestPreferences;
  /** Whether the account has answered the first-login passkey offer --
   *  accepted, declined, or dismissed without either (#624 design spec §5).
   *  Never reset once true: the offer must never ask twice. */
  passkeyOfferAnswered: boolean;
  magazineStyle: string;
}

export interface CurrentUser {
  id: number;
  email: string;
  roles: string[];
  status: string;
  createdAt: string;
  locale: string;
  trialEndsAt: string | null;
  preferences: UserPreferences;
  ai: { ready: boolean; model: string | null };
  mail: { enabled: boolean };
  emailVerified: boolean;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly tokens = inject(TokenStore);
  private readonly router = inject(Router);
  private readonly language = inject(LanguageService);
  private readonly preferences = inject(PreferencesService);
  private readonly magazineStyle = inject(MagazineStyleService);
  private readonly digest = inject(DigestService);
  private readonly ai = inject(AiAvailabilityService);

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
        this.preferences.adopt(u);
        this.magazineStyle.adopt(u);
        this.digest.adopt(u);
        this.ai.adopt(u);
      }),
    );
  }

  logout(): void {
    this.tokens.clear();
    this.user.set(null);
    // Per-account, unlike locale: leaving it set would let the next signed-in
    // account see the previous one's toggle state until (or unless) its own
    // loadMe() resolves.
    this.preferences.reset();
    this.magazineStyle.reset();
    this.digest.reset();
    this.ai.reset();
    void this.router.navigate(['/login']);
  }

  isAdmin(): boolean {
    return this.user()?.roles.includes('ROLE_ADMIN') ?? false;
  }

  /** Deliberately does not call `logout()` itself: the caller decides what to
   *  do with a failure (e.g. show it inline), and `logout()` navigates. */
  deleteAccount(): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/me`);
  }

  /** Re-sends the account's verification email. The email section is the only
   *  caller today (its `unverified` state), so the request lives here rather
   *  than in a dedicated service. */
  resendVerification(): Observable<void> {
    return this.http.post<void>(`${this.base}/api/me/resend-verification`, {});
  }

  /** Tells the server the first-login passkey offer was answered -- accepted,
   *  declined, or dismissed without either -- so it never asks again (#624
   *  design spec §5.4). `PasskeyOfferDialogComponent` is the only caller,
   *  from every path that closes it. A failed write (e.g. offline) is
   *  accepted: the offer simply returns on the next boot (design spec §5.4),
   *  so no retry lives here. */
  answerPasskeyOffer(): Observable<void> {
    return this.http
      .post<void>(`${this.base}/api/me/passkey-offer/answer`, {})
      .pipe(tap(() => this.markPasskeyOfferAnswered()));
  }

  /** Marks the offer answered in the local signal alone, with no request --
   *  for the one path that has already told the server: a successful passkey
   *  enrolment stamps the flag server-side as a side effect of enrolling
   *  (design spec §5.2), so calling `answerPasskeyOffer()` there too would be
   *  a redundant write. A re-render inside the same boot then reads the
   *  offer as already answered, rather than opening it a second time. */
  markPasskeyOfferAnswered(): void {
    const user = this.user();
    if (!user) return;
    this.user.set({ ...user, preferences: { ...user.preferences, passkeyOfferAnswered: true } });
  }
}
