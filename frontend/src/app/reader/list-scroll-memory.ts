import { Injectable } from '@angular/core';
import { Selection } from './query';

/**
 * Storage key for a selection's scroll offset. Distinguishes feed / tag / view and
 * unread-vs-all so every list remembers its own place independently.
 */
export function scrollKey(s: Selection): string {
  const kind = s.searchOrigin === 'saved' ? 'saved-search' : s.kind;
  return `feed-reader:list-scroll:${kind}:${s.id ?? ''}:${s.unread ? 'u' : 'a'}:${s.term ?? ''}`;
}

/** Storage key for an open article's own scroll offset, keyed by entry id. */
export function entryScrollKey(entryId: number): string {
  return `feed-reader:article-scroll:${entryId}`;
}

/**
 * Remembers each list's scroll offset in sessionStorage, so a background-discard
 * reload (iOS Safari / Brave resuming a tab) lands where the user left off instead
 * of jumping to the top. sessionStorage (not a Map) survives that reload yet clears
 * with the tab; all access is defensive since a blocked/full store is a convenience loss.
 */
@Injectable({ providedIn: 'root' })
export class ListScrollMemory {
  save(s: Selection, top: number): void {
    this.write(scrollKey(s), top);
  }

  read(s: Selection): number {
    return this.readNum(scrollKey(s));
  }

  /** Drop a list's remembered offset, so its next load starts at the top. */
  forget(s: Selection): void {
    this.mutate((store) => store.removeItem(scrollKey(s)));
  }

  /** Remember the scroll offset within an open article (keyed by entry id). */
  saveEntry(entryId: number, top: number): void {
    this.write(entryScrollKey(entryId), top);
  }

  readEntry(entryId: number): number {
    return this.readNum(entryScrollKey(entryId));
  }

  private write(key: string, top: number): void {
    this.mutate((store) => store.setItem(key, String(Math.max(0, Math.round(top)))));
  }

  private mutate(change: (store: Storage) => void): void {
    const store = this.store();
    if (!store) return;
    try {
      change(store);
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
