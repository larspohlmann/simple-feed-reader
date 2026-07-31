// src/app/core/locale-writer.ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';

/**
 * Writes the chosen language to the account. `LanguageService` is a pure
 * client-state service that every date-formatting component in the app
 * builds just to read `lang()` -- it must never pull in the HTTP layer to do
 * that. Isolating the write behind this token is what keeps it free of
 * `HttpClient`.
 */
export interface LocaleWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(locale: string): Observable<boolean>;
}

/**
 * Defaults to a no-op that reports success without doing anything. Almost
 * every consumer of `LanguageService` never calls `set()` -- they only read
 * `lang()` for formatting -- so the default keeps those call sites, and their
 * tests, free of any HTTP provider. The app wires the real, `HttpClient`-backed
 * writer in `app.config.ts`.
 */
export const LOCALE_WRITER = new InjectionToken<LocaleWriter>('LOCALE_WRITER', {
  providedIn: 'root',
  factory: (): LocaleWriter => ({ write: () => of(true) }),
});
