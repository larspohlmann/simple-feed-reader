import { Injectable, inject, signal } from '@angular/core';
import { CurrentUser } from '../auth/auth.service';
import { HANDEDNESS_KEY, Handedness, asHandedness } from './handedness';
import { HANDEDNESS_WRITER } from './handedness-writer';

/** Which corner the floating corner buttons stack in on a touch screen; the account is the record. */
@Injectable({ providedIn: 'root' })
export class HandednessService {
  private readonly writer = inject(HANDEDNESS_WRITER);

  readonly hand = signal<Handedness>(this.cached());

  /** True when the handedness applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  set(hand: Handedness): void {
    this.cache(hand);
    this.saveFailed.set(false);

    this.writer.write(hand).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  /** Caches only, for the reason `MagazineStyleService.adopt` gives. */
  adopt(user: CurrentUser): void {
    const hand = asHandedness(user.preferences.handedness);
    if (hand === null) return;
    this.cache(hand);
  }

  /** Cache included: the next account must not inherit this one's corner. */
  reset(): void {
    localStorage.removeItem(HANDEDNESS_KEY);
    this.hand.set('right');
    this.saveFailed.set(false);
  }

  private cache(hand: Handedness): void {
    localStorage.setItem(HANDEDNESS_KEY, hand);
    this.hand.set(hand);
  }

  private cached(): Handedness {
    return asHandedness(localStorage.getItem(HANDEDNESS_KEY)) ?? 'right';
  }
}
