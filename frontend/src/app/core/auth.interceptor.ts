// src/app/core/auth.interceptor.ts
import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { API_BASE_URL } from './api';
import { ClientErrorReporter } from './client-error-reporter';
import { ReaderLocationService } from './reader-location.service';
import { TokenStore } from './token.store';

/** Attaches the bearer token to API requests and, on 401, clears the session
 *  and sends the user to login. The token is the whole auth story — no cookie. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const base = inject(API_BASE_URL);
  const tokens = inject(TokenStore);
  const router = inject(Router);
  const readerLocation = inject(ReaderLocationService);
  const reporter = inject(ClientErrorReporter);

  const isApi = req.url.startsWith(base ? base : '/') || req.url.startsWith('/api');
  const token = tokens.token();
  const authed =
    isApi && token ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : req;

  return next(authed).pipe(
    catchError((err) => {
      if (err.status === 401) {
        // Only a 401 for the live session's own token means the session
        // expired; a request that carried no token, or a stale one an explicit
        // logout already replaced, must not resurrect a return destination.
        const requestUsesCurrentToken = isApi && token !== null && token === tokens.token();
        tokens.clear();
        if (requestUsesCurrentToken) {
          readerLocation.rememberSavedReaderUrlForSignIn();
        }
        void router.navigate(['/login']);
        return throwError(() => err);
      }
      // Report real breakage, but never the report endpoint's own failure — that
      // would loop. 401 is handled above and is not breakage worth reporting.
      const isClientErrorEndpoint = req.url.includes('/api/client-errors');
      if (!isClientErrorEndpoint && (err.status === 0 || err.status >= 500)) {
        reporter.report(new Error(`HTTP ${err.status} ${req.method} ${req.url}`), 'HttpError');
      }
      return throwError(() => err);
    }),
  );
};
