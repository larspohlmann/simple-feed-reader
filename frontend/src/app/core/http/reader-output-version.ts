import { HttpInterceptorFn, HttpResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { tap } from 'rxjs';

export const READER_VERSION_HEADER = 'Reader-Version';

/** The backend's current reader-output version; a cached article from any other version is stale (#1561). */
@Injectable({ providedIn: 'root' })
export class ReaderOutputVersion {
  /** Null until the first API response names it. */
  readonly current = signal<string | null>(null);
}

/** Every API response names the version, so the client knows it before it opens a cached article. */
export const readerOutputVersionInterceptor: HttpInterceptorFn = (request, next) => {
  const version = inject(ReaderOutputVersion);
  return next(request).pipe(
    tap((event) => {
      const named = event instanceof HttpResponse ? event.headers.get(READER_VERSION_HEADER) : null;
      if (named) version.current.set(named);
    }),
  );
};
