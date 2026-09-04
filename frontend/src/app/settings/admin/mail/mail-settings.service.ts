import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { Problem, parseProblem } from '../../../core/problem';

export type MailEncryption = 'none' | 'starttls' | 'tls';

export interface MailSettingsState {
  readonly enabled: boolean;
  readonly host: string;
  readonly port: number;
  readonly username: string | null;
  readonly encryption: MailEncryption;
  readonly fromAddress: string;
  readonly fromName: string;
  readonly hasPassword: boolean;
  readonly passwordHint: string;
  readonly hasSavedConfig: boolean;
  readonly envFallbackConfigured: boolean;
}

export interface SaveMailSettings {
  readonly enabled: boolean;
  readonly host: string;
  readonly port: number;
  readonly username: string | null;
  readonly encryption: MailEncryption;
  readonly fromAddress: string;
  readonly fromName: string;
  /** null keeps the stored secret; a string replaces it. */
  readonly password: string | null;
}

/** The typed fields behind the explicit Save. The enable toggle and the
 *  encryption select save instantly and never enter the draft. */
export type TypedMailEdits = Partial<Omit<SaveMailSettings, 'enabled' | 'encryption'>>;

export type MailProbe =
  | { readonly status: 'idle' }
  | { readonly status: 'loading' }
  | { readonly status: 'ok' }
  | { readonly status: 'error'; readonly message: string };

interface MailTestResponse {
  readonly ok: boolean;
  readonly reason: string | null;
}

@Injectable()
export class MailSettingsService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  readonly state = signal<MailSettingsState | null>(null);
  readonly busy = signal(false);
  readonly failure = signal<Problem | null>(null);
  readonly saved = signal(false);
  readonly probe = signal<MailProbe>({ status: 'idle' });

  readonly draft = signal<TypedMailEdits>({});
  readonly dirty = computed(() => Object.keys(this.draft()).length > 0);

  load(): void {
    this.run(this.http.get<MailSettingsState>(`${this.base}/api/admin/mail`), (state) =>
      this.commit(state),
    );
  }

  saveInstant(partial: Partial<SaveMailSettings>): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...partial }, (state) => {
      this.state.set(state);
      this.saved.set(true);
    });
  }

  setTypedField<F extends keyof TypedMailEdits>(field: F, value: TypedMailEdits[F]): void {
    this.draft.update((draft) => ({ ...draft, [field]: value }));
  }

  /** `overrides` carries the enable/encryption the component currently
   *  displays. Those two never enter the draft (see `TypedMailEdits`), so
   *  without this they would fall back to `bodyFromState`'s last-saved
   *  values -- correct once a row exists (instant-save already synced
   *  `state`), but wrong for the row-creating first Save, where `state` is
   *  only the env prefill and never reflects an unsaved toggle/select edit. */
  save(overrides: Partial<Pick<SaveMailSettings, 'enabled' | 'encryption'>> = {}): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...overrides, ...this.draft() }, (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  discardDraft(): void {
    this.draft.set({});
  }

  reset(): void {
    this.run(
      this.http.post<MailSettingsState>(`${this.base}/api/admin/mail/reset`, {}),
      (state) => {
        this.commit(state);
        this.saved.set(true);
      },
    );
  }

  testConnection(): void {
    this.probe.set({ status: 'loading' });
    this.http.post<MailTestResponse>(`${this.base}/api/admin/mail/test`, {}).subscribe({
      next: (result) =>
        this.probe.set(
          result.ok ? { status: 'ok' } : { status: 'error', message: result.reason ?? 'failed' },
        ),
      error: (error: HttpErrorResponse) =>
        this.probe.set({ status: 'error', message: parseProblem(error).detail ?? 'failed' }),
    });
  }

  /** password defaults to null (keep stored) unless a typed edit sets it. */
  private bodyFromState(state: MailSettingsState): SaveMailSettings {
    return {
      enabled: state.enabled,
      host: state.host,
      port: state.port,
      username: state.username,
      encryption: state.encryption,
      fromAddress: state.fromAddress,
      fromName: state.fromName,
      password: null,
    };
  }

  private put(body: SaveMailSettings, onSuccess: (state: MailSettingsState) => void): void {
    this.run(this.http.put<MailSettingsState>(`${this.base}/api/admin/mail`, body), onSuccess);
  }

  private commit(state: MailSettingsState): void {
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
