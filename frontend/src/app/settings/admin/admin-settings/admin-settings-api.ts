// src/app/settings/admin/admin-settings/admin-settings-api.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';

export interface InstanceSettings {
  requireEmailConfirmation: boolean;
  requireApproval: boolean;
  mailEnabled: boolean;
  /** The external base URL used to build links in outgoing email, or null to
   *  fall back to the APP_FRONTEND_URL deploy env (#636). */
  publicBaseUrl: string | null;
}

@Injectable({ providedIn: 'root' })
export class AdminSettingsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  get(): Observable<InstanceSettings> {
    return this.http.get<InstanceSettings>(`${this.base}/api/admin/settings`);
  }

  update(
    requireEmailConfirmation: boolean,
    requireApproval: boolean,
    publicBaseUrl: string | null,
  ): Observable<InstanceSettings> {
    return this.http.put<InstanceSettings>(`${this.base}/api/admin/settings`, {
      requireEmailConfirmation,
      requireApproval,
      publicBaseUrl,
    });
  }
}
