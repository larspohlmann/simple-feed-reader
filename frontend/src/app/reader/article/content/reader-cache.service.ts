import { Injectable } from '@angular/core';
import { ReaderArticle } from '../../models';

interface CacheRecord {
  entryId: number;
  article: ReaderArticle;
  cachedAt: number;
}

/**
 * Persistent, size-capped cache of extracted articles, keyed by entry id. Only
 * successful extractions are stored (failures should be retryable). Content is
 * immutable per entry, so there's no staleness logic — VERSION is the only cache-buster.
 */
@Injectable({ providedIn: 'root' })
export class ReaderCacheService {
  static readonly MAX_ENTRIES = 100;
  private static readonly DB = 'sfr-reader';
  private static readonly STORE = 'articles';
  private static readonly VERSION = 27;

  private db: Promise<IDBDatabase | null> | null = null;
  /** Strictly monotonic clock so puts within the same millisecond keep insertion order. */
  private lastCachedAt = 0;

  async get(entryId: number): Promise<ReaderArticle | null> {
    const database = await this.open();
    if (!database) return null;
    return new Promise((resolve) => {
      const tx = database.transaction(ReaderCacheService.STORE, 'readonly');
      const request = tx.objectStore(ReaderCacheService.STORE).get(entryId);
      request.onsuccess = () =>
        resolve((request.result as CacheRecord | undefined)?.article ?? null);
      request.onerror = () => resolve(null);
    });
  }

  async put(entryId: number, article: ReaderArticle): Promise<void> {
    const database = await this.open();
    if (!database) return;
    this.lastCachedAt = Math.max(Date.now(), this.lastCachedAt + 1);
    const record: CacheRecord = { entryId, article, cachedAt: this.lastCachedAt };
    await new Promise<void>((resolve) => {
      const tx = database.transaction(ReaderCacheService.STORE, 'readwrite');
      tx.objectStore(ReaderCacheService.STORE).put(record);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
    await this.evict(database);
  }

  async delete(entryId: number): Promise<void> {
    const database = await this.open();
    if (!database) return;
    await new Promise<void>((resolve) => {
      const tx = database.transaction(ReaderCacheService.STORE, 'readwrite');
      tx.objectStore(ReaderCacheService.STORE).delete(entryId);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
  }

  private async evict(database: IDBDatabase): Promise<void> {
    await new Promise<void>((resolve) => {
      const tx = database.transaction(ReaderCacheService.STORE, 'readwrite');
      const store = tx.objectStore(ReaderCacheService.STORE);
      const countRequest = store.count();
      countRequest.onsuccess = () => {
        const over = countRequest.result - ReaderCacheService.MAX_ENTRIES;
        if (over <= 0) return;
        // Oldest-first via the cachedAt index; delete the surplus.
        let removed = 0;
        store.index('cachedAt').openCursor().onsuccess = (event) => {
          const cursor = (event.target as IDBRequest<IDBCursorWithValue | null>).result;
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
      const request = indexedDB.open(ReaderCacheService.DB, ReaderCacheService.VERSION);
      request.onupgradeneeded = () => {
        const database = request.result;
        // Bumping VERSION drops the old store — the schema-version cache-bust.
        if (database.objectStoreNames.contains(ReaderCacheService.STORE)) {
          database.deleteObjectStore(ReaderCacheService.STORE);
        }
        const store = database.createObjectStore(ReaderCacheService.STORE, { keyPath: 'entryId' });
        store.createIndex('cachedAt', 'cachedAt');
      };
      // A tab from before the bump holds the old schema and never closes: read
      // without the cache rather than hang every article on it (#814). If it
      // closes later, onsuccess still fires and restores the cache below.
      request.onblocked = () => resolve(null);
      request.onsuccess = () => {
        const database = request.result;
        // A newer tab wants to upgrade: let go, or that tab hangs on us (#814).
        database.onversionchange = () => {
          database.close();
          this.db = null;
        };
        this.db = Promise.resolve(database);
        resolve(database);
      };
      request.onerror = () => resolve(null);
    });
    return this.db;
  }
}
