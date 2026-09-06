import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';

export interface MailFailure {
  readonly kind: 'digest' | 'account' | 'test';
  readonly recipient: string;
  readonly error: string;
  readonly at: string;
}

interface MailErrorsResponse {
  readonly failures: MailFailure[];
}

/** App-wide source of the admin mail-failure log (#882), so the settings nav can
 *  badge "Outgoing mail" and the mail section can list failures from one place.
 *  The endpoint is admin-only; callers refresh it only in admin contexts. */
@Injectable({ providedIn: 'root' })
export class MailHealthStore {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  readonly failures = signal<MailFailure[]>([]);
  readonly failureCount = computed(() => this.failures().length);

  /** Guards the settings-landing burst: the rail nav, the hub nav and the mail
   *  section each ask to refresh at once, and one fetch serves them all. */
  private loading = false;

  refresh(): void {
    if (this.loading) return;
    this.loading = true;
    this.http
      .get<MailErrorsResponse>(`${this.base}/api/admin/mail/errors`)
      .pipe(finalize(() => (this.loading = false)))
      .subscribe({
        next: (response) => this.failures.set(response.failures),
        error: () => {
          // A failed refresh leaves the last-known count; the badge is advisory.
        },
      });
  }
}
