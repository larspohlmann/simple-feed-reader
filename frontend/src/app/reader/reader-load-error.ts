import { HttpErrorResponse } from '@angular/common/http';

/**
 * The complete, diagnostic detail behind the reader's "show error" disclosure —
 * raw on purpose, so it reports what actually failed rather than a friendly gloss.
 * A transport failure carries the whole HTTP message (status, status text, URL, and
 * any problem+json detail); a timeout or other error falls back to its own message.
 */
export function describeLoadError(error: unknown): string {
  if (error instanceof HttpErrorResponse) return describeHttpError(error);
  if (error instanceof Error) return error.message || error.name;
  return String(error ?? 'Unknown error');
}

function describeHttpError(error: HttpErrorResponse): string {
  const detail = readProblemDetail(error.error);
  return detail === null ? error.message : `${error.message}\n${detail}`;
}

function readProblemDetail(body: unknown): string | null {
  if (typeof body === 'string') return body.trim() || null;
  if (body !== null && typeof body === 'object') {
    const problem = body as { detail?: unknown; title?: unknown };
    const text = problem.detail ?? problem.title;
    return typeof text === 'string' ? text : null;
  }
  return null;
}
