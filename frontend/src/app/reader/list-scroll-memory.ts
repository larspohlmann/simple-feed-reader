// src/app/reader/list-scroll-memory.ts
import { Injectable } from '@angular/core';
import { Selection } from './query';

/**
 * Storage key for a selection's scroll offset. Distinguishes feed / tag / view and
 * unread-vs-all so every list remembers its own place independently.
 */
export function scrollKey(s: Selection): string {
  return `feed-reader:list-scroll:${s.kind}:${s.id ?? ''}:${s.unread ? 'u' : 'a'}`;
}

/** Storage key for an open article's own scroll offset, keyed by entry id. */
export function entryScrollKey(entryId: number): string {
  return `feed-reader:article-scroll:${entryId}`;
}

/**
 * Remembers each list's scroll offset in sessionStorage so that when the browser
 * discards and reloads the page — iOS Safari / Brave background a tab and reload it
 * on resume — the list lands where the user left off instead of jumping to the top.
 *
 * sessionStorage (not an in-memory Map) is the point: it survives the full page
 * reload the resume triggers, yet is scoped to the tab session so it clears when
 * the tab closes. All access is defensive — a blocked or full store just loses the
 * memory rather than breaking the list.
 */
@Injectable({ providedIn: 'root' })
export class ListScrollMemory {
  save(s: Selection, top: number): void {
    this.write(scrollKey(s), top);
  }

  read(s: Selection): number {
    return this.readNum(scrollKey(s));
  }

  /** Remember the scroll offset within an open article (keyed by entry id). */
  saveEntry(entryId: number, top: number): void {
    this.write(entryScrollKey(entryId), top);
  }

  readEntry(entryId: number): number {
    return this.readNum(entryScrollKey(entryId));
  }

  private write(key: string, top: number): void {
    const store = this.store();
    if (!store) return;
    try {
      store.setItem(key, String(Math.max(0, Math.round(top))));
    } catch {
      // Quota exceeded or storage blocked (private mode) — scroll memory is
      // a convenience, so dropping it silently is the right failure mode.
    }
  }

  private readNum(key: string): number {
    const store = this.store();
    if (!store) return 0;
    try {
      const raw = store.getItem(key);
      const n = raw == null ? 0 : Number(raw);
      return Number.isFinite(n) && n > 0 ? n : 0;
    } catch {
      return 0;
    }
  }

  private store(): Storage | null {
    try {
      return typeof sessionStorage === 'undefined' ? null : sessionStorage;
    } catch {
      // Merely touching sessionStorage can throw when storage is disabled.
      return null;
    }
  }
}
