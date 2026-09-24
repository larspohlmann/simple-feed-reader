import { Injectable, computed, inject } from '@angular/core';
import { UserDeviceStorage } from '../core/user-device-storage';

const NAME = 'unread-only';
const LEGACY_DEVICE_KEY = 'sfr.unread-only';

@Injectable({ providedIn: 'root' })
export class UnreadFilterService {
  private readonly storage = inject(UserDeviceStorage);

  readonly unreadOnly = computed(() => this.storage.read(NAME) === '1');

  constructor() {
    localStorage.removeItem(LEGACY_DEVICE_KEY);
  }

  set(unreadOnly: boolean): void {
    this.storage.write(NAME, unreadOnly ? '1' : null);
  }
}
