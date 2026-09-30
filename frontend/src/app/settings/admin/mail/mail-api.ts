import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';

export interface MailFailure {
  readonly kind: 'digest' | 'account' | 'test';
  readonly recipient: string;
  readonly error: string;
  readonly at: string;
}

@Injectable({ providedIn: 'root' })
export class MailApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  failures(): Observable<MailFailure[]> {
    return this.http
      .get<{ failures: MailFailure[] }>(`${this.base}/api/admin/mail/errors`)
      .pipe(map((response) => response.failures));
  }
}
