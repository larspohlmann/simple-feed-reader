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
    extractedAt: '2026-07-23T00:00:00Z',
  };
}

describe('ReaderCacheService', () => {
  let cache: ReaderCacheService;

  beforeEach(async () => {
    (globalThis as unknown as { indexedDB: IDBFactory }).indexedDB = new IDBFactory(); // fresh DB per test
    cache = new ReaderCacheService();
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
});
