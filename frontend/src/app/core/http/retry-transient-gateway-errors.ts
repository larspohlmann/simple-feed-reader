import { HttpErrorResponse } from '@angular/common/http';
import { MonoTypeOperatorFunction, retry, throwError, timer } from 'rxjs';

const TRANSIENT_GATEWAY_STATUSES = new Set([502, 503, 504]);
const RETRY_DELAYS_MS = [2000, 5000, 10000];

/** Retries a read the gateway gave up on — a busy backend right after a restart (#1419) answers it a few
 *  seconds later. Every other failure is not transient, and retrying it would only delay the message. */
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
