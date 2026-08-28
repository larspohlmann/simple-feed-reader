// src/app/core/digest-writer.ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';

export interface DigestConfig {
  enabled: boolean;
  cadence: 'daily' | 'weekly';
  sendHour: number;
  weekday: number;
}

/**
 * Writes the digest configuration to the account. Isolated behind a token for
 * the same reason `PREFERENCES_WRITER` is: the service is read by components
 * that must not pull in the HTTP layer, and their tests must not need an HTTP
 * provider.
 */
export interface DigestWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(config: DigestConfig): Observable<boolean>;
}

export const DIGEST_WRITER = new InjectionToken<DigestWriter>('DIGEST_WRITER', {
  providedIn: 'root',
  factory: (): DigestWriter => ({ write: () => of(true) }),
});
