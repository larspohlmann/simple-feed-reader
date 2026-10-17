import { Injectable, inject } from '@angular/core';
import { Observable, from, of, switchMap, tap, timeout } from 'rxjs';
import { ReaderApi } from '../../reader-api';
import { ReaderCacheService, ReaderTarget, readerCacheKey } from './reader-cache.service';
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

  load(target: ReaderTarget): Observable<ReaderContent> {
    return from(this.cache.get(readerCacheKey(target))).pipe(
      timeout({ first: ReaderContentService.CACHE_WAIT_MS, with: () => of(null) }),
      switchMap((cached) => (cached ? of<ReaderContent>(cached) : this.fetchAndCache(target))),
    );
  }

  /** Drop the target's cached copy, then fetch and re-cache a fresh one. */
  reload(target: ReaderTarget): Observable<ReaderContent> {
    return from(this.cache.delete(readerCacheKey(target))).pipe(
      switchMap(() => this.fetchAndCache(target)),
    );
  }

  private fetchAndCache(target: ReaderTarget): Observable<ReaderContent> {
    return this.fetch(target).pipe(
      timeout({ first: ReaderContentService.LOAD_TIMEOUT_MS }),
      tap((content) => {
        if (content.status === 'ok') void this.cache.put(readerCacheKey(target), content);
      }),
    );
  }

  private fetch(target: ReaderTarget): Observable<ReaderContent> {
    return typeof target === 'number'
      ? this.api.readerContent(target)
      : this.api.linkCardReaderContent(target);
  }
}
