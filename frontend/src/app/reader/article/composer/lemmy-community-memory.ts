import { Injectable } from '@angular/core';

const KEY = 'sfr.composer.lemmyCommunity';

type CommunityByAccount = Record<string, unknown>;

/** The community each Lemmy account last posted a link to, device-local like the playback speed. */
@Injectable({ providedIn: 'root' })
export class LemmyCommunityMemory {
  recall(accountId: number): number | null {
    const remembered = this.load()[String(accountId)];
    return typeof remembered === 'number' && Number.isInteger(remembered) ? remembered : null;
  }

  remember(accountId: number, communityId: number): void {
    this.save({ ...this.load(), [String(accountId)]: communityId });
  }

  private load(): CommunityByAccount {
    try {
      const stored: unknown = JSON.parse(localStorage.getItem(KEY) ?? '{}');
      return isRecord(stored) ? stored : {};
    } catch {
      return {};
    }
  }

  private save(communities: CommunityByAccount): void {
    try {
      localStorage.setItem(KEY, JSON.stringify(communities));
    } catch {
      /* Storage blocked: the community is chosen again next time. */
    }
  }
}

function isRecord(value: unknown): value is CommunityByAccount {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
