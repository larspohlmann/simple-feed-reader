import * as v8 from 'node:v8';

// jsdom's global scope lacks structuredClone, which fake-indexeddb needs to
// store records. Node's v8 (de)serialize pair is the canonical polyfill.
globalThis.structuredClone ??= <T>(value: T): T => v8.deserialize(v8.serialize(value)) as T;

import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { ReaderCacheService } from './reader-cache.service';
import { ReaderArticle } from './models';

function article(url: string): ReaderArticle {
  return {
    status: 'ok',
    url,
    title: 'T',
    byline: null,
    siteName: null,
    contentHtml: '<p>body</p>',
    excerpt: null,
    paywalled: false,
    originalHero: null,
    extractedAt: '2026-07-23T00:00:00Z',
  };
}

/** A reader tab from before a schema bump, holding the database at an older version. */
function staleTabConnection(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open('sfr-reader', 1);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function within<T>(ms: number, promise: Promise<T>): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const expired = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new Error(`still pending after ${ms} ms`)), ms);
  });
  return Promise.race([promise, expired]).finally(() => clearTimeout(timer));
}

describe('ReaderCacheService', () => {
  let cache: ReaderCacheService;

  beforeEach(async () => {
    // Fresh DB per test.
    (globalThis as unknown as { indexedDB: IDBFactory }).indexedDB = new IDBFactory();
    cache = new ReaderCacheService();
  });

  it('answers a miss when a stale tab blocks the schema upgrade, instead of waiting for it', async () => {
    const staleTab = await staleTabConnection();
    staleTab.onversionchange = () => undefined; // the old code never closes on versionchange
    await expect(within(500, cache.get(1))).resolves.toBeNull();
    staleTab.close();
  });

  it('closes its own connection when a newer schema asks, so the newer tab can upgrade', async () => {
    await cache.get(1);
    const newerTabUpgrade = new Promise<number>((resolve, reject) => {
      const req = indexedDB.open('sfr-reader', 1_000);
      req.onblocked = () => reject(new Error('blocked by the cache connection'));
      req.onsuccess = () => {
        resolve(req.result.version);
        req.result.close();
      };
      req.onerror = () => reject(req.error);
    });
    await expect(within(500, newerTabUpgrade)).resolves.toBe(1_000);
  });

  it('returns null on a miss and the article on a hit', async () => {
    expect(await cache.get(1)).toBeNull();
    await cache.put(1, article('https://x/1'));
    expect((await cache.get(1))?.url).toBe('https://x/1');
  });

  it('evicts the oldest entry past the LRU cap', async () => {
    for (let i = 1; i <= ReaderCacheService.MAX_ENTRIES + 1; i++) {
      await cache.put(i, article('https://x/' + i));
    }
    // The very first inserted entry was evicted; the newest remains.
    expect(await cache.get(1)).toBeNull();
    expect(await cache.get(ReaderCacheService.MAX_ENTRIES + 1)).not.toBeNull();
  });

  it('deletes a cached entry, leaving a later get a miss', async () => {
    await cache.put(1, article('https://x/1'));
    expect(await cache.get(1)).not.toBeNull();
    await cache.delete(1);
    expect(await cache.get(1)).toBeNull();
  });

  it('treats deleting an absent entry as a no-op', async () => {
    await expect(cache.delete(999)).resolves.toBeUndefined();
  });
});
