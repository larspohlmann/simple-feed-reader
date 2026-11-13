import { Injectable, computed, inject, signal } from '@angular/core';
import { CurrentUser } from '../auth/auth.service';
import { DEFAULT_HANDEDNESS, HANDEDNESS_KEY, Handedness, asHandedness } from './handedness';
import { PREFERENCES_WRITER } from './preferences-writer';

/** Which corner the floating corner buttons stack in on a touch screen; the account is the record. */
@Injectable({ providedIn: 'root' })
export class HandednessService {
  private readonly writer = inject(PREFERENCES_WRITER);

  readonly hand = signal<Handedness>(this.cached());

  readonly leftHanded = computed(() => this.hand() === 'left');

  readonly saveFailed = signal(false);

  set(hand: Handedness): void {
    this.cache(hand);
    this.saveFailed.set(false);

    this.writer.write({ handedness: hand }).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  /** Caches only: a value just read from the account is never written straight back. */
  adopt(user: CurrentUser): void {
    const hand = asHandedness(user.preferences.handedness);
    if (hand === null) return;
    this.cache(hand);
  }

  /** Cache included: the next account must not inherit this one's corner. */
  reset(): void {
    localStorage.removeItem(HANDEDNESS_KEY);
    this.hand.set(DEFAULT_HANDEDNESS);
    this.saveFailed.set(false);
  }

  private cache(hand: Handedness): void {
    localStorage.setItem(HANDEDNESS_KEY, hand);
    this.hand.set(hand);
  }

  private cached(): Handedness {
    return asHandedness(localStorage.getItem(HANDEDNESS_KEY)) ?? DEFAULT_HANDEDNESS;
  }
}
