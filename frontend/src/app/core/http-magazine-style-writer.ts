// src/app/core/http-magazine-style-writer.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from './api';
import { MagazineStyle } from './magazine-style';
import { MagazineStyleWriter } from './magazine-style-writer';

/**
 * Goes straight at `HttpClient` rather than through `AuthService`, which would
 * close a dependency cycle — the same reason `HttpPreferencesWriter` does.
 */
@Injectable({ providedIn: 'root' })
export class HttpMagazineStyleWriter implements MagazineStyleWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(magazineStyle: MagazineStyle): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/magazine-style`, { magazineStyle }).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
