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
  // v5: v4 records carry a `readerHero` field and a contentHtml with no lead
  // picture in it (#681); an already-read article would come back missing its
  // lead until refetched.
  // v6: v5 records hold image URLs that a comma-splitting srcset reader cut
  // short (#706); an already-read article would keep its broken pictures.
  // v7: v6 records hold reader extractions with Substack player chrome and
  // un-stripped share buttons (#627); an already-read article would keep them.
  // v8: v7 records were extracted before media recovery (#748); an
  // already-read article would keep its dropped embeds as plain links.
  // v9: v8 records were extracted before recovered media was reconciled into
  // the body in place (#755); an already-read article would keep a video's
  // poster duplicated as a header image and its players stranded at the top.
  // v10: v9 records hold trailing teaser carousels (#779).
  // v11: v10 records carry no `paywalled` flag (#785); an already-read
  // preview would never show the paywall note.
  // v12: v11 records were extracted while a declared embed hid the page's
  // other embeds (#788); an already-read article would keep one player where
  // the page has several.
  // v13: v12 records lost every photo held in a lazy <picture>, a custom
  // element or a media-classed wrapper (#789); an already-read gallery would
  // keep its empty figures.
  // v14: v13 records hold no player for a page whose only playable form is
  // an HLS playlist or a Brightcove player (#782).
  // v15: v14 records hold an HLS stream at the URL the page declared, which a
  // cross-origin fetch cannot follow through its redirect, and two players for
  // a schema.org node that names both its file and its player page (#782).
  // v16: v15 records hold no player for a page that declares its YouTube video
  // only as an id in a data attribute (#795).
  // v17: v16 records hold only the audio of a broadcast page whose video had
  // no og:image poster (#796).
  // v18: v17 records hold one player for a page whose other videos are named
  // only by a sibling id in a script payload (#800).
  private static readonly VERSION = 19;

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

  async delete(entryId: number): Promise<void> {
    const db = await this.open();
    if (!db) return;
    await new Promise<void>((resolve) => {
      const tx = db.transaction(ReaderCacheService.STORE, 'readwrite');
      tx.objectStore(ReaderCacheService.STORE).delete(entryId);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
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
