import { Injectable, computed } from '@angular/core';
import { CachedAccountChoice } from './cached-account-choice';
import { DEFAULT_HANDEDNESS, HANDEDNESS, HANDEDNESS_KEY } from './handedness';

/** Which corner the floating corner buttons stack in on a touch screen. */
@Injectable({ providedIn: 'root' })
export class HandednessService extends CachedAccountChoice<'handedness'> {
  readonly hand = this.value;

  readonly leftHanded = computed(() => this.hand() === 'left');

  constructor() {
    super({
      key: HANDEDNESS_KEY,
      choices: HANDEDNESS,
      fallback: DEFAULT_HANDEDNESS,
      field: 'handedness',
    });
  }
}
