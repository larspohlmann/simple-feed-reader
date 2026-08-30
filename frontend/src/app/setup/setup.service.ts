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
  /** Whether THIS instance can actually complete a passkey sign-in right now
   *  -- the toggle AND the relying-party configuration both hold (#624
   *  follow-up). Distinct from `isPasskeySupported()`, which only asks
   *  whether the BROWSER understands WebAuthn at all. `null` until loaded;
   *  every gate below treats that the same as `false` -- see
   *  AiAvailabilityService's docblock for why staying hidden a moment longer
   *  is right where showing a control that then fails is not. */
  readonly passkeySignInAvailable = signal<boolean | null>(null);

  ensureLoaded(): Observable<boolean> {
    if (this.cached !== null) return of(this.cached);
    return this.api.status().pipe(
      tap((r) => {
        this.cached = r.needsSetup;
        this.needsSetup.set(r.needsSetup);
        this.mailEnabled.set(r.mailEnabled);
        this.passkeySignInAvailable.set(r.passkeySignInAvailable);
      }),
      map((r) => r.needsSetup),
    );
  }

  markComplete(): void {
    this.cached = false;
    this.needsSetup.set(false);
  }
}
