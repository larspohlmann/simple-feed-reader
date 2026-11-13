import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';
import { Handedness } from './handedness';
import { MagazineStyle } from './magazine-style';

export interface ReadingPreferences {
  scrapeFallbackEnabled: boolean;
  magazineStyle: MagazineStyle;
  handedness: Handedness;
  trendingEnabled: boolean;
}

/**
 * Writes reading settings to the account. Isolated behind a token for the same
 * reason `LOCALE_WRITER` is: the services are read by components that must not
 * pull in the HTTP layer, and their tests must not need an HTTP provider.
 */
export interface PreferencesWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(change: Partial<ReadingPreferences>): Observable<boolean>;
}

export const PREFERENCES_WRITER = new InjectionToken<PreferencesWriter>('PREFERENCES_WRITER', {
  providedIn: 'root',
  factory: (): PreferencesWriter => ({ write: () => of(true) }),
});
