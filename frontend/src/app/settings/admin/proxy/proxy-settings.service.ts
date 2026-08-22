// src/app/settings/admin/proxy/proxy-settings.service.ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { Problem, parseProblem } from '../../../core/problem';

export type ProxyType = 'SOCKS5' | 'HTTP';

export interface ProxySettingsState {
  readonly enabled: boolean;
  readonly directFallback: boolean;
  readonly type: ProxyType;
  readonly host: string;
  readonly port: number;
  readonly username: string | null;
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
  /** null keeps the stored secret; a string replaces it. */
  readonly password: string | null;
}

/** The typed text/number fields behind the explicit Save. The three toggles/selects
 *  — enabled, directFallback, type — save instantly and never enter the draft. */
export type TypedProxyEdits = Partial<
  Omit<SaveProxySettings, 'enabled' | 'directFallback' | 'type'>
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
export class ProxySettingsService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  readonly state = signal<ProxySettingsState | null>(null);
  readonly busy = signal(false);
  readonly failure = signal<Problem | null>(null);
  readonly saved = signal(false);
  readonly probe = signal<ProxyProbe>({ status: 'idle' });

  readonly draft = signal<TypedProxyEdits>({});
  readonly dirty = computed(() => Object.keys(this.draft()).length > 0);

  load(): void {
    this.run(this.http.get<ProxySettingsState>(`${this.base}/api/admin/proxy`), (state) =>
      this.commit(state),
    );
  }

  saveInstant(partial: Partial<SaveProxySettings>): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...partial }, (state) => {
      this.state.set(state);
      this.saved.set(true);
    });
  }

  setTypedField<F extends keyof TypedProxyEdits>(field: F, value: TypedProxyEdits[F]): void {
    this.draft.update((draft) => ({ ...draft, [field]: value }));
  }

  save(): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...this.draft() }, (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  discardDraft(): void {
    this.draft.set({});
  }

  testConnection(): void {
    this.probe.set({ status: 'loading' });
    this.http.post<ProxyTestResponse>(`${this.base}/api/admin/proxy/test`, {}).subscribe({
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

  /** password defaults to null (keep stored) unless a typed edit sets it. */
  private bodyFromState(state: ProxySettingsState): SaveProxySettings {
    return {
      enabled: state.enabled,
      directFallback: state.directFallback,
      type: state.type,
      host: state.host,
      port: state.port,
      username: state.username,
      password: null,
    };
  }

  private put(body: SaveProxySettings, onSuccess: (state: ProxySettingsState) => void): void {
    this.run(this.http.put<ProxySettingsState>(`${this.base}/api/admin/proxy`, body), onSuccess);
  }

  private commit(state: ProxySettingsState): void {
    this.state.set(state);
    this.draft.set({});
  }

  private run<T>(request: Observable<T>, onSuccess: (value: T) => void): void {
    this.busy.set(true);
    this.failure.set(null);
    this.saved.set(false);
    request.subscribe({
      next: (value) => {
        this.busy.set(false);
        onSuccess(value);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(parseProblem(error));
      },
    });
  }
}
