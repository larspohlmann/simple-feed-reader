import { HttpErrorResponse } from '@angular/common/http';
import { Problem, parseProblem } from '../core/problem';

/** Marks a `Problem` a client-side check built, one that never reached the
 *  server -- an old-format file, a rejected zip, a parse/verify failure.
 *  `detail` holds the i18n key to translate rather than server-sent text. */
export const CLIENT_CHECK_FAILED = 'client_backup_check_failed';

export function clientCheckFailed(detailKey: string): Problem {
  return { type: CLIENT_CHECK_FAILED, title: 'Backup check failed', status: 0, detail: detailKey };
}

/** Maps a restore failure to a `Problem`: a real HTTP failure keeps the
 *  backend's problem+json, while anything else -- a corrupt/non-gzip archive,
 *  a parse or verify error thrown while opening or reading a part -- becomes
 *  the invalid-archive client message instead of a fake "something went wrong". */
export function restoreErrorProblem(error: unknown): Problem {
  if (error instanceof HttpErrorResponse) return parseProblem(error);
  return clientCheckFailed('settings.backup.invalidArchive');
}
