import { Injectable, inject } from '@angular/core';
import { Observable, from, of, switchMap, tap, timeout } from 'rxjs';
import { ReaderApi } from '../../reader-api';
import { LinkCardRef, linkParam } from '../../query/link-param';
import { ReaderCacheKey, ReaderCacheService } from './reader-cache.service';
import { ReaderContent } from '../../models';

/**
 * Cache-first reader content: an IndexedDB hit resolves immediately; a miss
 * calls the API and caches only successful extractions (failures stay
 * retryable). One method the reader view subscribes to on each open.
 */
@Injectable({ providedIn: 'root' })
export class ReaderContentService {
  /** A cache stuck behind a blocked schema upgrade must not hold the article back (#814). */
  private static readonly CACHE_WAIT_MS = 2_000;
  /** The client-side backstop for a stalled connection; the backend caps a fetch at ~20 s. */
  private static readonly LOAD_TIMEOUT_MS = 30_000;

  private readonly api = inject(ReaderApi);
  private readonly cache = inject(ReaderCacheService);

  load(entryId: number): Observable<ReaderContent> {
    return this.cacheFirst(entryId, () => this.api.readerContent(entryId));
  }

  loadLinkCard(ref: LinkCardRef): Observable<ReaderContent> {
    return this.cacheFirst(cardKey(ref), () => this.api.linkCardReaderContent(ref));
  }

  /** Drop this entry's cached copy, then fetch and re-cache a fresh one. */
  reload(entryId: number): Observable<ReaderContent> {
    return this.refetch(entryId, () => this.api.readerContent(entryId));
  }

  reloadLinkCard(ref: LinkCardRef): Observable<ReaderContent> {
    return this.refetch(cardKey(ref), () => this.api.linkCardReaderContent(ref));
  }

  private cacheFirst(
    key: ReaderCacheKey,
    request: () => Observable<ReaderContent>,
  ): Observable<ReaderContent> {
    return from(this.cache.get(key)).pipe(
      timeout({ first: ReaderContentService.CACHE_WAIT_MS, with: () => of(null) }),
      switchMap((cached) =>
        cached ? of<ReaderContent>(cached) : this.fetchAndCache(key, request),
      ),
    );
  }

  private refetch(
    key: ReaderCacheKey,
    request: () => Observable<ReaderContent>,
  ): Observable<ReaderContent> {
    return from(this.cache.delete(key)).pipe(switchMap(() => this.fetchAndCache(key, request)));
  }

  private fetchAndCache(
    key: ReaderCacheKey,
    request: () => Observable<ReaderContent>,
  ): Observable<ReaderContent> {
    return request().pipe(
      timeout({ first: ReaderContentService.LOAD_TIMEOUT_MS }),
      tap((content) => {
        if (content.status === 'ok') void this.cache.put(key, content);
      }),
    );
  }
}

function cardKey(ref: LinkCardRef): ReaderCacheKey {
  return `card:${linkParam(ref)}`;
}
