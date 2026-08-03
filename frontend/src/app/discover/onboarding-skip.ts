// src/app/discover/onboarding-skip.ts
import { Injectable } from '@angular/core';
import { onIdentityChange } from '../core/session-identity';

const KEY = 'onboarding.skipped';

/**
 * Remembers, for this browser session only, that the user chose "Skip for now".
 *
 * Session-scoped rather than a database column on purpose: the trigger for the
 * picker is "this user has zero subscriptions", and an empty reader SHOULD keep
 * offering the picker on a later visit. The flag exists only so that skipping
 * does not bounce the user straight back into the redirect that sent them there.
 *
 * A skip belongs to the user who made it: the flag is dropped when the identity
 * changes, so the next user in the same tab still reaches the picker (#263).
 */
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
