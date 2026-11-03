import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';
import { SharingSettings } from './sharing';

/** Behind a token for the reason `MAGAZINE_STYLE_WRITER` is. */
export interface SharingWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(settings: SharingSettings): Observable<boolean>;
}

export const SHARING_WRITER = new InjectionToken<SharingWriter>('SHARING_WRITER', {
  providedIn: 'root',
  factory: (): SharingWriter => ({ write: () => of(true) }),
});
