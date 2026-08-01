// src/app/settings/admin/admin-settings/admin-settings-api.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';

export interface InstanceSettings {
  requireEmailConfirmation: boolean;
  requireApproval: boolean;
  mailEnabled: boolean;
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
  ): Observable<InstanceSettings> {
    return this.http.put<InstanceSettings>(`${this.base}/api/admin/settings`, {
      requireEmailConfirmation,
      requireApproval,
    });
  }
}
