// src/app/core/magazine-style-writer.ts
import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';
import { MagazineStyle } from './magazine-style';

/**
 * Behind a token for the reason `PREFERENCES_WRITER` is: the service is read by
 * reader components that must not pull in the HTTP layer.
 */
export interface MagazineStyleWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(style: MagazineStyle): Observable<boolean>;
}

export const MAGAZINE_STYLE_WRITER = new InjectionToken<MagazineStyleWriter>(
  'MAGAZINE_STYLE_WRITER',
  { providedIn: 'root', factory: (): MagazineStyleWriter => ({ write: () => of(true) }) },
);
