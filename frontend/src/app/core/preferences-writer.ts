// src/app/core/preferences-writer.ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';

/**
 * Writes a preference to the account. Isolated behind a token for the same
 * reason `LOCALE_WRITER` is: the service is read by components that must not
 * pull in the HTTP layer, and their tests must not need an HTTP provider.
 */
export interface PreferencesWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(scrapeFallbackEnabled: boolean): Observable<boolean>;
}

export const PREFERENCES_WRITER = new InjectionToken<PreferencesWriter>('PREFERENCES_WRITER', {
  providedIn: 'root',
  factory: (): PreferencesWriter => ({ write: () => of(true) }),
});
