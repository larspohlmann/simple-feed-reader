import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, signal } from '@angular/core';
import { parseProblem } from '../../../core/problem';
import { DraftSettingsService } from '../../../shared/settings/draft-settings.service';

export type ProxyType = 'SOCKS5' | 'HTTP';

export interface ProxySettingsState {
  readonly enabled: boolean;
  readonly directFallback: boolean;
  readonly type: ProxyType;
  readonly host: string;
  readonly port: number;
  readonly username: string | null;
  readonly remoteDns: boolean;
  readonly hasPassword: boolean;
  readonly passwordHint: string;
}

export interface SaveProxySettings {
  readonly enabled: boolean;
  readonly directFallback: boolean;
  readonly type: ProxyType;
  readonly host: string;
  readonly port: number;
  readonly username: string | null;
  readonly remoteDns: boolean;
  /** null keeps the stored secret; a string replaces it. */
  readonly password: string | null;
}

/** The typed text/number fields behind the explicit Save. The toggles and the
 *  select — enabled, directFallback, remoteDns, type — save instantly and never
 *  enter the draft. */
export type TypedProxyEdits = Partial<
  Omit<SaveProxySettings, 'enabled' | 'directFallback' | 'remoteDns' | 'type'>
>;

export type ProxyProbe =
  | { readonly status: 'idle' }
  | { readonly status: 'loading' }
  | { readonly status: 'ok'; readonly egressIp: string }
  | { readonly status: 'error'; readonly message: string };

interface ProxyTestResponse {
  readonly ok: boolean;
  readonly egressIp: string | null;
  readonly reason: string | null;
}

@Injectable()
export class ProxySettingsService extends DraftSettingsService<
  ProxySettingsState,
  SaveProxySettings,
  TypedProxyEdits
> {
  protected readonly endpoint = `${this.base}/api/admin/proxy`;

  readonly probe = signal<ProxyProbe>({ status: 'idle' });

  testConnection(): void {
    this.probe.set({ status: 'loading' });
    this.http.post<ProxyTestResponse>(`${this.endpoint}/test`, {}).subscribe({
      next: (result) =>
        this.probe.set(
          result.ok && result.egressIp
            ? { status: 'ok', egressIp: result.egressIp }
            : { status: 'error', message: result.reason ?? 'failed' },
        ),
      error: (error: HttpErrorResponse) =>
        this.probe.set({ status: 'error', message: parseProblem(error).detail ?? 'failed' }),
    });
  }

  protected bodyFromState(state: ProxySettingsState): SaveProxySettings {
    return {
      enabled: state.enabled,
      directFallback: state.directFallback,
      type: state.type,
      host: state.host,
      port: state.port,
      username: state.username,
      remoteDns: state.remoteDns,
      password: null,
    };
  }
}
