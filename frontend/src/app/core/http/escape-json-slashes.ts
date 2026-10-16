import { HttpInterceptorFn } from '@angular/common/http';

/** The web host's content filter drops a body holding a raw social-network URL but misses `\/` (#1638). */
export const escapeJsonSlashesInterceptor: HttpInterceptorFn = (request, next) => {
  if (request.detectContentTypeHeader() !== 'application/json') return next(request);

  return next(
    request.clone({
      body: JSON.stringify(request.body).replaceAll('/', '\\/'),
      setHeaders: request.headers.has('Content-Type') ? {} : { 'Content-Type': 'application/json' },
    }),
  );
};
