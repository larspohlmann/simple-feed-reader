// src/app/core/http-preferences-writer.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from './api';
import { PreferencesWriter } from './preferences-writer';

/**
 * The real `PreferencesWriter`. Goes straight at `HttpClient`/`API_BASE_URL`
 * rather than through `AuthService`, which would close a dependency cycle —
 * the same reason `HttpLocaleWriter` does.
 */
@Injectable({ providedIn: 'root' })
export class HttpPreferencesWriter implements PreferencesWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(scrapeFallbackEnabled: boolean): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/preferences`, { scrapeFallbackEnabled }).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
