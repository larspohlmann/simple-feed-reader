import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from '../api';
import { SharingSettings } from './sharing';
import { SharingWriter } from './sharing-writer';

/** Goes straight at `HttpClient` for the reason `HttpMagazineStyleWriter` does. */
@Injectable({ providedIn: 'root' })
export class HttpSharingWriter implements SharingWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(settings: SharingSettings): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/sharing`, settings).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
