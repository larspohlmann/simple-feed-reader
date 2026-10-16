import { HttpInterceptorFn } from '@angular/common/http';
import { escapeJsonSlashes } from './json-slashes';

/** The web host's content filter drops a body holding a raw social-network URL but misses `\/` (#1638). */
export const escapeJsonSlashesInterceptor: HttpInterceptorFn = (request, next) => {
  if (request.body instanceof URLSearchParams) return next(request);
  if (request.detectContentTypeHeader() !== 'application/json') return next(request);

  return next(
    request.clone({
      body: escapeJsonSlashes(JSON.stringify(request.body)),
      setHeaders: request.headers.has('Content-Type') ? {} : { 'Content-Type': 'application/json' },
    }),
  );
};
