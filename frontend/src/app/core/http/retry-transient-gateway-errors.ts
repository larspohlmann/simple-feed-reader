import {
  HttpContext,
  HttpContextToken,
  HttpErrorResponse,
  HttpInterceptorFn,
} from '@angular/common/http';
import { MonoTypeOperatorFunction, retry, throwError, timer } from 'rxjs';

const TRANSIENT_GATEWAY_STATUSES = new Set([502, 503, 504]);
const RETRY_DELAYS_MS = [2000, 5000, 10000];

const RETRIES_TRANSIENT_GATEWAY_ERRORS = new HttpContextToken<boolean>(() => false);

/** Opts a read into the gateway retry: a busy backend right after a restart answers it seconds later (#1419). */
export function retryingTransientGatewayErrors(): HttpContext {
  return new HttpContext().set(RETRIES_TRANSIENT_GATEWAY_ERRORS, true);
}

/** Sits inside authInterceptor, so only the error that outlasts every retry reaches its client-error report. */
export const transientGatewayRetryInterceptor: HttpInterceptorFn = (request, next) =>
  request.context.get(RETRIES_TRANSIENT_GATEWAY_ERRORS)
    ? next(request).pipe(retryTransientGatewayErrors())
    : next(request);

export function retryTransientGatewayErrors<T>(): MonoTypeOperatorFunction<T> {
  return retry({
    count: RETRY_DELAYS_MS.length,
    delay: (error: unknown, retryCount: number) =>
      isTransientGatewayError(error)
        ? timer(RETRY_DELAYS_MS[retryCount - 1])
        : throwError(() => error),
  });
}

function isTransientGatewayError(error: unknown): boolean {
  return error instanceof HttpErrorResponse && TRANSIENT_GATEWAY_STATUSES.has(error.status);
}
