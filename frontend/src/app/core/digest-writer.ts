// src/app/core/digest-writer.ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';

export interface DigestConfig {
  enabled: boolean;
  cadence: 'daily' | 'weekly';
  sendHour: number;
  weekday: number;
  format: 'html' | 'text';
}

/** Outcome of a one-off test-digest send. `empty` is a successful request
 *  that matched nothing (the backend's `sent: false`); `rateLimited` is a 429;
 *  `failed` is anything else. */
export type DigestTestMailResult = 'sent' | 'empty' | 'rateLimited' | 'failed';

/**
 * Writes the digest configuration to the account. Isolated behind a token for
 * the same reason `PREFERENCES_WRITER` is: the service is read by components
 * that must not pull in the HTTP layer, and their tests must not need an HTTP
 * provider.
 */
export interface DigestWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(config: DigestConfig): Observable<boolean>;

  /** Sends a one-off test digest covering the last `days` days. Never errors --
   *  a rate limit or any other failure resolves to a distinct result so the
   *  caller can show the right inline message. */
  sendTest(days: number): Observable<DigestTestMailResult>;
}

export const DIGEST_WRITER = new InjectionToken<DigestWriter>('DIGEST_WRITER', {
  providedIn: 'root',
  factory: (): DigestWriter => ({ write: () => of(true), sendTest: () => of('sent') }),
});
