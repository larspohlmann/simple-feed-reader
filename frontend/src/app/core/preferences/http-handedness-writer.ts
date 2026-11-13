import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { API_BASE_URL } from '../api';
import { Handedness } from './handedness';
import { HandednessWriter } from './handedness-writer';

/** Goes straight at `HttpClient` for the reason `HttpMagazineStyleWriter` does. */
@Injectable({ providedIn: 'root' })
export class HttpHandednessWriter implements HandednessWriter {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  write(handedness: Handedness): Observable<boolean> {
    return this.http.patch(`${this.base}/api/me/handedness`, { handedness }).pipe(
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
