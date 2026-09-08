import { HttpErrorResponse } from '@angular/common/http';
import { parseProblem } from '../core/problem';

/**
 * The complete, diagnostic detail behind the reader's "show error" disclosure —
 * raw on purpose, so it reports what actually failed rather than a friendly gloss.
 * A transport failure keeps the whole HTTP message (status, status text, URL) and
 * appends the server's problem+json detail when it sent one; a timeout or other
 * error falls back to its own message.
 */
export function describeLoadError(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const detail = parseProblem(error).detail;
    return detail ? `${error.message}\n${detail}` : error.message;
  }
  if (error instanceof Error) return error.message || error.name;
  return String(error ?? 'Unknown error');
}
