import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, signal } from '@angular/core';
import { parseProblem } from '../../../core/problem';
import { DraftSettingsService } from '../../../shared/settings/draft-settings.service';

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
  readonly hasSavedConfig: boolean;
  readonly envFallbackConfigured: boolean;
  readonly useProxy: boolean;
  readonly proxyConfigured: boolean;
  readonly proxyLabel: string;
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
  readonly removePassword: boolean;
  readonly useProxy: boolean;
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

export interface MailFailure {
  readonly kind: 'digest' | 'account' | 'test';
  readonly recipient: string;
  readonly error: string;
  readonly at: string;
}

interface MailErrorsResponse {
  readonly count: number;
  readonly failures: MailFailure[];
}

@Injectable()
export class MailSettingsService extends DraftSettingsService<
  MailSettingsState,
  SaveMailSettings,
  TypedMailEdits
> {
  protected readonly endpoint = `${this.base}/api/admin/mail`;

  readonly probe = signal<MailProbe>({ status: 'idle' });

  readonly failures = signal<MailFailure[]>([]);
  /** The pill reads the server-reported total rather than `failures().length`:
   *  both mirror the same server-side retention window, but the total is the
   *  field the pill's contract names. */
  private readonly failureTotal = signal(0);
  readonly failureCount = this.failureTotal.asReadonly();

  constructor() {
    super();
    this.loadFailures();
  }

  loadFailures(): void {
    this.http.get<MailErrorsResponse>(`${this.endpoint}/errors`).subscribe((response) => {
      this.failures.set(response.failures);
      this.failureTotal.set(response.count);
    });
  }

  reset(): void {
    this.run(this.http.post<MailSettingsState>(`${this.endpoint}/reset`, {}), (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  /** Carries any pending typed edits along, like `save` -- committing clears the
   *  draft, so leaving them out would silently discard an in-progress edit. */
  removePassword(): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...this.draft(), removePassword: true }, (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  testConnection(): void {
    this.probe.set({ status: 'loading' });
    this.http.post<MailTestResponse>(`${this.endpoint}/test`, {}).subscribe({
      next: (result) => {
        this.probe.set(
          result.ok ? { status: 'ok' } : { status: 'error', message: result.reason ?? 'failed' },
        );
        this.loadFailures();
      },
      error: (error: HttpErrorResponse) => {
        this.probe.set({ status: 'error', message: parseProblem(error).detail ?? 'failed' });
        this.loadFailures();
      },
    });
  }

  protected bodyFromState(state: MailSettingsState): SaveMailSettings {
    return {
      enabled: state.enabled,
      host: state.host,
      port: state.port,
      username: state.username,
      encryption: state.encryption,
      fromAddress: state.fromAddress,
      fromName: state.fromName,
      password: null,
      removePassword: false,
      useProxy: state.useProxy,
    };
  }
}
