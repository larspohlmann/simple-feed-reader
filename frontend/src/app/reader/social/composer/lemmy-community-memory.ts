import { Injectable, inject } from '@angular/core';
import { UserDeviceStorage } from '../../../core/preferences/user-device-storage';

/** The community each Lemmy account last posted a link to, device-local like the playback speed. */
@Injectable({ providedIn: 'root' })
export class LemmyCommunityMemory {
  private readonly storage = inject(UserDeviceStorage);

  recall(accountId: number): number | null {
    try {
      const remembered = this.storage.read(nameFor(accountId)) ?? '';
      return /^\d+$/.test(remembered) ? Number(remembered) : null;
    } catch {
      return null;
    }
  }

  remember(accountId: number, communityId: number): void {
    try {
      this.storage.write(nameFor(accountId), String(communityId));
    } catch {
      /* Storage blocked: the community is chosen again next time. */
    }
  }
}

function nameFor(accountId: number): string {
  return `lemmyCommunity.${accountId}`;
}
