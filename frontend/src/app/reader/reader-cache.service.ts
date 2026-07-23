import { Injectable } from '@angular/core';
import { ReaderArticle } from './models';

interface CacheRecord {
  entryId: number;
  article: ReaderArticle;
  cachedAt: number;
}

/**
 * Persistent, size-capped cache of extracted articles, keyed by entry id.
 * Only successful extractions are stored (failures should be retryable). Article
 * content is immutable per entry, so there is no staleness logic — the schema
 * version is the only cache-buster.
 */
@Injectable({ providedIn: 'root' })
export class ReaderCacheService {
  static readonly MAX_ENTRIES = 100;
  private static readonly DB = 'sfr-reader';
  private static readonly STORE = 'articles';
  // v2: ReaderArticle gained leadImage — bump to drop v1 records that lack it.
  private static readonly VERSION = 2;

  private db: Promise<IDBDatabase | null> | null = null;
  /** Strictly monotonic clock so puts within the same millisecond keep insertion order. */
  private lastCachedAt = 0;

  async get(entryId: number): Promise<ReaderArticle | null> {
    const db = await this.open();
    if (!db) return null;
    return new Promise((resolve) => {
      const tx = db.transaction(ReaderCacheService.STORE, 'readonly');
      const req = tx.objectStore(ReaderCacheService.STORE).get(entryId);
      req.onsuccess = () => resolve((req.result as CacheRecord | undefined)?.article ?? null);
      req.onerror = () => resolve(null);
    });
  }

  async put(entryId: number, article: ReaderArticle): Promise<void> {
    const db = await this.open();
    if (!db) return;
    this.lastCachedAt = Math.max(Date.now(), this.lastCachedAt + 1);
    const record: CacheRecord = { entryId, article, cachedAt: this.lastCachedAt };
    await new Promise<void>((resolve) => {
      const tx = db.transaction(ReaderCacheService.STORE, 'readwrite');
      tx.objectStore(ReaderCacheService.STORE).put(record);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
    await this.evict(db);
  }

  private async evict(db: IDBDatabase): Promise<void> {
    await new Promise<void>((resolve) => {
      const tx = db.transaction(ReaderCacheService.STORE, 'readwrite');
      const store = tx.objectStore(ReaderCacheService.STORE);
      const countReq = store.count();
      countReq.onsuccess = () => {
        const over = countReq.result - ReaderCacheService.MAX_ENTRIES;
        if (over <= 0) return;
        // Oldest-first via the cachedAt index; delete the surplus.
        let removed = 0;
        store.index('cachedAt').openCursor().onsuccess = (e) => {
          const cursor = (e.target as IDBRequest<IDBCursorWithValue | null>).result;
          if (!cursor || removed >= over) return;
          cursor.delete();
          removed++;
          cursor.continue();
        };
      };
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
  }

  private open(): Promise<IDBDatabase | null> {
    if (this.db) return this.db;
    this.db = new Promise((resolve) => {
      if (typeof indexedDB === 'undefined') return resolve(null);
      const req = indexedDB.open(ReaderCacheService.DB, ReaderCacheService.VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        // Bumping VERSION drops the old store — the schema-version cache-bust.
        if (db.objectStoreNames.contains(ReaderCacheService.STORE)) {
          db.deleteObjectStore(ReaderCacheService.STORE);
        }
        const store = db.createObjectStore(ReaderCacheService.STORE, { keyPath: 'entryId' });
        store.createIndex('cachedAt', 'cachedAt');
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
    });
    return this.db;
  }
}
