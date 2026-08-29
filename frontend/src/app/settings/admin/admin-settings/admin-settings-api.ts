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
  /** The stored passkey relying-party id override, or null to derive it from
   *  publicBaseUrl's host (#624). */
  passkeyRpId: string | null;
  /** The stored passkey relying-party display name override, or null to fall
   *  back to "Simple Feed Reader" (#624). */
  passkeyRpName: string | null;
  /** Read-only: the relying-party id the server would actually use right
   *  now -- the stored override, or the derived host. Never sent back on a
   *  PUT; it exists so the UI can show the real value even when passkeyRpId
   *  is empty. */
  passkeyRpIdEffective: string;
}

/**
 * The full-replace PUT body `InstanceSettingsRequest` expects (#624): every
 * field must be sent together, or the server resets whatever is missing to
 * its constructor default. `invalidateExistingPasskeys` is a one-shot command
 * modifier, not a stored setting -- it confirms a relying-party id change the
 * server already refused with a 409.
 */
export interface InstanceSettingsUpdate {
  requireEmailConfirmation: boolean;
  requireApproval: boolean;
  publicBaseUrl: string | null;
  passkeyRpId: string | null;
  passkeyRpName: string | null;
  invalidateExistingPasskeys: boolean;
}

@Injectable({ providedIn: 'root' })
export class AdminSettingsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  get(): Observable<InstanceSettings> {
    return this.http.get<InstanceSettings>(`${this.base}/api/admin/settings`);
  }

  update(update: InstanceSettingsUpdate): Observable<InstanceSettings> {
    return this.http.put<InstanceSettings>(`${this.base}/api/admin/settings`, update);
  }
}
