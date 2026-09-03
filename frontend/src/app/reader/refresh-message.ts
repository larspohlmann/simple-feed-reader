import { RefreshFailure } from './refresh.service';

/**
 * The translation key that explains a failed refresh to the user. Each cause
 * needs its own sentence -- wait for another refresh to finish, retry a sweep
 * that stopped with feeds due, or retry a failed request -- since one shared
 * message would leave the user guessing which happened (#119). A server
 * problem keeps the general wording rather than `problem.detail`, which is
 * backend English only while the banner is a translated surface.
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
