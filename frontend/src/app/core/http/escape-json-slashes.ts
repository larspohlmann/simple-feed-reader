import { HttpInterceptorFn } from '@angular/common/http';
import { stringifyWithEscapedSlashes } from './json-slashes';

/** The web host's content filter drops a body holding a raw social-network URL but misses `\/` (#1638). */
export const escapeJsonSlashesInterceptor: HttpInterceptorFn = (request, next) => {
  if (request.body instanceof URLSearchParams) return next(request);
  if (request.detectContentTypeHeader() !== 'application/json') return next(request);

  return next(
    request.clone({
      body: stringifyWithEscapedSlashes(request.body),
      setHeaders: { 'Content-Type': request.headers.get('Content-Type') ?? 'application/json' },
    }),
  );
};
