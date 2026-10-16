import { Injectable, computed, inject } from '@angular/core';
import { prefersReducedMotion } from '../reduced-motion';
import { UserDeviceStorage } from './user-device-storage';

const NAME = 'listAutoplay';

/** Whether list videos start on their own; chosen per device, since it is a bandwidth choice. */
@Injectable({ providedIn: 'root' })
export class ListAutoplayService {
  private readonly storage = inject(UserDeviceStorage);

  readonly enabled = computed(() => {
    const stored = this.storage.read(NAME);
    if (stored === 'on') return true;
    if (stored === 'off') return false;
    return !prefersReducedMotion();
  });

  setEnabled(enabled: boolean): void {
    this.storage.write(NAME, enabled ? 'on' : 'off');
  }
}
