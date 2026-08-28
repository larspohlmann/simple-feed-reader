// src/app/core/http-digest-writer.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from './api';
import { DigestConfig, DigestWriter } from './digest-writer';

/**
 * The real `DigestWriter`. Goes straight at `HttpClient`/`API_BASE_URL` rather
 * than through `AuthService`, which would close a dependency cycle — the same
 * reason `HttpPreferencesWriter` does.
 */
@Injectable({ providedIn: 'root' })
export class HttpDigestWriter implements DigestWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(config: DigestConfig): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/digest`, config).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
