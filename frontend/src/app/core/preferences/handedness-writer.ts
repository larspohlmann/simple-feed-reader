import { InjectionToken } from '@angular/core';
import { Observable, of } from 'rxjs';
import { Handedness } from './handedness';

export interface HandednessWriter {
  /** Resolves `true` on success, `false` on failure. Never errors. */
  write(hand: Handedness): Observable<boolean>;
}

export const HANDEDNESS_WRITER = new InjectionToken<HandednessWriter>('HANDEDNESS_WRITER', {
  providedIn: 'root',
  factory: (): HandednessWriter => ({ write: () => of(true) }),
});
