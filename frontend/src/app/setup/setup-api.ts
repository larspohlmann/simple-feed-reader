// src/app/setup/setup-api.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';

@Injectable({ providedIn: 'root' })
export class SetupApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  status(): Observable<{ needsSetup: boolean; mailEnabled: boolean }> {
    return this.http.get<{ needsSetup: boolean; mailEnabled: boolean }>(
      `${this.base}/api/setup/status`,
    );
  }

  createAdmin(email: string, password: string, secret: string): Observable<{ token: string }> {
    return this.http.post<{ token: string }>(`${this.base}/api/setup/admin`, {
      email,
      password,
      secret,
    });
  }
}
