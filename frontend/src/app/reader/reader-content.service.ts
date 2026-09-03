import { Injectable, inject } from '@angular/core';
import { Observable, from, of, switchMap, tap, timeout } from 'rxjs';
import { ReaderApi } from './reader-api';
import { ReaderCacheService } from './reader-cache.service';
import { ReaderContent } from './models';

/**
 * Cache-first reader content: an IndexedDB hit resolves immediately; a miss
 * calls the API and caches only successful extractions (failures stay
 * retryable). One method the reader view subscribes to on each open.
 */
@Injectable({ providedIn: 'root' })
export class ReaderContentService {
  /** A cache stuck behind a blocked schema upgrade must not hold the article back (#814). */
  private static readonly CACHE_WAIT_MS = 2_000;

  private readonly api = inject(ReaderApi);
  private readonly cache = inject(ReaderCacheService);

  load(entryId: number): Observable<ReaderContent> {
    return from(this.cache.get(entryId)).pipe(
      timeout({ first: ReaderContentService.CACHE_WAIT_MS, with: () => of(null) }),
      switchMap((cached) => (cached ? of<ReaderContent>(cached) : this.fetchAndCache(entryId))),
    );
  }

  /** Drop this entry's cached copy, then fetch and re-cache a fresh one. */
  reload(entryId: number): Observable<ReaderContent> {
    return from(this.cache.delete(entryId)).pipe(switchMap(() => this.fetchAndCache(entryId)));
  }

  private fetchAndCache(entryId: number): Observable<ReaderContent> {
    return this.api.readerContent(entryId).pipe(
      tap((c) => {
        if (c.status === 'ok') void this.cache.put(entryId, c);
      }),
    );
  }
}
