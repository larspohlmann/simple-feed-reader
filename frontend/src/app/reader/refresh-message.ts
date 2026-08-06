// src/app/reader/refresh-message.ts
import { RefreshFailure } from './refresh.service';

/**
 * The translation key that explains a failed refresh to the user.
 *
 * Each cause needs its own sentence, because each one asks for something
 * different: wait for the other refresh to let go, retry a sweep that stopped
 * with feeds still due, or retry a request that failed. One shared message
 * would leave the user guessing which of the three happened (#119).
 *
 * A server problem keeps the general wording rather than surfacing
 * `problem.detail`: that text comes from the backend in English only, and the
 * banner is a translated surface.
 */
export function refreshFailureKey(failure: RefreshFailure): string {
  switch (failure.kind) {
    case 'busy':
      return 'reader.refreshBusy';
    case 'aborted':
      return 'reader.refreshAborted';
    case 'stalled':
      return 'reader.refreshStalled';
    case 'http':
      return 'reader.fetchFailed';
  }
}
