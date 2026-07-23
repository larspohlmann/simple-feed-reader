import { Injectable, inject } from '@angular/core';
import { Observable, from, of, switchMap, tap } from 'rxjs';
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
  private readonly api = inject(ReaderApi);
  private readonly cache = inject(ReaderCacheService);

  load(entryId: number): Observable<ReaderContent> {
    return from(this.cache.get(entryId)).pipe(
      switchMap((cached) =>
        cached
          ? of<ReaderContent>(cached)
          : this.api.readerContent(entryId).pipe(
              tap((c) => {
                if (c.status === 'ok') void this.cache.put(entryId, c);
              }),
            ),
      ),
    );
  }
}
