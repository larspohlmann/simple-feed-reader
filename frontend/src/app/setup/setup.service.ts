// src/app/setup/setup.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { Observable, map, of, tap } from 'rxjs';
import { SetupApi } from './setup-api';

/** Caches the one-time first-run check so the guards do not re-hit the API on
 *  every navigation. `markComplete()` closes the window the moment the operator
 *  finishes setup, without another round-trip. */
@Injectable({ providedIn: 'root' })
export class SetupService {
  private readonly api = inject(SetupApi);
  private cached: boolean | null = null;
  readonly needsSetup = signal<boolean | null>(null);
  readonly mailEnabled = signal<boolean | null>(null);

  ensureLoaded(): Observable<boolean> {
    if (this.cached !== null) return of(this.cached);
    return this.api.status().pipe(
      tap((r) => {
        this.cached = r.needsSetup;
        this.needsSetup.set(r.needsSetup);
        this.mailEnabled.set(r.mailEnabled);
      }),
      map((r) => r.needsSetup),
    );
  }

  markComplete(): void {
    this.cached = false;
    this.needsSetup.set(false);
  }
}
