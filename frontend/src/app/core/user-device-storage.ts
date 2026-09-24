import { Injectable, inject, signal } from '@angular/core';
import { AccountIdentity } from './account-identity';

/** Per-account values that stay on this device: `sfr.user.<id>.<name>` in localStorage. */
@Injectable({ providedIn: 'root' })
export class UserDeviceStorage {
  private readonly identity = inject(AccountIdentity);
  private readonly revision = signal(0);

  read(name: string): string | null {
    this.revision();
    const key = this.keyFor(name);
    return key === null ? null : localStorage.getItem(key);
  }

  write(name: string, value: string | null): void {
    const key = this.keyFor(name);
    if (key === null) return;
    if (value === null) localStorage.removeItem(key);
    else localStorage.setItem(key, value);
    this.revision.update((count) => count + 1);
  }

  forgetCurrentUser(): void {
    const prefix = this.keyFor('');
    if (prefix === null) return;
    for (let index = localStorage.length - 1; index >= 0; index--) {
      const key = localStorage.key(index);
      if (key?.startsWith(prefix)) localStorage.removeItem(key);
    }
    this.revision.update((count) => count + 1);
  }

  private keyFor(name: string): string | null {
    const userId = this.identity.userId();
    return userId === null ? null : `sfr.user.${userId}.${name}`;
  }
}
