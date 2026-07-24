import { Injectable } from '@angular/core';
import { Selection } from './query';

/** A stable per-list identity so each tag/feed/filter remembers its own scroll. */
export function listScrollKey(s: Selection): string {
  return `${s.kind}:${s.id ?? ''}:${s.unread ? 'u' : 'a'}`;
}

/**
 * Remembers the list's scroll offset per selection so returning from an article
 * to the list lands where you left off instead of jumping back to the top.
 * In-memory only — it survives the list component being destroyed and recreated
 * (the full-screen article swaps it out), but not a full page reload.
 */
@Injectable({ providedIn: 'root' })
export class ListScrollStore {
  private readonly positions = new Map<string, number>();

  save(key: string, top: number): void {
    this.positions.set(key, top);
  }

  restore(key: string): number {
    return this.positions.get(key) ?? 0;
  }
}
