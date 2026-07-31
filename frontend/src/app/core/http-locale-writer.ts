// src/app/core/http-locale-writer.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from './api';
import { LocaleWriter } from './locale-writer';

/**
 * The real `LocaleWriter`: PATCHes the account's locale straight against
 * `HttpClient`/`API_BASE_URL`, not through `AuthService`. `AuthService.loadMe()`
 * calls into `LanguageService` (to adopt the account's locale), so a writer
 * that injected `AuthService` back would close that cycle.
 *
 * This does not refresh `AuthService.user()` after a successful write, so the
 * cached user signal keeps its pre-switch locale until the next `loadMe()`.
 * Reaching into `AuthService` from here would reopen the cycle above; flagged
 * for a separate decision rather than worked around silently.
 */
@Injectable({ providedIn: 'root' })
export class HttpLocaleWriter implements LocaleWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(locale: string): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me`, { locale }).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
