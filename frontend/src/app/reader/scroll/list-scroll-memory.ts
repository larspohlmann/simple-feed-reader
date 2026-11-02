import { Injectable } from '@angular/core';
import { Selection, listOrderOf, networkKey } from '../query/query';

/**
 * Storage key for a selection's scroll offset. Distinguishes feed / tag / view,
 * unread-vs-all, and the list order so every list remembers its own place independently.
 */
export function scrollKey(selection: Selection): string {
  return `feed-reader:list-scroll:${selection.kind}:${selection.id ?? ''}:${selection.unread ? 'u' : 'a'}:${selection.term ?? ''}:${networkKey(selection)}:${selection.group ?? ''}:${listOrderOf(selection)}`;
}

/** Storage key for an open article's own scroll offset. */
export function articleScrollKey(pageKey: string): string {
  return `feed-reader:article-scroll:${pageKey}`;
}

/**
 * Remembers each list's scroll offset in sessionStorage, so a background-discard
 * reload (iOS Safari / Brave resuming a tab) lands where the user left off instead
 * of jumping to the top. sessionStorage (not a Map) survives that reload yet clears
 * with the tab; all access is defensive since a blocked/full store is a convenience loss.
 */
@Injectable({ providedIn: 'root' })
export class ListScrollMemory {
  save(selection: Selection, top: number): void {
    this.write(scrollKey(selection), top);
  }

  read(selection: Selection): number {
    return this.readNum(scrollKey(selection));
  }

  /** Drop a list's remembered offset, so its next load starts at the top. */
  forget(selection: Selection): void {
    this.mutate((store) => store.removeItem(scrollKey(selection)));
  }

  /** Remember the scroll offset within an open article. */
  saveArticle(pageKey: string, top: number): void {
    this.write(articleScrollKey(pageKey), top);
  }

  readArticle(pageKey: string): number {
    return this.readNum(articleScrollKey(pageKey));
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
      const offset = raw == null ? 0 : Number(raw);
      return Number.isFinite(offset) && offset > 0 ? offset : 0;
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
