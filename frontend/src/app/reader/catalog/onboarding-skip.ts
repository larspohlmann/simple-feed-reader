import { Injectable } from '@angular/core';
import { onIdentityChange } from '../core/session-identity';

const KEY = 'onboarding.skipped';

/** Remembers, for this browser session only, that the user chose "Skip for
 *  now" -- so skipping doesn't bounce them straight back into the redirect
 *  that sent them there. Session-scoped, not a database column: an empty
 *  reader should keep offering the picker on a later visit. Dropped on
 *  identity change so the next user in the same tab still reaches it (#263). */
@Injectable({ providedIn: 'root' })
export class OnboardingSkip {
  constructor() {
    onIdentityChange(() => this.forget());
  }

  wasSkipped(): boolean {
    try {
      return sessionStorage.getItem(KEY) === '1';
    } catch {
      // Storage disabled (private mode, blocked cookies): treat as not skipped.
      return false;
    }
  }

  remember(): void {
    try {
      sessionStorage.setItem(KEY, '1');
    } catch {
      // Not being able to remember a skip is survivable; throwing here is not.
    }
  }

  private forget(): void {
    try {
      sessionStorage.removeItem(KEY);
    } catch {
      // Storage disabled: there was nothing remembered to drop.
    }
  }
}
